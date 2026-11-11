<?php

namespace App\Core\Tenancy\Database\Factories;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMember>
 */
class OrganizationMemberFactory extends Factory
{
    protected $model = OrganizationMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationMember::ROLE_MEMBER,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => OrganizationMember::ROLE_OWNER,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => OrganizationMember::ROLE_ADMIN,
        ]);
    }
}
