<?php

namespace App\Core\Tenancy\Services;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectUserAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The project create path under SaaS mode. Resolves the org from the
 * tenant context, enforces the trial project cap (via TenantLimits, the
 * single source of truth), and makes the creator a project admin through
 * ProjectUserAccess, as the create form does for scoped access today.
 *
 * When SaaS mode is off this is a plain create with no org attached.
 */
class TenantProjectCreation
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $creator, array $attributes): Project
    {
        if (! $this->context->isEnabled()) {
            return Project::query()->create($attributes);
        }

        $organizationId = $this->context->currentOrganizationId();

        if ($organizationId === null) {
            throw new HttpException(403, 'You must create an organization first before creating a project.');
        }

        $organization = Organization::query()->findOrFail($organizationId);

        // SaaS billing (§2): past_due orgs create no new projects
        // (7-day grace is for paying, not for growing); canceled orgs
        // create nothing at all.
        if ($organization->status === Organization::STATUS_PAST_DUE) {
            throw new HttpException(403, 'Your subscription is past due. Upgrade in Billing to add more projects.');
        }

        if ($organization->status === Organization::STATUS_CANCELED) {
            throw new HttpException(403, 'Your subscription was canceled. Reactivate it in Billing to add more projects.');
        }

        $maxProjects = TenantLimits::limitsFor($organization->plan)['max_projects'];

        if ($maxProjects !== null && $organization->projectCount() >= $maxProjects) {
            throw new HttpException(
                403,
                'Trial limit reached: trial organizations can own at most '.$maxProjects.' project'.($maxProjects === 1 ? '' : 's').'. Upgrade to add more projects.'
            );
        }

        $project = Project::query()->create(array_merge($attributes, [
            'organization_id' => $organization->id,
        ]));

        ProjectUserAccess::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'user_id' => $creator->id,
            ],
            [
                'granted_by' => $creator->id,
                'permission_keys' => ['projects.view', 'projects.edit', 'projects.delete'],
            ],
        );

        return $project;
    }
}
