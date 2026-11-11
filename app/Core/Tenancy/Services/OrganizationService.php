<?php

namespace App\Core\Tenancy\Services;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Organization management for SaaS signup (§3) and the org endpoints.
 * Creating an org always creates the owner membership; it never
 * touches project memberships (those are per-project, §1).
 */
class OrganizationService
{
    public function createOrganization(User $owner, string $name, string $plan = Organization::PLAN_TRIAL): Organization
    {
        return DB::transaction(function () use ($owner, $name, $plan): Organization {
            $organization = Organization::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'plan' => $plan,
                'status' => $plan === Organization::PLAN_PAID
                    ? Organization::STATUS_ACTIVE
                    : Organization::STATUS_TRIALING,
                'trial_ends_at' => $plan === Organization::PLAN_TRIAL
                    ? now()->addDays((int) config('saas.trial_days', 30))
                    : null,
            ]);

            $this->addMember($organization, $owner, OrganizationMember::ROLE_OWNER);

            return $organization;
        });
    }

    public function addMember(Organization $organization, User $user, string $role = OrganizationMember::ROLE_MEMBER): OrganizationMember
    {
        if (! in_array($role, OrganizationMember::ROLES, true)) {
            $role = OrganizationMember::ROLE_MEMBER;
        }

        return OrganizationMember::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            ['role' => $role],
        );
    }

    /**
     * May this user manage the org (members, billing)? Platform admins
     * always can; otherwise only org owners and org admins.
     */
    public function userCanManage(User $user, Organization $organization): bool
    {
        if (TenantAuthorizer::isPlatformAdmin($user)) {
            return true;
        }

        $role = $user->organizations()
            ->where('organizations.id', $organization->id)
            ->value('organization_members.role');

        return in_array($role, [OrganizationMember::ROLE_OWNER, OrganizationMember::ROLE_ADMIN], true);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Organization::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
