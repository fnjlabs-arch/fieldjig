<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use App\Core\Tenancy\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organization endpoints (§3): create an additional org, list the
 * caller's orgs, add a member by email. Adding a member creates only
 * the OrganizationMember — it does NOT auto-add project memberships,
 * and there is no invite email in v1 (the user must already exist).
 */
class OrganizationController
{
    public function __construct(private readonly OrganizationService $organizations) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $organization = $this->organizations->createOrganization($user, $validated['name']);

        $request->session()->put('tenant_organization_id', $organization->id);

        return redirect()->route('dashboard')
            ->with('status', 'Organization "'.$organization->name.'" created.');
    }

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organizations = $user->organizations()
            ->orderBy('organizations.created_at')
            ->get()
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'plan' => $organization->plan,
                'status' => $organization->status,
                'trial_ends_at' => $organization->trial_ends_at?->toDateTimeString(),
                'role' => $organization->pivot->role ?? null,
            ])
            ->all();

        return response()->json(['organizations' => $organizations]);
    }

    public function addMember(Request $request, Organization $organization): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->organizations->userCanManage($user, $organization)) {
            abort(403, 'Only an organization owner or admin can add members.');
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', Rule::exists(User::class, 'email')],
            'role' => ['nullable', 'string', Rule::in([OrganizationMember::ROLE_MEMBER, OrganizationMember::ROLE_ADMIN])],
        ]);

        $member = User::query()->where('email', $validated['email'])->firstOrFail();

        $membership = $this->organizations->addMember(
            $organization,
            $member,
            $validated['role'] ?? OrganizationMember::ROLE_MEMBER,
        );

        return response()->json([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'email' => $member->email,
            'role' => $membership->role,
        ], 201);
    }
}
