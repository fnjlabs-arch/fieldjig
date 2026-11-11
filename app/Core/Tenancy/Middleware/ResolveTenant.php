<?php

namespace App\Core\Tenancy\Middleware;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Services\TenantContext;
use App\Core\Tenancy\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant context for the authenticated user (see
 * TenantResolver). No-op when SaaS mode is off or the request is a guest.
 * Runs after StartSession in the web group, so the session-selected org
 * is available.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        if (! $context->isEnabled()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $selectedOrganizationId = $request->hasSession()
            ? $request->session()->get('tenant_organization_id')
            : null;

        app(TenantResolver::class)->resolve(
            $user,
            is_string($selectedOrganizationId) ? $selectedOrganizationId : null,
        );

        // SaaS billing (§2): lazy subscription state transitions. A
        // scheduled `saas:expire-trials` command is a documented
        // follow-up; until then the transition happens on first request.
        $this->expireTrials($context);

        return $next($request);
    }

    /**
     * trialing with an expired trial_ends_at → past_due (past_due_at =
     * now, starting the 7-day grace period); past_due for more than 7
     * days → canceled. Platform admins have no current org and skip.
     */
    private function expireTrials(TenantContext $context): void
    {
        $organization = $context->currentOrganization();

        if (! $organization instanceof Organization) {
            return;
        }

        if ($organization->status === Organization::STATUS_TRIALING
            && $organization->trial_ends_at !== null
            && $organization->trial_ends_at->isPast()
        ) {
            $organization->update([
                'status' => Organization::STATUS_PAST_DUE,
                'past_due_at' => now(),
            ]);

            return;
        }

        if ($organization->status === Organization::STATUS_PAST_DUE
            && $organization->past_due_at !== null
            && $organization->past_due_at->copy()->addDays(7)->isPast()
        ) {
            $organization->update(['status' => Organization::STATUS_CANCELED]);
        }
    }
}
