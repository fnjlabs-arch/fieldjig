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
 * Cancels the current org's subscription at the provider (§2). The
 * subscription_cancelled webhook confirms the cancellation; the local
 * status is updated by the provider call itself.
 */
class BillingCancelController
{
    public function __invoke(Request $request, BillingManager $billing, TenantContext $context): RedirectResponse
    {
        $organization = $context->currentOrganization() ?? $request->user()?->organizations()->first();

        if ($organization === null) {
            return redirect()->route('billing.panel')
                ->withErrors(['billing' => 'No organization found.']);
        }

        try {
            $billing->provider()->cancelSubscription($organization);
        } catch (BillingNotConfiguredException) {
            return redirect()->route('billing.panel')
                ->withErrors(['billing' => 'Billing is not configured yet.']);
        } catch (RuntimeException) {
            return redirect()->route('billing.panel')
                ->withErrors(['billing' => 'Cancellation failed. Please try again.']);
        }

        return redirect()->route('billing.panel')
            ->with('status', 'Your subscription has been canceled.');
    }
}
