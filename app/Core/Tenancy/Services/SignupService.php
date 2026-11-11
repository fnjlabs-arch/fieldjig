<?php

namespace App\Core\Tenancy\Services;

use App\Core\Auth\Permission\Services\DomainPermissionSynchronizer;
use App\Core\Auth\Role\Models\Role;
use App\Core\Identity\Actions\Fortify\CreateNewUser;
use App\Core\Identity\Concerns\PasswordValidationRules;
use App\Core\Identity\Concerns\ProfileValidationRules;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * SaaS signup (§3). Fortify registration stays OFF — this is the only
 * self-serve registration path.
 *
 * POST /signup {name, email, password (+ confirmation), company_name}
 * creates the User (reusing CreateNewUser, so the validation rules are
 * the same as the app's own user creation), a trial Organization owned
 * by that user (plan "trial", status "trialing", trial_ends_at = now +
 * the configured trial days), the owner OrganizationMember, and
 * assigns the built-in Admin role (§4) to the owner. The owner does
 * NOT get the is_admin platform flag.
 */
class SignupService
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly OrganizationService $organizations) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, organization: Organization}
     */
    public function register(array $input): array
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
            'company_name' => ['required', 'string', 'max:255'],
        ])->validate();

        // NOTE: validated() drops password_confirmation (it has no
        // explicit rule — only the `confirmed` rule on password), so
        // carry it from the raw input for CreateNewUser's re-validation.
        $passwordConfirmation = $input['password_confirmation'] ?? null;

        return DB::transaction(function () use ($validated, $passwordConfirmation): array {
            $nameParts = preg_split('/\s+/', trim((string) $validated['name'])) ?: [];
            $firstName = $nameParts[0] ?? (string) $validated['name'];
            $lastName = count($nameParts) > 1
                ? implode(' ', array_slice($nameParts, 1))
                : $firstName;

            $user = app(CreateNewUser::class)->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => null,
                'username' => $this->uniqueUsername((string) $validated['email']),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $passwordConfirmation,
            ]);

            $organization = $this->organizations->createOrganization(
                $user,
                (string) $validated['company_name'],
                Organization::PLAN_TRIAL,
            );

            $this->assignAdminRole($user);

            return ['user' => $user, 'organization' => $organization];
        });
    }

    /**
     * The first user of a new org is an Admin (§4): all permissions via
     * the existing sync. This is the Admin *role*, not the is_admin
     * platform flag.
     */
    private function assignAdminRole(User $user): void
    {
        app(DomainPermissionSynchronizer::class)->sync();

        $adminRole = Role::query()
            ->where('name', Role::BUILT_IN_ADMIN)
            ->where('built_in', true)
            ->first();

        if ($adminRole === null) {
            return;
        }

        $user->roles()->syncWithoutDetaching([$adminRole->id]);
        $user->flushAuthorizationCache();
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::slug(Str::before($email, '@')) ?: 'user';
        $username = $base;
        $suffix = 2;

        while (User::query()->where('username', $username)->exists()) {
            $username = $base.'-'.$suffix;
            $suffix++;
        }

        return $username;
    }
}
