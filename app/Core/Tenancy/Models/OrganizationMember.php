<?php

namespace App\Core\Tenancy\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Database\Factories\OrganizationMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMember extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_MEMBER,
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
    ];

    protected static function newFactory(): OrganizationMemberFactory
    {
        return OrganizationMemberFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }
}
