<?php

namespace App\Core\Auth\Role\Policies;

use App\Core\Auth\Role\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;

class RolePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        // SaaS mode (§2): the platform admin area is platform-operator
        // only (raw is_admin flag). An org owner holding the Admin role
        // must not reach platform-wide screens. See
        // TenantAuthorizer::platformAreaBefore().
        return TenantAuthorizer::platformAreaBefore($user);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.edit');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.delete') && ! $role->isBuiltIn();
    }

    public function assignPermissions(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.assign-permissions') || $user->hasPermission('roles.edit');
    }

    public function assignUsers(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.edit');
    }
}
