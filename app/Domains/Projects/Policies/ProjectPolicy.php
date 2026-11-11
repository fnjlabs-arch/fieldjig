<?php

namespace App\Domains\Projects\Policies;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Services\TenantAuthorizer;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\ProjectAccessService;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('projects.view')
            || $user->hasPermission('projects.view-all');
    }

    public function view(User $user, Project $project): bool
    {
        if (! $user->hasPermission('projects.view')) {
            return false;
        }

        // Tenancy gate first: a tenant Admin role must never see another
        // org's project, even though isAdmin() below includes that role.
        if (! app(TenantAuthorizer::class)->canAccess($project->organization_id, $user)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $projectAccessService = app(ProjectAccessService::class);

        if (! $projectAccessService->projectUsesScopedAccess($project)) {
            return true;
        }

        return $projectAccessService->hasScopedPermission($project, $user, 'projects.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        if (! $user->hasPermission('projects.edit')) {
            return false;
        }

        if (! app(TenantAuthorizer::class)->canAccess($project->organization_id, $user)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $projectAccessService = app(ProjectAccessService::class);

        if (! $projectAccessService->projectUsesScopedAccess($project)) {
            return true;
        }

        return $projectAccessService->hasScopedPermission($project, $user, 'projects.edit');
    }

    public function delete(User $user, Project $project): bool
    {
        if (! $user->hasPermission('projects.delete')) {
            return false;
        }

        if (! app(TenantAuthorizer::class)->canAccess($project->organization_id, $user)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $projectAccessService = app(ProjectAccessService::class);

        if (! $projectAccessService->projectUsesScopedAccess($project)) {
            return true;
        }

        return $projectAccessService->hasScopedPermission($project, $user, 'projects.delete');
    }

    public function viewFinancials(User $user, Project $project): bool
    {
        if (! $user->hasPermission('projects.view-financials')) {
            return false;
        }

        if (! app(TenantAuthorizer::class)->canAccess($project->organization_id, $user)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $projectAccessService = app(ProjectAccessService::class);

        if (! $projectAccessService->projectUsesScopedAccess($project)) {
            return true;
        }

        return $projectAccessService->hasScopedPermission($project, $user, 'projects.view');
    }
}
