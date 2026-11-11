<?php

namespace App\Core\Settings\Policies;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;
use App\Core\Settings\Models\SettingsSqlite;

class SettingPolicy
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
        return $user->hasPermission('settings.view');
    }

    public function view(User $user, SettingsSqlite $setting): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('settings.edit');
    }

    public function import(User $user): bool
    {
        return $user->hasPermission('settings.import');
    }
}
