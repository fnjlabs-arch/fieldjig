<?php

namespace App\Core\Tenancy\Scopes;

use App\Core\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * First enforcement layer: filters Project and Client lists to the caller's
 * orgs. Applied only when SAAS_MODE=1 and a tenant context is set.
 * Platform is_admin users bypass it (they see legacy/unassigned data);
 * a user with no org sees nothing.
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isEnabled()) {
            return;
        }

        if ($context->isPlatformAdmin()) {
            return;
        }

        $organizationIds = $context->organizationIds();

        if ($organizationIds === []) {
            // No tenant context: no rows. Legacy (NULL) rows belong to no org.
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->whereIn($model->qualifyColumn('organization_id'), $organizationIds);
    }
}
