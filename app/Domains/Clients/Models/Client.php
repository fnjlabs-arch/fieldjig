<?php

namespace App\Domains\Clients\Models;

use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Scopes\TenantScope;
use App\Domains\Addresses\Models\Address;
use App\Domains\Clients\Database\Factories\ClientFactory;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperClient
 */
class Client extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'company_name',
        'contact_name',
        'email',
        'phone',
        'mobile',
        'notes',
        'is_active',
        'organization_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // SaaS tenancy (§1): no-op unless SAAS_MODE=1 with a tenant context.
        static::addGlobalScope(new TenantScope);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Route bindings resolve WITHOUT the TenantScope so that direct access
     * to another org's client hits the ClientPolicy tenant gate (403)
     * instead of vanishing as a 404. Lists still use the scope.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery(
            $query->withoutGlobalScope(TenantScope::class),
            $value,
            $field,
        );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}
