<?php

use App\Core\Auth\Permission\Services\DomainPermissionSynchronizer;
use App\Core\Auth\Role\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Scheduler\Models\ScheduledTask;
use App\Core\Settings\Models\SettingsSqlite;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use App\Core\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    app(TenantContext::class)->clear();
    app(DomainPermissionSynchronizer::class)->sync();
});

/**
 * An org owner: NOT the platform is_admin flag, but holding the built-in
 * Admin role (§4) — exactly the user the §2 security fix is about.
 */
function platformAreaOrgOwner(): User
{
    $organization = Organization::factory()->create();

    $user = User::factory()->create(['is_admin' => false]);

    $adminRoleId = Role::query()
        ->where('name', Role::BUILT_IN_ADMIN)
        ->value('id');

    $user->roles()->sync([$adminRoleId]);

    OrganizationMember::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationMember::ROLE_OWNER,
    ]);

    return $user->fresh();
}

function platformAreaPlatformAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('denies the admin gate to an org owner in saas mode but allows the platform admin', function (): void {
    config()->set('saas.mode', true);

    $owner = platformAreaOrgOwner();
    $platformAdmin = platformAreaPlatformAdmin();

    expect(Gate::forUser($owner)->allows('admin'))->toBeFalse()
        ->and(Gate::forUser($platformAdmin)->allows('admin'))->toBeTrue();
});

it('denies platform admin-area policies to an org owner in saas mode', function (): void {
    config()->set('saas.mode', true);

    $owner = platformAreaOrgOwner();
    $platformAdmin = platformAreaPlatformAdmin();

    // The owner holds the Admin role, so without the §2 fix every one
    // of these would pass via isAdmin() / the all-permissions snapshot.
    expect(Gate::forUser($owner)->allows('viewAny', User::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('viewAny', Role::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('viewAny', SettingsSqlite::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('viewAny', ScheduledTask::class))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('queue.viewAny'))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('queue.manage'))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('manage-email-accounts'))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('viewTelescope'))->toBeFalse();

    expect(Gate::forUser($platformAdmin)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('viewAny', Role::class))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('viewAny', SettingsSqlite::class))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('viewAny', ScheduledTask::class))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('queue.viewAny'))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('queue.manage'))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('manage-email-accounts'))->toBeTrue()
        ->and(Gate::forUser($platformAdmin)->allows('viewTelescope'))->toBeTrue();
});

it('returns 403 on platform admin routes for an org owner in saas mode', function (): void {
    config()->set('saas.mode', true);

    $owner = platformAreaOrgOwner();
    $platformAdmin = platformAreaPlatformAdmin();

    $this->actingAs($owner)
        ->get(route('admin.users.index'))
        ->assertForbidden();

    $this->actingAs($platformAdmin)
        ->get(route('admin.users.index'))
        ->assertOk();
});

it('keeps the old behaviour when saas mode is off', function (): void {
    config()->set('saas.mode', false);

    $owner = platformAreaOrgOwner();
    $plainUser = User::factory()->create(['is_admin' => false]);

    expect(Gate::forUser($owner)->allows('admin'))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('queue.viewAny'))->toBeTrue()
        ->and(Gate::forUser($plainUser)->allows('admin'))->toBeFalse();
});
