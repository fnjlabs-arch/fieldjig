<?php

namespace App\Core\Billing\Services;

use App\Core\Billing\Models\BillingWebhookEvent;
use App\Core\Tenancy\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Applies verified Paddle webhook deliveries to organizations.
 * Idempotent: the delivery's event id is recorded in
 * billing_webhook_events and an exact replay is skipped.
 *
 * Paddle sends a per-delivery `event_id`, so the event id is
 * "paddle:" + event_id. Events are correlated to an org through
 * data.custom_data.organization_id (set at checkout creation);
 * failing that, through data.customer_id matching a stored
 * billing_customer_id, then through data.subscription_id matching a
 * stored billing_subscription_id.
 *
 * Subscription status mapping (Paddle → SadSha Build):
 *   active    → active / paid plan
 *   trialing  → trialing / trial plan (defensive: the Paddle price
 *               carries no trial — the 30-day trial lives in the app)
 *   past_due  → past_due (7-day grace, see billing.md)
 *   paused    → past_due (the app has no paused state; a resume flips
 *               the org back to active)
 *   canceled  → canceled
 */
class PaddleWebhookHandler
{
    /**
     * @param  array<string, mixed>  $payload  The decoded JSON body.
     * @return array<int, string> The event names applied (empty on replay or when no org matches).
     */
    public function handle(string $eventName, string $rawPayload, array $payload): array
    {
        /** @var mixed $paddleEventId */
        $paddleEventId = $payload['event_id'] ?? null;
        $eventId = 'paddle:'.(is_string($paddleEventId) && $paddleEventId !== '' ? $paddleEventId : hash('sha256', $rawPayload));

        try {
            $record = BillingWebhookEvent::query()->firstOrCreate([
                'provider' => 'paddle',
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
            Log::warning('Paddle webhook could not be matched to an organization.', [
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
        /** @var array<string, mixed> $data */
        $data = $payload['data'] ?? [];

        /** @var mixed $customOrganizationId */
        $customOrganizationId = $data['custom_data']['organization_id'] ?? null;

        if (is_string($customOrganizationId) && $customOrganizationId !== '') {
            $organization = Organization::query()->find($customOrganizationId);

            if ($organization !== null) {
                return $organization;
            }
        }

        /** @var mixed $customerId */
        $customerId = $data['customer_id'] ?? null;

        if (is_string($customerId) && $customerId !== '') {
            $organization = Organization::query()
                ->where('billing_customer_id', $customerId)
                ->first();

            if ($organization !== null) {
                return $organization;
            }
        }

        /** @var mixed $subscriptionId */
        $subscriptionId = $data['subscription_id'] ?? null;

        if (is_string($subscriptionId) && $subscriptionId !== '') {
            return Organization::query()
                ->where('billing_subscription_id', $subscriptionId)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyEvent(Organization $organization, string $eventName, array $payload): void
    {
        /** @var array<string, mixed> $data */
        $data = $payload['data'] ?? [];
        /** @var mixed $resourceId */
        $resourceId = $data['id'] ?? null;
        $isSubscription = str_starts_with($eventName, 'subscription.');

        if ($eventName === 'subscription.created' || $eventName === 'subscription.updated' || $eventName === 'subscription.resumed' || $eventName === 'subscription.trialing') {
            $this->applySubscription($organization, $data);
        } elseif ($eventName === 'subscription.canceled') {
            $organization->update([
                'status' => Organization::STATUS_CANCELED,
                'billing_provider' => 'paddle',
                'past_due_at' => null,
            ]);
        } elseif ($eventName === 'subscription.past_due' || $eventName === 'subscription.paused') {
            $organization->update([
                'status' => Organization::STATUS_PAST_DUE,
                'billing_provider' => 'paddle',
                'past_due_at' => $organization->past_due_at ?? now(),
            ]);
        } elseif ($eventName === 'transaction.completed' || $eventName === 'transaction.paid') {
            $this->applyTransaction($organization, $data);
        } elseif ($eventName === 'transaction.payment_failed' || $eventName === 'transaction.past_due') {
            $organization->update([
                'status' => Organization::STATUS_PAST_DUE,
                'billing_provider' => 'paddle',
                'past_due_at' => $organization->past_due_at ?? now(),
            ]);
        }

        if ($isSubscription && is_string($resourceId) && $resourceId !== '') {
            $organization->update(['billing_subscription_id' => $resourceId]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applySubscription(Organization $organization, array $attributes): void
    {
        /** @var mixed $status */
        $status = $attributes['status'] ?? null;
        /** @var mixed $customerId */
        $customerId = $attributes['customer_id'] ?? null;

        $updates = [
            'billing_provider' => 'paddle',
        ];

        if (is_string($customerId) && $customerId !== '') {
            $updates['billing_customer_id'] = $customerId;
        }

        match ($status) {
            'trialing' => $updates += [
                'plan' => Organization::PLAN_TRIAL,
                'status' => Organization::STATUS_TRIALING,
            ],
            'active' => $updates += [
                'plan' => Organization::PLAN_PAID,
                'status' => Organization::STATUS_ACTIVE,
                'past_due_at' => null,
            ],
            'past_due', 'paused' => $updates += [
                'status' => Organization::STATUS_PAST_DUE,
                'past_due_at' => $organization->past_due_at ?? now(),
            ],
            'canceled' => $updates += [
                'status' => Organization::STATUS_CANCELED,
                'past_due_at' => null,
            ],
            default => null,
        };

        $organization->update($updates);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyTransaction(Organization $organization, array $data): void
    {
        /** @var mixed $customerId */
        $customerId = $data['customer_id'] ?? null;
        /** @var mixed $subscriptionId */
        $subscriptionId = $data['subscription_id'] ?? null;

        $updates = [
            'billing_provider' => 'paddle',
        ];

        if (is_string($customerId) && $customerId !== '') {
            $updates['billing_customer_id'] = $customerId;
        }

        // A completed/paid transaction for a subscription confirms the
        // payment — the subscription events carry the state, but a
        // successful renewal also cures a past_due org.
        if (is_string($subscriptionId) && $subscriptionId !== '') {
            $updates['billing_subscription_id'] = $subscriptionId;
            $updates['plan'] = Organization::PLAN_PAID;
            $updates['status'] = Organization::STATUS_ACTIVE;
            $updates['past_due_at'] = null;
        }

        $organization->update($updates);
    }
}
