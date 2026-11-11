<?php

namespace App\Core\Billing\Contracts;

use App\Core\Tenancy\Models\Organization;

/**
 * Provider-agnostic billing contract (§2). The app talks to this
 * interface only; the configured implementation comes from
 * BillingManager (env BILLING_PROVIDER, default lemonsqueezy).
 */
interface BillingProvider
{
    /**
     * Create a checkout session for the org's paid plan and return the
     * URL the customer is redirected to.
     *
     * @throws \App\Core\Billing\Exceptions\BillingNotConfiguredException when the provider has no credentials/variant configured.
     */
    public function createCheckout(Organization $organization, string $plan): string;

    /**
     * Verify and apply a webhook delivery.
     *
     * @param  string  $rawPayload  The request body exactly as received (the signature is computed over these bytes).
     * @param  string|null  $signature  The provider's signature header value.
     * @param  string|null  $eventName  The provider's event-name header value, if any.
     * @return array<int, string> The event names that were applied (empty when the delivery was an idempotent replay).
     *
     * @throws \App\Core\Billing\Exceptions\InvalidWebhookSignatureException when the signature does not verify.
     */
    public function handleWebhook(string $rawPayload, ?string $signature, ?string $eventName = null): array;

    /**
     * URL of the provider's customer billing portal, or null when the
     * provider has no self-serve portal (Lemon Squeezy: null).
     */
    public function portalUrl(Organization $organization): ?string;

    /**
     * Cancel the org's subscription at the provider. No-op when the org
     * has no provider subscription id.
     */
    public function cancelSubscription(Organization $organization): void;

    /**
     * Whether the provider has everything it needs to take a payment
     * (credentials + store + variant). False means the billing panel
     * must show the "billing not configured yet" state.
     */
    public function isConfigured(): bool;
}
