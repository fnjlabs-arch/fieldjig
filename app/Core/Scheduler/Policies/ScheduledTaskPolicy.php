<?php

namespace App\Core\Scheduler\Policies;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;
use App\Core\Scheduler\Models\ScheduledTask;

class ScheduledTaskPolicy
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
        return $user->hasPermission('scheduler.view');
    }

    public function view(User $user, ScheduledTask $scheduledTask): bool
    {
        return $user->hasPermission('scheduler.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('scheduler.create');
    }

    public function update(User $user, ScheduledTask $scheduledTask): bool
    {
        return $user->hasPermission('scheduler.edit');
    }

    public function delete(User $user, ScheduledTask $scheduledTask): bool
    {
        return $user->hasPermission('scheduler.delete');
    }

    public function toggle(User $user, ScheduledTask $scheduledTask): bool
    {
        return $user->hasPermission('scheduler.toggle');
    }

    public function run(User $user, ScheduledTask $scheduledTask): bool
    {
        return $user->hasPermission('scheduler.run');
    }
}
