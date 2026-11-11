<?php

namespace App\Core\Billing\Providers;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\Exceptions\BillingNotConfiguredException;
use App\Core\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Core\Billing\Services\LemonSqueezyWebhookHandler;
use App\Core\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * Lemon Squeezy billing provider (§2) — the first, temporary gateway.
 *
 * Docs (verified 2026-11-11):
 * - Webhooks: signature in the `X-Signature` header, HMAC-SHA256 hex
 *   digest of the RAW request body keyed with the webhook signing
 *   secret; event name in `X-Event-Name`
 *   (docs.lemonsqueezy.com/help/webhooks/signing-requests).
 * - Checkouts: POST https://api.lemonsqueezy.com/v1/checkouts
 *   (JSON:API), relationships store + variant, custom data under
 *   checkout_data.custom; the checkout URL is data.attributes.url.
 *   Custom data is returned in webhook meta.custom_data.
 * - Cancel: DELETE https://api.lemonsqueezy.com/v1/subscriptions/{id}.
 *
 * Lemon Squeezy has no customer self-serve billing portal, so
 * portalUrl() returns null.
 *
 * No live API calls happen in tests: checkout/cancel go through
 * Laravel's HTTP client (Http::fake in tests) and webhooks are
 * verified against known HMAC test vectors.
 */
class LemonSqueezyProvider implements BillingProvider
{
    private const API_BASE_URL = 'https://api.lemonsqueezy.com/v1';

    public function __construct(private readonly LemonSqueezyWebhookHandler $webhookHandler) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->storeId() !== '' && $this->variantPro() !== '';
    }

    public function createCheckout(Organization $organization, string $plan): string
    {
        $this->ensureConfigured();

        $ownerEmail = $organization->users()
            ->orderBy('organization_members.created_at')
            ->value('users.email');

        $response = Http::withToken($this->apiKey())
            ->withHeaders([
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ])
            ->post(self::API_BASE_URL.'/checkouts', [
                'data' => [
                    'type' => 'checkouts',
                    'attributes' => [
                        'checkout_data' => [
                            'email' => is_string($ownerEmail) ? $ownerEmail : null,
                            'custom' => ['organization_id' => $organization->id],
                        ],
                        'product_options' => [
                            'redirect_url' => route('billing.panel'),
                        ],
                    ],
                    'relationships' => [
                        'store' => ['data' => ['type' => 'stores', 'id' => $this->storeId()]],
                        'variant' => ['data' => ['type' => 'variants', 'id' => $this->variantPro()]],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Lemon Squeezy checkout creation failed with HTTP '.$response->status().'.');
        }

        $url = $response->json('data.attributes.url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Lemon Squeezy checkout creation returned no URL.');
        }

        return $url;
    }

    /**
     * @return array<int, string>
     */
    public function handleWebhook(string $rawPayload, ?string $signature, ?string $eventName = null): array
    {
        $secret = $this->webhookSecret();

        if ($secret === '') {
            throw new InvalidWebhookSignatureException('Lemon Squeezy webhook secret is not configured.');
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);

        if ($signature === null || ! hash_equals($expected, strtolower(trim($signature)))) {
            throw new InvalidWebhookSignatureException('Lemon Squeezy webhook signature did not verify.');
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidWebhookSignatureException('Lemon Squeezy webhook body was not valid JSON.', 0, $exception);
        }

        if (! is_array($payload)) {
            throw new InvalidWebhookSignatureException('Lemon Squeezy webhook body was not a JSON object.');
        }

        /** @var mixed $metaEventName */
        $metaEventName = $payload['meta']['event_name'] ?? null;
        $resolvedEventName = $eventName ?? (is_string($metaEventName) ? $metaEventName : 'unknown');

        return $this->webhookHandler->handle($resolvedEventName, $rawPayload, $payload);
    }

    public function portalUrl(Organization $organization): ?string
    {
        // Lemon Squeezy offers no customer self-serve billing portal.
        return null;
    }

    public function cancelSubscription(Organization $organization): void
    {
        $subscriptionId = $organization->billing_subscription_id;

        if ($subscriptionId === null) {
            return;
        }

        $this->ensureConfigured();

        $response = Http::withToken($this->apiKey())
            ->withHeaders(['Accept' => 'application/vnd.api+json'])
            ->delete(self::API_BASE_URL.'/subscriptions/'.$subscriptionId);

        if ($response->failed()) {
            throw new RuntimeException('Lemon Squeezy subscription cancellation failed with HTTP '.$response->status().'.');
        }

        // The subscription_cancelled webhook confirms this; update the
        // local state now so the panel reflects the cancellation even
        // if the webhook is delayed.
        $organization->update([
            'status' => Organization::STATUS_CANCELED,
            'past_due_at' => null,
        ]);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new BillingNotConfiguredException(
                'Lemon Squeezy is not configured: set LEMONSQUEEZY_API_KEY, LEMONSQUEEZY_STORE_ID and LEMONSQUEEZY_VARIANT_PRO.'
            );
        }
    }

    private function apiKey(): string
    {
        return (string) config('billing.lemonsqueezy.api_key', '');
    }

    private function storeId(): string
    {
        return (string) config('billing.lemonsqueezy.store_id', '');
    }

    private function variantPro(): string
    {
        return (string) config('billing.lemonsqueezy.variant_pro', '');
    }

    private function webhookSecret(): string
    {
        return (string) config('billing.lemonsqueezy.webhook_secret', '');
    }
}
