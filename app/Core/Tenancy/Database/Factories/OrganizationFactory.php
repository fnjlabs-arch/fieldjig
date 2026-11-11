<?php

namespace App\Core\Tenancy\Database\Factories;

use App\Core\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Builders';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'plan' => Organization::PLAN_TRIAL,
            'status' => Organization::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(30),
            'billing_provider' => null,
            'billing_customer_id' => null,
            'billing_subscription_id' => null,
            'seats' => 5,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'plan' => Organization::PLAN_PAID,
            'status' => Organization::STATUS_ACTIVE,
            'trial_ends_at' => null,
        ]);
    }
}
