<?php

namespace App\Core\Auth\User\Policies;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;

class UserPolicy
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
        return $user->hasPermission('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('users.edit');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission('users.delete') && ! $target->is_built_in;
    }
}
