<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Exceptions\BillingNotConfiguredException;
use App\Core\Billing\Services\BillingManager;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Starts a Lemon Squeezy checkout for the current org (§2): resolves
 * the provider checkout URL and redirects the customer to Lemon.
 */
class BillingCheckoutController
{
    public function __invoke(Request $request, BillingManager $billing, TenantContext $context): RedirectResponse
    {
        $organization = $this->resolveOrganization($request, $context);

        if ($organization === null) {
            return redirect()->route('billing.panel')
                ->withErrors(['billing' => 'Create an organization first before upgrading.']);
        }

        try {
            $checkoutUrl = $billing->provider()->createCheckout($organization, Organization::PLAN_PAID);
        } catch (BillingNotConfiguredException) {
            return redirect()->route('billing.panel')
                ->withErrors(['billing' => 'Billing is not configured yet — the Lemon Squeezy variant has not been set up.']);
        } catch (RuntimeException) {
            return redirect()->route('billing.panel')
                ->withErrors(['billing' => 'Checkout could not be started. Please try again.']);
        }

        return redirect()->away($checkoutUrl);
    }

    private function resolveOrganization(Request $request, TenantContext $context): ?Organization
    {
        $organization = $context->currentOrganization();

        if ($organization !== null) {
            return $organization;
        }

        return $request->user()?->organizations()->first();
    }
}
