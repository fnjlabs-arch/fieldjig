<?php

namespace App\Core\Billing\Middleware;

use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canceled orgs keep no app access except the billing panel (§2 state
 * machine). GET requests from a user whose current org is canceled are
 * redirected to /billing; the billing panel itself, auth/email routes,
 * Livewire updates and non-GET requests pass through (the panel's own
 * actions must keep working, and policies still gate the data).
 * Platform admins bypass tenant scoping entirely and are unaffected.
 */
class EnsureSubscriptionNotCanceled
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        if (! $context->isEnabled() || $context->isPlatformAdmin()) {
            return $next($request);
        }

        $organization = $context->currentOrganization();

        if ($organization === null || $organization->status !== Organization::STATUS_CANCELED) {
            return $next($request);
        }

        if (! $request->isMethod('GET')
            || $request->is('billing*', 'livewire/*', 'login*', 'logout', 'email/*', 'up')) {
            return $next($request);
        }

        return redirect()->route('billing.panel');
    }
}
