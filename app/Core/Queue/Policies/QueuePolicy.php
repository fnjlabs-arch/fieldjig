<?php

namespace App\Core\Queue\Policies;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;
use App\Core\Tenancy\Services\TenantContext;

class QueuePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        // SaaS mode (§2): the platform admin area is platform-operator
        // only (raw is_admin flag). See TenantAuthorizer::platformAreaBefore().
        return TenantAuthorizer::platformAreaBefore($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->platformAreaOrPermission($user, 'queue.view');
    }

    public function manage(User $user): bool
    {
        return $this->platformAreaOrPermission($user, 'queue.manage');
    }

    /**
     * NOTE: these methods are also called directly by Gate::define
     * ('queue.viewAny' / 'queue.manage'), which bypasses before() — so
     * the SaaS-mode platform check must live here too. In SaaS mode the
     * permission fallback is deliberately NOT consulted: an org owner
     * holding the Admin role holds every permission key, so it would
     * re-grant the platform area. When SaaS mode is off, behaviour is
     * unchanged (isAdmin() OR the permission key).
     */
    private function platformAreaOrPermission(User $user, string $permission): bool
    {
        if (app(TenantContext::class)->isEnabled()) {
            return TenantAuthorizer::isPlatformAdmin($user);
        }

        return $user->isAdmin() || $user->hasPermission($permission);
    }
}
