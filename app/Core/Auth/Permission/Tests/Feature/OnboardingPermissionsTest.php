<?php

use App\Core\Auth\Permission\Contracts\PermissionRegistryContract;
use App\Core\Auth\Permission\Models\Permission;
use App\Core\Auth\Permission\Services\DomainPermissionSynchronizer;
use App\Core\Auth\Role\Models\Role;
use App\Core\Identity\Models\User;
use App\Domains\Dailies\Models\DailyReport;
use App\Domains\Invoices\Models\Invoice;
use App\Domains\RFIs\Models\RFI;
use App\Domains\Timecards\Models\Timecard;

/*
 | SadSha Build §4 — onboarding permission model.
 |
 | The built-in User role must carry exactly the default member working
 | set below (23 keys). The first user of a new org gets the Admin role
 | instead (all 191 permissions via the existing sync); org owners must
 | NOT get is_admin=true (platform flag only).
 |
 | This list is the single source of truth for the set and must match
 | docs/saas/onboarding-permissions.md. Change one, change the other.
 */
if (! function_exists('onboardingMemberPermissions')) {
    function onboardingMemberPermissions(): array
    {
        return [
            'announcements.view',
            'clients.view',
            'dailies.create',
            'dailies.edit',
            'dailies.submit',
            'dailies.view',
            'documents.view',
            'plans.annotate',
            'plans.compare',
            'plans.view',
            'projects.view',
            'projects.view-documents',
            'task-categories.view',
            'tasks.create',
            'tasks.edit',
            'tasks.edit-notes',
            'tasks.edit-progress',
            'tasks.edit-status',
            'tasks.view',
            'timecards.create',
            'timecards.edit',
            'timecards.submit',
            'timecards.view',
        ];
    }
}

if (! function_exists('onboardingMemberUser')) {
    function onboardingMemberUser(): User
    {
        app(DomainPermissionSynchronizer::class)->sync();

        $user = User::factory()->create(['is_admin' => false]);

        $userRole = Role::query()->where('name', Role::BUILT_IN_USER)->firstOrFail();
        $user->roles()->sync([$userRole->id]);
        User::bumpPermissionCacheVersion();

        return $user->fresh();
    }
}

it('registers exactly the default member set for the built-in User role', function (): void {
    $registry = app(PermissionRegistryContract::class);

    $userKeys = $registry->builtInRolePermissions()[Role::BUILT_IN_USER] ?? [];
    sort($userKeys);

    expect($userKeys)->toBe(onboardingMemberPermissions())
        ->and($registry->permissions())->toHaveCount(191);
});

it('syncs the default member set onto the User role and all permissions onto Admin', function (): void {
    app(DomainPermissionSynchronizer::class)->sync();

    $keysFor = fn (string $roleName): array => Role::query()
        ->where('name', $roleName)
        ->firstOrFail()
        ->permissions()
        ->get(['resource', 'action'])
        ->map(fn (Permission $permission): string => $permission->resource.'.'.$permission->action)
        ->sort()
        ->values()
        ->all();

    expect($keysFor(Role::BUILT_IN_USER))->toBe(onboardingMemberPermissions());

    $adminKeys = $keysFor(Role::BUILT_IN_ADMIN);
    $allKeys = Permission::query()
        ->get(['resource', 'action'])
        ->map(fn (Permission $permission): string => $permission->resource.'.'.$permission->action)
        ->sort()
        ->values()
        ->all();

    expect($adminKeys)->toBe($allKeys)->toHaveCount(191);
});

it('lets a member user open the working set and denies the admin set', function (): void {
    $member = onboardingMemberUser();

    $this->actingAs($member);

    // Working set — the pages a field member needs on day one.
    foreach (['/timecards', '/dailies', '/projects', '/tasks', '/documents', '/dashboard'] as $path) {
        $this->get($path)->assertOk();
    }

    // Admin set — invoices/RFIs stay admin-only; no user-level routes exist.
    $this->get('/admin/invoices')->assertForbidden();
    $this->get('/admin/rfis')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();
});

it('lets a member view their own timecard but not another member’s', function (): void {
    $member = onboardingMemberUser();
    $other = User::factory()->create(['is_admin' => false]);

    $ownTimecard = Timecard::factory()->create(['user_id' => $member->id]);
    $otherTimecard = Timecard::factory()->create(['user_id' => $other->id]);

    expect($member->can('view', $ownTimecard))->toBeTrue()
        ->and($member->can('view', $otherTimecard))->toBeFalse()
        ->and($member->can('submit', $ownTimecard))->toBeTrue()
        ->and($member->can('approve', $ownTimecard))->toBeFalse()
        ->and($member->can('viewAny', DailyReport::class))->toBeTrue()
        ->and($member->can('viewAny', Invoice::class))->toBeFalse()
        ->and($member->can('viewAny', RFI::class))->toBeFalse();

    $this->actingAs($member);
    $this->get("/timecards/{$ownTimecard->id}")->assertOk();
    $this->get("/timecards/{$otherTimecard->id}")->assertForbidden();
});

it('gives the first user of an org the Admin role, not the is_admin flag', function (): void {
    app(DomainPermissionSynchronizer::class)->sync();

    $owner = User::factory()->create(['is_admin' => false]);

    $adminRole = Role::query()->where('name', Role::BUILT_IN_ADMIN)->firstOrFail();
    $owner->roles()->sync([$adminRole->id]);
    User::bumpPermissionCacheVersion();
    $owner = $owner->fresh();

    expect($owner->is_admin)->toBeFalse()
        ->and($owner->isAdmin())->toBeTrue()
        ->and($owner->hasPermission('invoices.view'))->toBeTrue()
        ->and($owner->hasPermission('rfis.view'))->toBeTrue()
        ->and($owner->hasPermission('projects.view-financials'))->toBeTrue();
});
