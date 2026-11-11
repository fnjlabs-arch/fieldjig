<?php

namespace App\Core\Tenancy\Services;

/**
 * Single source of truth for plan caps. Billing (§2) and signup (§3) must
 * reuse limitsFor() — do not hard-code the trial cap anywhere else.
 *
 * @phpstan-type PlanLimits array{max_projects: int|null}
 */
class TenantLimits
{
    /**
     * @return array{max_projects: int|null}
     */
    public static function limitsFor(string $plan): array
    {
        $limits = config('saas.limits.'.$plan);

        if (! is_array($limits)) {
            // Unknown plan: fail closed to the trial cap.
            return ['max_projects' => 1];
        }

        return [
            'max_projects' => $limits['max_projects'] ?? null,
        ];
    }
}
