<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SaaS mode
    |--------------------------------------------------------------------------
    |
    | When false (default) every tenancy enforcement in app/Core/Tenancy is a
    | no-op: no tenant scoping, no policy gate, no trial caps. Existing
    | single-tenant behaviour is unchanged. Set SAAS_MODE=1 to enforce.
    |
    */
    'mode' => (bool) env('SAAS_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | SadSha Build monthly price (USD)
    |--------------------------------------------------------------------------
    */
    'price_monthly' => (int) env('SADSHA_BUILD_PRICE_MONTHLY', 99),

    /*
    |--------------------------------------------------------------------------
    | Trial length (days)
    |--------------------------------------------------------------------------
    */
    'trial_days' => (int) env('SADSHA_BUILD_TRIAL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Plan limits
    |--------------------------------------------------------------------------
    |
    | The single source of truth for plan caps. Billing (§2) and signup (§3)
    | must reuse TenantLimits::limitsFor() instead of hard-coding values.
    | A null max_projects means unlimited.
    |
    */
    'limits' => [
        'trial' => [
            'max_projects' => 1,
        ],
        'paid' => [
            'max_projects' => null,
        ],
    ],
];
