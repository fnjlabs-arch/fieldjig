<?php

namespace App\Core\Billing\Services;

use App\Core\Billing\Models\BillingWebhookEvent;
use App\Core\Tenancy\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Applies verified Lemon Squeezy webhook deliveries to organizations
 * (§2). Idempotent: the delivery's event id is recorded in
 * billing_webhook_events and an exact replay is skipped.
 *
 * Lemon Squeezy sends no per-delivery id, so the event id is
 * "lemonsqueezy:" + sha256(raw body). Events are correlated to an org
 * through meta.custom_data.organization_id (set at checkout creation);
 * failing that, through data.attributes.customer_id matching a stored
 * billing_customer_id.
 *
 * Subscription status mapping (Lemon → SadSha Build):
 *   on_trial  → trialing / trial plan
 *   active    → active / paid plan
 *   past_due, unpaid → past_due (7-day grace, see billing.md)
 *   cancelled, expired → canceled
 */
class LemonSqueezyWebhookHandler
{
    /**
     * @param  array<string, mixed>  $payload  The decoded JSON body.
     * @return array<int, string> The event names applied (empty on replay or when no org matches).
     */
    public function handle(string $eventName, string $rawPayload, array $payload): array
    {
        $eventId = 'lemonsqueezy:'.hash('sha256', $rawPayload);

        try {
            $record = BillingWebhookEvent::query()->firstOrCreate([
                'provider' => 'lemonsqueezy',
                'event_id' => $eventId,
            ]);
        } catch (QueryException) {
            // A concurrent identical delivery won the unique-index race.
            return [];
        }

        if ($record->processed_at !== null) {
            return [];
        }

        $organization = $this->resolveOrganization($payload);

        if ($organization === null) {
            Log::warning('Lemon Squeezy webhook could not be matched to an organization.', [
                'event' => $eventName,
            ]);

            $record->update(['processed_at' => now()]);

            return [];
        }

        $this->applyEvent($organization, $eventName, $payload);

        $record->update(['processed_at' => now()]);

        return [$eventName];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrganization(array $payload): ?Organization
    {
        /** @var mixed $customOrganizationId */
        $customOrganizationId = $payload['meta']['custom_data']['organization_id'] ?? null;

        if (is_string($customOrganizationId) && $customOrganizationId !== '') {
            $organization = Organization::query()->find($customOrganizationId);

            if ($organization !== null) {
                return $organization;
            }
        }

        /** @var mixed $customerId */
        $customerId = $payload['data']['attributes']['customer_id'] ?? null;

        if (is_int($customerId) || is_string($customerId)) {
            return Organization::query()
                ->where('billing_customer_id', (string) $customerId)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyEvent(Organization $organization, string $eventName, array $payload): void
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $payload['data']['attributes'] ?? [];
        /** @var mixed $resourceId */
        $resourceId = $payload['data']['id'] ?? null;
        $isSubscription = ($payload['data']['type'] ?? null) === 'subscriptions';

        if ($eventName === 'subscription_created' || $eventName === 'subscription_updated') {
            $this->applySubscription($organization, $attributes, $resourceId);
        } elseif ($eventName === 'subscription_cancelled' || $eventName === 'subscription_expired') {
            $organization->update([
                'status' => Organization::STATUS_CANCELED,
                'billing_provider' => 'lemonsqueezy',
                'past_due_at' => null,
            ]);
        } elseif ($eventName === 'order_created') {
            $this->applyOrder($organization, $attributes);
        }

        if ($isSubscription && is_string($resourceId) && $resourceId !== '') {
            $organization->update(['billing_subscription_id' => $resourceId]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applySubscription(Organization $organization, array $attributes, mixed $resourceId): void
    {
        /** @var mixed $status */
        $status = $attributes['status'] ?? null;
        /** @var mixed $customerId */
        $customerId = $attributes['customer_id'] ?? null;

        $updates = [
            'billing_provider' => 'lemonsqueezy',
        ];

        if (is_int($customerId) || is_string($customerId)) {
            $updates['billing_customer_id'] = (string) $customerId;
        }

        match ($status) {
            'on_trial' => $updates += [
                'plan' => Organization::PLAN_TRIAL,
                'status' => Organization::STATUS_TRIALING,
            ],
            'active' => $updates += [
                'plan' => Organization::PLAN_PAID,
                'status' => Organization::STATUS_ACTIVE,
                'past_due_at' => null,
            ],
            'past_due', 'unpaid' => $updates += [
                'status' => Organization::STATUS_PAST_DUE,
                'past_due_at' => $organization->past_due_at ?? now(),
            ],
            'cancelled', 'expired' => $updates += [
                'status' => Organization::STATUS_CANCELED,
                'past_due_at' => null,
            ],
            default => null,
        };

        $organization->update($updates);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyOrder(Organization $organization, array $attributes): void
    {
        // An order on its own does not change plan/status — the
        // subscription events carry the state. Record the customer id
        // so later subscription events can be matched by it.
        /** @var mixed $customerId */
        $customerId = $attributes['customer_id'] ?? null;

        if ((is_int($customerId) || is_string($customerId)) && $organization->billing_customer_id === null) {
            $organization->update([
                'billing_customer_id' => (string) $customerId,
                'billing_provider' => 'lemonsqueezy',
            ]);
        }
    }
}
