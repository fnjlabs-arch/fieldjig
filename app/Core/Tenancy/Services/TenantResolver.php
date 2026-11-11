<?php

namespace App\Core\Tenancy\Services;

use App\Core\Identity\Models\User;

/**
 * Resolves the tenant context for an authenticated user and stores it on
 * the TenantContext singleton.
 *
 * - Exactly one org → it.
 * - Multiple orgs → the session-selected org, defaulting to the first.
 * - None → no tenant context (project creation then 403s).
 * - Platform is_admin users bypass tenant scoping entirely.
 */
class TenantResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolve(User $user, ?string $sessionSelectedOrganizationId = null): TenantContext
    {
        if (TenantAuthorizer::isPlatformAdmin($user)) {
            $this->context->setPlatformAdmin(true);
            $this->context->setOrganizationIds([], null);

            return $this->context;
        }

        $this->context->setPlatformAdmin(false);

        $organizationIds = $user->organizations()
            ->orderBy('organizations.created_at')
            ->pluck('organizations.id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $current = null;

        if ($sessionSelectedOrganizationId !== null && in_array($sessionSelectedOrganizationId, $organizationIds, true)) {
            $current = $sessionSelectedOrganizationId;
        }

        $this->context->setOrganizationIds($organizationIds, $current);

        return $this->context;
    }
}
