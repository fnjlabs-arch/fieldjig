<?php

namespace App\Core\Billing\Providers;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\Exceptions\BillingNotConfiguredException;
use App\Core\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Core\Billing\Services\PaddleWebhookHandler;
use App\Core\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

/**
 * Paddle billing provider — the live gateway (replaces Lemon Squeezy,
 * whose store application was rejected 2026-11-16).
 *
 * Docs (verified 2026-11-23, developer.paddle.com):
 * - Checkouts: create the customer first (POST /customers {email}),
 *   then POST /transactions {items: [{price_id, quantity: 1}],
 *   customer_id, custom_data: {organization_id}}; the checkout URL is
 *   data.checkout.url (<default payment link>?_ptxn=<txn id>).
 *   The Paddle price carries NO trial period — the 30-day trial is
 *   handled in the app (org trialing at signup, 1-project cap).
 * - Webhooks: signature in the `Paddle-Signature` header,
 *   `ts=<unix>;h1=<hex>[;h1=...]`; signed payload is
 *   `ts + ':' + rawBody`, HMAC-SHA256 with the webhook secret,
 *   timing-safe compare, timestamps more than 300s old are rejected
 *   (same algorithm as the Toolzo Paddle worker, §5.3).
 *   Event type comes from the payload `event_type` field.
 * - Customer portal: POST /customers/{id}/portal-sessions returns
 *   urls.general.overview — a one-time, time-limited link; mint a
 *   fresh session per click, never cache it.
 * - Cancel: POST /subscriptions/{id}/cancel
 *   {effective_from: "next_billing_period"} — access runs to the end
 *   of the current period; the subscription.canceled webhook confirms.
 *
 * No live API calls happen in tests: checkout/cancel/portal go
 * through Laravel's HTTP client (Http::fake in tests) and webhooks
 * are verified against known HMAC test vectors.
 */
class PaddleProvider implements BillingProvider
{
    private const API_BASE_URL = 'https://api.paddle.com';

    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __construct(private readonly PaddleWebhookHandler $webhookHandler) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->pricePro() !== '';
    }

    public function createCheckout(Organization $organization, string $plan): string
    {
        $this->ensureConfigured();

        $customerId = $this->resolveCustomerId($organization);

        $response = Http::withToken($this->apiKey())
            ->withHeaders(['Paddle-Version' => '1'])
            ->post(self::API_BASE_URL.'/transactions', [
                'items' => [
                    ['price_id' => $this->pricePro(), 'quantity' => 1],
                ],
                'customer_id' => $customerId,
                'custom_data' => ['organization_id' => (string) $organization->id],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Paddle transaction creation failed with HTTP '.$response->status().'.');
        }

        $url = $response->json('data.checkout.url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Paddle transaction creation returned no checkout URL.');
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
            throw new InvalidWebhookSignatureException('Paddle webhook secret is not configured.');
        }

        if (! $this->verifySignature($rawPayload, $signature, $secret)) {
            throw new InvalidWebhookSignatureException('Paddle webhook signature did not verify.');
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidWebhookSignatureException('Paddle webhook body was not valid JSON.', 0, $exception);
        }

        if (! is_array($payload)) {
            throw new InvalidWebhookSignatureException('Paddle webhook body was not a JSON object.');
        }

        /** @var mixed $payloadEventType */
        $payloadEventType = $payload['event_type'] ?? null;
        $resolvedEventName = $eventName ?? (is_string($payloadEventType) ? $payloadEventType : 'unknown');

        return $this->webhookHandler->handle($resolvedEventName, $rawPayload, $payload);
    }

    public function portalUrl(Organization $organization): ?string
    {
        $customerId = $organization->billing_customer_id;

        if ($customerId === null || ! $this->isConfigured()) {
            return null;
        }

        $body = [];

        if ($organization->billing_subscription_id !== null) {
            $body['subscription_ids'] = [$organization->billing_subscription_id];
        }

        $response = Http::withToken($this->apiKey())
            ->withHeaders(['Paddle-Version' => '1'])
            ->post(self::API_BASE_URL.'/customers/'.$customerId.'/portal-sessions', $body);

        if ($response->failed()) {
            Log::warning('Paddle customer portal session creation failed.', [
                'organization_id' => $organization->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $url = $response->json('data.urls.general.overview');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function cancelSubscription(Organization $organization): void
    {
        $subscriptionId = $organization->billing_subscription_id;

        if ($subscriptionId === null) {
            return;
        }

        $this->ensureConfigured();

        $response = Http::withToken($this->apiKey())
            ->withHeaders(['Paddle-Version' => '1'])
            ->post(self::API_BASE_URL.'/subscriptions/'.$subscriptionId.'/cancel', [
                'effective_from' => 'next_billing_period',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Paddle subscription cancellation failed with HTTP '.$response->status().'.');
        }

        // The subscription.canceled webhook confirms this once the
        // period ends; local state is updated on the webhook, not here,
        // so the org keeps access until current_period_end.
    }

    /**
     * Resolve the org's Paddle customer id, creating the customer from
     * the org owner's email when the org has none yet.
     */
    private function resolveCustomerId(Organization $organization): string
    {
        if ($organization->billing_customer_id !== null) {
            return $organization->billing_customer_id;
        }

        $ownerEmail = $organization->users()
            ->orderBy('organization_members.created_at')
            ->value('users.email');

        if (! is_string($ownerEmail) || $ownerEmail === '') {
            throw new RuntimeException('Paddle checkout needs an organization owner email and the organization has none.');
        }

        $response = Http::withToken($this->apiKey())
            ->withHeaders(['Paddle-Version' => '1'])
            ->post(self::API_BASE_URL.'/customers', ['email' => $ownerEmail]);

        if ($response->failed()) {
            throw new RuntimeException('Paddle customer creation failed with HTTP '.$response->status().'.');
        }

        $customerId = $response->json('data.id');

        if (! is_string($customerId) || $customerId === '') {
            throw new RuntimeException('Paddle customer creation returned no customer id.');
        }

        $organization->update([
            'billing_customer_id' => $customerId,
            'billing_provider' => 'paddle',
        ]);

        return $customerId;
    }

    /**
     * Verify a Paddle webhook signature — the exact algorithm from the
     * Toolzo Paddle worker (§5.3): header `ts=<unix>;h1=<hex>[;h1=...]`,
     * signed payload `ts + ':' + rawBody`, HMAC-SHA256, timing-safe
     * compare against any h1, timestamps more than 300s old rejected.
     */
    private function verifySignature(string $rawPayload, ?string $signature, string $secret): bool
    {
        if ($signature === null) {
            return false;
        }

        $timestamp = null;
        $digests = [];

        foreach (explode(';', $signature) as $part) {
            $part = trim($part);

            if (str_starts_with($part, 'ts=')) {
                $timestamp = substr($part, 3);
            } elseif (str_starts_with($part, 'h1=')) {
                $digests[] = substr($part, 3);
            }
        }

        if ($timestamp === null || $timestamp === '' || $digests === [] || ! is_numeric($timestamp)) {
            return false;
        }

        // Replay protection: reject timestamps more than 300s from now, either way.
        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$rawPayload, $secret);

        foreach ($digests as $digest) {
            $digest = strtolower(trim($digest));

            if ($digest === '') {
                continue;
            }

            if (hash_equals($expected, $digest)) {
                return true;
            }
        }

        return false;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new BillingNotConfiguredException(
                'Paddle is not configured: set PADDLE_API_KEY and PADDLE_PRICE_PRO.'
            );
        }
    }

    private function apiKey(): string
    {
        return (string) config('billing.paddle.api_key', '');
    }

    private function pricePro(): string
    {
        return (string) config('billing.paddle.price_pro', '');
    }

    private function webhookSecret(): string
    {
        return (string) config('billing.paddle.webhook_secret', '');
    }
}
