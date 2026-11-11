<?php

namespace App\Core\Tenancy\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Database\Factories\OrganizationFactory;
use App\Core\Tenancy\Services\TenantLimits;
use App\Domains\Clients\Models\Client;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant of SadSha Build. One organization owns its clients and projects;
 * users belong to organizations through OrganizationMember.
 *
 * The platform operator is NOT an organization member: platform access is
 * the users.is_admin flag only. Org owners must never get is_admin=true.
 */
class Organization extends Model
{
    use HasFactory, HasUlids;

    public const PLAN_TRIAL = 'trial';

    public const PLAN_PAID = 'paid';

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'name',
        'slug',
        'plan',
        'status',
        'trial_ends_at',
        'past_due_at',
        'billing_provider',
        'billing_customer_id',
        'billing_subscription_id',
        'seats',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'past_due_at' => 'datetime',
            'seats' => 'integer',
        ];
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'organization_members',
            'organization_id',
            'user_id',
        )->withPivot('role')->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function isTrial(): bool
    {
        return $this->plan === self::PLAN_TRIAL;
    }

    /**
     * @return array{max_projects: int|null}
     */
    public function limits(): array
    {
        return TenantLimits::limitsFor($this->plan);
    }

    public function projectCount(): int
    {
        return $this->projects()->count();
    }

    public function canCreateProject(): bool
    {
        $maxProjects = $this->limits()['max_projects'];

        if ($maxProjects === null) {
            return true;
        }

        return $this->projectCount() < $maxProjects;
    }
}
