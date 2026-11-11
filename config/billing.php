<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Billing provider
    |--------------------------------------------------------------------------
    |
    | Which billing gateway SadSha Build uses. Lemon Squeezy is the first
    | (temporary) implementation; Paddle is STUCK on proof of address and
    | is only a stub that throws — see PaddleProvider. Keep the gateway
    | swappable: all app code talks to BillingProvider, never to Lemon
    | Squeezy directly.
    |
    */
    'provider' => env('BILLING_PROVIDER', 'lemonsqueezy'),

    /*
    |--------------------------------------------------------------------------
    | Lemon Squeezy
    |--------------------------------------------------------------------------
    |
    | One $99/mo plan = one variant (LEMONSQUEEZY_VARIANT_PRO). Do NOT
    | create products/variants in code — the variant is created in the
    | Lemon dashboard AFTER the "Toolzo" store is approved (currently
    | "In Review", Test mode ON). Until the variant env is set, the
    | billing panel shows a "billing not configured yet" state.
    |
    */
    'lemonsqueezy' => [
        'api_key' => env('LEMONSQUEEZY_API_KEY'),
        'store_id' => env('LEMONSQUEEZY_STORE_ID'),
        'variant_pro' => env('LEMONSQUEEZY_VARIANT_PRO'),
        'webhook_secret' => env('LEMONSQUEEZY_WEBHOOK_SECRET'),
    ],
];
