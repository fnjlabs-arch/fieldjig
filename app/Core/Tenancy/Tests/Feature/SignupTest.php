<?php

use App\Core\Auth\Role\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use App\Core\Tenancy\Services\TenantContext;
use App\Core\Tenancy\Services\TenantProjectCreation;
use App\Domains\Projects\Models\ProjectUserAccess;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config()->set('saas.mode', true);
    app(TenantContext::class)->clear();
});

/**
 * @return array<string, mixed>
 */
function signupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jamie Builder',
        'email' => 'jamie@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'company_name' => 'Jamie Builders',
    ], $overrides);
}

it('renders the signup page', function (): void {
    $this->get(route('signup'))->assertOk()->assertSee('Create your SadSha Build account');
});

it('signs up a user with a trial org, owner membership and the admin role', function (): void {
    $response = $this->post(route('signup.store'), signupPayload());

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'jamie@example.com')->firstOrFail();

    expect($user->is_admin)->toBeFalse()
        ->and(Hash::check('Password123!', $user->password))->toBeTrue()
        ->and($user->first_name)->toBe('Jamie')
        ->and($user->last_name)->toBe('Builder');

    $organization = Organization::query()->where('name', 'Jamie Builders')->firstOrFail();

    expect($organization->plan)->toBe(Organization::PLAN_TRIAL)
        ->and($organization->status)->toBe(Organization::STATUS_TRIALING)
        ->and($organization->trial_ends_at)->not->toBeNull()
        ->and(now()->diffInDays($organization->trial_ends_at))->toBeGreaterThanOrEqual(29);

    $membership = OrganizationMember::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->role)->toBe(OrganizationMember::ROLE_OWNER);

    // Admin *role* (§4), not the is_admin platform flag.
    expect($user->fresh()->isAdmin())->toBeTrue()
        ->and($user->roles()->where('name', Role::BUILT_IN_ADMIN)->exists())->toBeTrue();
});

it('signs up through the volt signup form', function (): void {
    Livewire::test('signup')
        ->set('name', 'Volt Builder')
        ->set('email', 'volt@example.com')
        ->set('company_name', 'Volt Builders')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->call('signup')
        ->assertRedirect(route('dashboard'));

    expect(User::query()->where('email', 'volt@example.com')->exists())->toBeTrue()
        ->and(Organization::query()->where('name', 'Volt Builders')->exists())->toBeTrue();
});

it('rejects signup with a duplicate email or missing company', function (): void {
    User::factory()->create(['email' => 'jamie@example.com']);

    $this->post(route('signup.store'), signupPayload())
        ->assertSessionHasErrors('email');

    $this->post(route('signup.store'), signupPayload(['email' => 'other@example.com', 'company_name' => '']))
        ->assertSessionHasErrors('company_name');
});

it('enforces the trial 1-project cap end to end through signup', function (): void {
    $this->post(route('signup.store'), signupPayload())->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'jamie@example.com')->firstOrFail();
    $organization = $user->organizations()->firstOrFail();

    app(TenantContext::class)->setOrganizationIds([$organization->id], $organization->id);

    $project = app(TenantProjectCreation::class)->create($user, [
        'name' => 'First Trial Project',
        'status' => 'pending',
        'is_active' => true,
    ]);

    expect((string) $project->organization_id)->toBe((string) $organization->id);

    expect(fn () => app(TenantProjectCreation::class)->create($user, [
        'name' => 'Second Trial Project',
        'status' => 'pending',
        'is_active' => true,
    ]))->toThrow(HttpException::class, 'Trial limit reached');
});

it('creates an additional organization for an authenticated user', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->post(route('organizations.store'), ['name' => 'Second Builders'])
        ->assertRedirect(route('dashboard'));

    $organization = Organization::query()->where('name', 'Second Builders')->firstOrFail();

    expect($organization->plan)->toBe(Organization::PLAN_TRIAL)
        ->and($organization->status)->toBe(Organization::STATUS_TRIALING)
        ->and($organization->members()->where('user_id', $user->id)->value('role'))
        ->toBe(OrganizationMember::ROLE_OWNER);
});

it('lists the caller organizations with plan, status and trial end', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $organization = Organization::factory()->create();

    OrganizationMember::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationMember::ROLE_MEMBER,
    ]);

    $this->actingAs($user)
        ->getJson(route('organizations.mine'))
        ->assertOk()
        ->assertJsonPath('organizations.0.id', $organization->id)
        ->assertJsonPath('organizations.0.plan', Organization::PLAN_TRIAL)
        ->assertJsonPath('organizations.0.status', Organization::STATUS_TRIALING)
        ->assertJsonPath('organizations.0.role', OrganizationMember::ROLE_MEMBER);
});

it('lets an org owner add a member by email without project access', function (): void {
    $owner = User::factory()->create(['is_admin' => false]);
    $member = User::factory()->create(['is_admin' => false]);
    $organization = Organization::factory()->create();

    OrganizationMember::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationMember::ROLE_OWNER,
    ]);

    $this->actingAs($owner)
        ->postJson(route('organizations.members.store', $organization), [
            'email' => $member->email,
            'role' => OrganizationMember::ROLE_MEMBER,
        ])
        ->assertCreated()
        ->assertJsonPath('role', OrganizationMember::ROLE_MEMBER);

    expect(OrganizationMember::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->exists())->toBeTrue()
        // No project memberships are auto-added.
        ->and(ProjectUserAccess::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

it('forbids adding members for non-managers and unknown emails', function (): void {
    $owner = User::factory()->create(['is_admin' => false]);
    $outsider = User::factory()->create(['is_admin' => false]);
    $organization = Organization::factory()->create();

    OrganizationMember::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationMember::ROLE_OWNER,
    ]);

    $this->actingAs($outsider)
        ->postJson(route('organizations.members.store', $organization), ['email' => $owner->email])
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('organizations.members.store', $organization), ['email' => 'nobody@example.com'])
        ->assertUnprocessable();
});
