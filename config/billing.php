<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Billing provider
    |--------------------------------------------------------------------------
    |
    | Which billing gateway SadSha Build uses. Paddle is the live gateway
    | (Lemon Squeezy's store application was rejected 2026-11-16, so the
    | Lemon provider is deprecated and kept only as a rollback path).
    | Keep the gateway swappable: all app code talks to BillingProvider,
    | never to Paddle or Lemon Squeezy directly.
    |
    */
    'provider' => env('BILLING_PROVIDER', 'lemonsqueezy'),

    /*
    |--------------------------------------------------------------------------
    | Paddle
    |--------------------------------------------------------------------------
    |
    | One $99/mo plan = one price (PADDLE_PRICE_PRO, product "SadSha
    | Build", created in the Paddle dashboard — do NOT create
    | products/prices in code). The price carries NO trial period: the
    | 30-day trial is handled in the app (org trialing at signup,
    | 1-project cap). Until the price env is set, the billing panel
    | shows a "billing not configured yet" state.
    |
    */
    'paddle' => [
        'api_key' => env('PADDLE_API_KEY'),
        'price_pro' => env('PADDLE_PRICE_PRO'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lemon Squeezy (deprecated)
    |--------------------------------------------------------------------------
    |
    | DEPRECATED — the "Toolzo" store application was rejected
    | 2026-11-16. Kept only as a rollback path until Paddle is proven
    | live; delete the Lemon provider, route, config and env vars in a
    | follow-up commit after cutover.
    |
    */
    'lemonsqueezy' => [
        'api_key' => env('LEMONSQUEEZY_API_KEY'),
        'store_id' => env('LEMONSQUEEZY_STORE_ID'),
        'variant_pro' => env('LEMONSQUEEZY_VARIANT_PRO'),
        'webhook_secret' => env('LEMONSQUEEZY_WEBHOOK_SECRET'),
    ],
];
