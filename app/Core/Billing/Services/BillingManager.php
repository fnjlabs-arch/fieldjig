<?php

namespace App\Core\Billing\Services;

use App\Core\Billing\Contracts\BillingProvider;

/**
 * Resolves the configured billing provider (§2). App code asks the
 * manager, never a concrete provider — the gateway stays swappable via
 * the BILLING_PROVIDER env var.
 */
class BillingManager
{
    public function provider(): BillingProvider
    {
        return app(BillingProvider::class);
    }

    public function providerName(): string
    {
        return (string) config('billing.provider', 'lemonsqueezy');
    }
}
