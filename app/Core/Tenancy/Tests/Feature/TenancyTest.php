<?php

use App\Core\Auth\Permission\Models\Permission;
use App\Core\Auth\Permission\Services\DomainPermissionSynchronizer;
use App\Core\Auth\Role\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use App\Core\Tenancy\Services\TenantContext;
use App\Core\Tenancy\Services\TenantLimits;
use App\Core\Tenancy\Services\TenantProjectCreation;
use App\Core\Tenancy\Services\TenantResolver;
use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Livewire\Admin\Projects\Form as ProjectForm;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectUserAccess;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config()->set('saas.mode', true);
    app(TenantContext::class)->clear();
});

/**
 * Non-admin user with the given permission keys (via a fresh role) and,
 * optionally, membership of the given org.
 *
 * @param  array<int, string>  $permissions
 */
function tenancyUserWithPermissions(array $permissions, ?Organization $organization = null, string $memberRole = OrganizationMember::ROLE_MEMBER): User
{
    app(DomainPermissionSynchronizer::class)->sync();

    $user = User::factory()->create(['is_admin' => false]);

    $role = Role::query()->create([
        'name' => 'Tenancy Test Role '.str()->uuid(),
        'description' => 'Role for tenancy tests',
        'is_active' => true,
        'built_in' => false,
        'access_level' => 20,
    ]);

    $permissionIds = collect($permissions)
        ->map(function (string $permission): ?string {
            [$resource, $action] = explode('.', $permission, 2);

            return Permission::query()
                ->where('resource', $resource)
                ->where('action', $action)
                ->value('id');
        })
        ->filter()
        ->values()
        ->all();

    $role->permissions()->sync($permissionIds);
    $user->roles()->sync([$role->id]);

    if ($organization !== null) {
        OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $memberRole,
        ]);
    }

    return $user->fresh();
}

function tenancyResolveFor(User $user, ?string $selectedOrganizationId = null): void
{
    app(TenantResolver::class)->resolve($user, $selectedOrganizationId);
}

it('is a no-op when saas mode is off', function (): void {
    config()->set('saas.mode', false);

    $organization = Organization::factory()->create();
    $project = Project::factory()->create(['organization_id' => $organization->id]);
    $user = tenancyUserWithPermissions(['projects.view']);

    expect(Gate::forUser($user)->allows('view', $project))->toBeTrue()
        ->and(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});

it('lets a tenant member view their own org project but not another org project or legacy data', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = tenancyUserWithPermissions(['projects.view', 'projects.edit', 'projects.delete'], $orgA);

    $ownProject = Project::factory()->create(['organization_id' => $orgA->id]);
    $otherProject = Project::factory()->create(['organization_id' => $orgB->id]);
    $legacyProject = Project::factory()->create(['organization_id' => null]);

    tenancyResolveFor($user);

    expect(Gate::forUser($user)->allows('view', $ownProject))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $otherProject))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $otherProject))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $otherProject))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', $legacyProject))->toBeFalse();
});

it('denies cross-org client read and write', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = tenancyUserWithPermissions(['clients.view', 'clients.edit', 'clients.delete'], $orgA);

    $ownClient = Client::factory()->create(['organization_id' => $orgA->id]);
    $otherClient = Client::factory()->create(['organization_id' => $orgB->id]);

    tenancyResolveFor($user);

    expect(Gate::forUser($user)->allows('view', $ownClient))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $otherClient))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $otherClient))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $otherClient))->toBeFalse();
});

it('filters project and client lists to the caller orgs', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = tenancyUserWithPermissions(['projects.view'], $orgA);

    $ownProject = Project::factory()->create(['organization_id' => $orgA->id]);
    $otherProject = Project::factory()->create(['organization_id' => $orgB->id]);
    $legacyProject = Project::factory()->create(['organization_id' => null]);
    $ownClient = Client::factory()->create(['organization_id' => $orgA->id]);
    $otherClient = Client::factory()->create(['organization_id' => $orgB->id]);

    tenancyResolveFor($user);

    $projectIds = Project::query()->pluck('id')->map(fn ($id): string => (string) $id)->all();
    $clientIds = Client::query()->pluck('id')->map(fn ($id): string => (string) $id)->all();

    expect($projectIds)->toContain((string) $ownProject->id)
        ->and($projectIds)->not->toContain((string) $otherProject->id)
        ->and($projectIds)->not->toContain((string) $legacyProject->id)
        ->and($clientIds)->toContain((string) $ownClient->id)
        ->and($clientIds)->not->toContain((string) $otherClient->id);
});

it('filters the user project list per org and forbids the cross-org show page', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = tenancyUserWithPermissions(['projects.view'], $orgA);

    $ownProject = Project::factory()->create([
        'name' => 'Tenant A HQ Build',
        'organization_id' => $orgA->id,
        'project_manager_id' => $user->id,
        'status' => 'in_progress',
        'is_active' => true,
    ]);
    $otherProject = Project::factory()->create([
        'name' => 'Tenant B Secret Build',
        'organization_id' => $orgB->id,
        'project_manager_id' => $user->id,
        'status' => 'in_progress',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertSuccessful()
        ->assertSee('Tenant A HQ Build')
        ->assertDontSee('Tenant B Secret Build');

    $this->actingAs($user)
        ->get(route('projects.show', $ownProject))
        ->assertSuccessful()
        ->assertSee('Tenant A HQ Build');

    $this->actingAs($user)
        ->get(route('projects.show', $otherProject))
        ->assertForbidden();
});

it('resolves the session-selected org when a user belongs to several', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = tenancyUserWithPermissions(['projects.view'], $orgA);
    OrganizationMember::query()->create([
        'organization_id' => $orgB->id,
        'user_id' => $user->id,
        'role' => OrganizationMember::ROLE_MEMBER,
    ]);

    tenancyResolveFor($user, (string) $orgB->id);

    expect(app(TenantContext::class)->currentOrganizationId())->toBe((string) $orgB->id)
        ->and(app(TenantContext::class)->organizationIds())->toBe([(string) $orgA->id, (string) $orgB->id]);

    // An unknown selection falls back to the first org.
    tenancyResolveFor($user, 'not-an-org');

    expect(app(TenantContext::class)->currentOrganizationId())->toBe((string) $orgA->id);

    // No selection defaults to the first org.
    tenancyResolveFor($user);

    expect(app(TenantContext::class)->currentOrganizationId())->toBe((string) $orgA->id);
});

it('scopes project creation to the current org and grants the creator project-admin access', function (): void {
    $organization = Organization::factory()->create();
    $user = tenancyUserWithPermissions(['projects.create'], $organization, OrganizationMember::ROLE_OWNER);

    // Livewire test calls do not run the web middleware stack, so the
    // tenant context (normally set by ResolveTenant) must be set here.
    tenancyResolveFor($user);

    Livewire::actingAs($user)
        ->test(ProjectForm::class)
        ->set('name', 'Tenant HQ Build')
        ->set('status', 'in_progress')
        ->call('save');

    $project = Project::query()->where('name', 'Tenant HQ Build')->first();

    expect($project)->not->toBeNull()
        ->and((string) $project->organization_id)->toBe((string) $organization->id)
        ->and(ProjectUserAccess::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists())->toBeTrue();

    $access = ProjectUserAccess::query()
        ->where('project_id', $project->id)
        ->where('user_id', $user->id)
        ->first();

    expect($access->permission_keys)->toContain('projects.view', 'projects.edit', 'projects.delete');
});

it('caps trial orgs at one project', function (): void {
    $organization = Organization::factory()->create(['plan' => Organization::PLAN_TRIAL]);
    $user = tenancyUserWithPermissions(['projects.create'], $organization, OrganizationMember::ROLE_OWNER);

    Project::factory()->create(['organization_id' => $organization->id]);

    tenancyResolveFor($user);

    expect(fn () => app(TenantProjectCreation::class)->create($user, [
        'name' => 'Second Trial Project',
        'status' => 'pending',
        'is_active' => true,
    ]))->toThrow(HttpException::class, 'Trial limit reached');
});

it('allows a paid org past one project', function (): void {
    $organization = Organization::factory()->paid()->create();
    $user = tenancyUserWithPermissions(['projects.create'], $organization, OrganizationMember::ROLE_OWNER);

    Project::factory()->create(['organization_id' => $organization->id]);

    tenancyResolveFor($user);

    $project = app(TenantProjectCreation::class)->create($user, [
        'name' => 'Second Paid Project',
        'status' => 'pending',
        'is_active' => true,
    ]);

    expect((string) $project->organization_id)->toBe((string) $organization->id);
});

it('forbids project creation for a user with no organization', function (): void {
    $user = tenancyUserWithPermissions(['projects.create']);

    tenancyResolveFor($user);

    expect(fn () => app(TenantProjectCreation::class)->create($user, [
        'name' => 'Orphan Project',
        'status' => 'pending',
        'is_active' => true,
    ]))->toThrow(HttpException::class, 'create an organization first');
});

it('lets a platform admin see legacy unassigned data', function (): void {
    $orgA = Organization::factory()->create();
    $admin = tenancyUserWithPermissions(['projects.view', 'clients.view']);
    $admin->update(['is_admin' => true]);

    $legacyProject = Project::factory()->create(['organization_id' => null]);
    $legacyClient = Client::factory()->create(['organization_id' => null]);
    $tenantProject = Project::factory()->create(['organization_id' => $orgA->id]);

    tenancyResolveFor($admin->fresh());

    expect(app(TenantContext::class)->isPlatformAdmin())->toBeTrue()
        ->and(Gate::forUser($admin->fresh())->allows('view', $legacyProject))->toBeTrue()
        ->and(Gate::forUser($admin->fresh())->allows('view', $tenantProject))->toBeTrue();

    $projectIds = Project::query()->pluck('id')->map(fn ($id): string => (string) $id)->all();
    $clientIds = Client::query()->pluck('id')->map(fn ($id): string => (string) $id)->all();

    expect($projectIds)->toContain((string) $legacyProject->id)
        ->and($clientIds)->toContain((string) $legacyClient->id);
});

it('keeps plan limits in one place', function (): void {
    expect(TenantLimits::limitsFor('trial'))->toBe(['max_projects' => 1])
        ->and(TenantLimits::limitsFor('paid'))->toBe(['max_projects' => null])
        ->and(TenantLimits::limitsFor('unknown-plan'))->toBe(['max_projects' => 1]);
});
