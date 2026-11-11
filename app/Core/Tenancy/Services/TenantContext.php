<?php

namespace App\Core\Tenancy\Services;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;

/**
 * Request-scoped tenant state, populated by TenantResolver (via the
 * ResolveTenant middleware on web requests, or directly in tests/console).
 *
 * Capability (roles/permissions) and data visibility (tenancy) are separate
 * layers: a user holding the Admin *role* is NOT a platform admin here.
 * Only the users.is_admin platform flag bypasses tenant scoping.
 */
class TenantContext
{
    /** @var array<int, string> */
    private array $organizationIds = [];

    private ?string $currentOrganizationId = null;

    private bool $platformAdmin = false;

    public function isEnabled(): bool
    {
        return (bool) config('saas.mode', false);
    }

    public function setPlatformAdmin(bool $platformAdmin): void
    {
        $this->platformAdmin = $platformAdmin;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platformAdmin;
    }

    /**
     * @param  array<int, string>  $organizationIds
     */
    public function setOrganizationIds(array $organizationIds, ?string $currentOrganizationId = null): void
    {
        $this->organizationIds = array_values(array_unique(array_map('strval', $organizationIds)));
        $this->currentOrganizationId = $currentOrganizationId !== null && in_array($currentOrganizationId, $this->organizationIds, true)
            ? $currentOrganizationId
            : ($this->organizationIds[0] ?? null);
    }

    /**
     * @return array<int, string>
     */
    public function organizationIds(): array
    {
        return $this->organizationIds;
    }

    public function currentOrganizationId(): ?string
    {
        return $this->currentOrganizationId;
    }

    public function currentOrganization(): ?Organization
    {
        if ($this->currentOrganizationId === null) {
            return null;
        }

        return Organization::query()->find($this->currentOrganizationId);
    }

    public function hasTenantContext(): bool
    {
        return $this->currentOrganizationId !== null;
    }

    public function clear(): void
    {
        $this->organizationIds = [];
        $this->currentOrganizationId = null;
        $this->platformAdmin = false;
    }
}
