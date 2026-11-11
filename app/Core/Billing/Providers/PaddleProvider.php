<?php

namespace App\Core\Billing\Providers;

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Tenancy\Models\Organization;
use LogicException;

/**
 * Paddle billing provider — STUB ONLY (§2).
 *
 * Paddle is stuck on proof-of-address verification, so Paddle must NOT
 * be built now. This stub exists so the gateway stays swappable: set
 * BILLING_PROVIDER=paddle and every billing call fails loudly with
 * "not built yet" instead of silently doing nothing. Build it only
 * after Paddle approves the seller account.
 */
class PaddleProvider implements BillingProvider
{
    public function createCheckout(Organization $organization, string $plan): string
    {
        throw new LogicException('Paddle billing is not built yet: Paddle is stuck on proof-of-address verification.');
    }

    /**
     * @return array<int, string>
     */
    public function handleWebhook(string $rawPayload, ?string $signature, ?string $eventName = null): array
    {
        throw new LogicException('Paddle billing is not built yet: Paddle is stuck on proof-of-address verification.');
    }

    public function portalUrl(Organization $organization): ?string
    {
        throw new LogicException('Paddle billing is not built yet: Paddle is stuck on proof-of-address verification.');
    }

    public function cancelSubscription(Organization $organization): void
    {
        throw new LogicException('Paddle billing is not built yet: Paddle is stuck on proof-of-address verification.');
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
