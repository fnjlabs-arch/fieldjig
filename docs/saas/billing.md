# SadSha Build — Billing & signup (§2 + §3)

Product: **SadSha Build**, **$99/mo flat** (one variant, configurable via
`SADSHA_BUILD_PRICE_MONTHLY`, default 99), **30-day free trial on one
live project** (`SADSHA_BUILD_TRIAL_DAYS`, default 30), paid from month 2.

## Billing provider (swappable)

App code talks only to `App\Core\Billing\Contracts\BillingProvider`
(`createCheckout`, `handleWebhook`, `portalUrl`, `cancelSubscription`,
`isConfigured`), resolved by `BillingManager` off `BILLING_PROVIDER`
(default `lemonsqueezy`). Tests override the binding with a fake
provider — no live API calls in tests.

| Provider | State |
| --- | --- |
| Lemon Squeezy (`LemonSqueezyProvider`) | Implemented (§2). Temporary gateway. |
| Paddle (`PaddleProvider`) | **Stub only — throws "not built yet".** Paddle is STUCK on proof-of-address verification; do not build it until Paddle approves the seller account. |

### Lemon Squeezy details (verified against Lemon docs, 2026-11-11)

- Checkout: `POST https://api.lemonsqueezy.com/v1/checkouts` (JSON:API),
  relationships store + variant, `checkout_data.custom.organization_id`
  (returned in webhook `meta.custom_data`), `product_options.redirect_url`
  back to the billing panel. The checkout URL is `data.attributes.url`.
- Webhook signature: `X-Signature` header, HMAC-SHA256 **hex digest of the
  raw request body** keyed with the webhook signing secret; event name in
  `X-Event-Name`. Verification runs over `$request->getContent()` before
  any JSON parsing (re-serializing would change the bytes).
- Cancel: `DELETE /v1/subscriptions/{id}`.
- Lemon Squeezy has **no customer self-serve billing portal**, so
  `portalUrl()` returns null; cancellation happens in the billing panel.
- Webhook route: `POST /billing/webhook/lemonsqueezy`, CSRF-exempt
  (`bootstrap/app.php` `validateCsrfTokens(except: [...])`) — authenticity
  comes from the HMAC, not a session token.

### Idempotency

`billing_webhook_events` (provider, `event_id` unique, `processed_at`).
Lemon Squeezy sends no per-delivery id, so the event id is
`"lemonsqueezy:" + sha256(raw body)` — an exact replay is skipped, a
redelivery with different content is a new event. The unique index
makes the skip race-safe.

### Webhook → org mapping

| Lemon event | Org change |
| --- | --- |
| `subscription_created` / `subscription_updated` (`on_trial`) | plan trial, status trialing |
| `subscription_updated` (`active`) | plan paid, status active, `past_due_at` cleared |
| `subscription_updated` (`past_due` / `unpaid`) | status past_due, `past_due_at` set |
| `subscription_cancelled` / `subscription_expired` (or status `cancelled` / `expired`) | status canceled |
| `order_created` | records `billing_customer_id` only; plan/status unchanged |

Events are matched to an org via `meta.custom_data.organization_id`
first, then via `data.attributes.customer_id` matching a stored
`billing_customer_id`. Unmatched deliveries return 200 and are logged
(Lemon retries non-2xx, so a transient mismatch must not 500).

## Subscription state machine

```
trialing (30 days) → active (paid) → past_due → canceled
```

- **trialing** — 30-day trial, 1-project cap (`TenantLimits::limitsFor('trial')`,
  the single source of truth — never hard-code the cap).
- **Trial expiry without payment → past_due.** Implemented lazily in
  `ResolveTenant` (the tenant middleware): on the first request after
  `trial_ends_at` passes, status becomes `past_due` and `past_due_at` is
  set. A scheduled `saas:expire-trials` artisan command is a documented
  follow-up, not v1.
- **past_due** — no new projects (`TenantProjectCreation` 403s), banner on
  the billing panel, **7-day grace** from `past_due_at`. After 7 days the
  lazy transition moves the org to `canceled`.
- **canceled** — no app access except the billing panel
  (`EnsureSubscriptionNotCanceled` middleware, after `ResolveTenant` in
  the web group: GETs redirect to `/billing`; billing/auth/email/Livewire
  routes and non-GET requests pass through; platform `is_admin` users
  bypass entirely).

Billing panel: Livewire `billing::panel` (`GET /billing`, auth only — no
`verified` middleware, so a fresh trial user can pay before verifying
email). Shows plan / status / trial days left / price. Upgrade posts to
`POST /billing/checkout` → provider checkout URL → redirect to Lemon.
When the Lemon variant env is absent the button is **disabled with a
clear "billing not configured yet" state**. Cancel posts to
`POST /billing/cancel`.

## Signup (§3)

Fortify registration stays **OFF** (`Features::registration()` remains
commented out). SaaS signup is its own flow:

- `GET /signup` — Volt single-file component
  (`resources/views/livewire/signup.blade.php`, Livewire-first).
- `POST /signup` (guest, `throttle:5,1`) — `{name, email, password,
  password_confirmation, company_name}` → `SignupService::register()`:
  creates the User **reusing `CreateNewUser`** (same validation rules;
  the name is split into first/last and a unique username is derived
  from the email), a trial Organization (plan `trial`, status `trialing`,
  `trial_ends_at` = now + trial days), the owner `OrganizationMember`,
  and assigns the built-in **Admin role** (§4). The owner does **NOT**
  get the `is_admin` platform flag. Logs the user in, redirects to the
  dashboard.
  - Note: a fresh user has not verified their email yet, and the
    dashboard requires `verified` — they land on the email-verification
    notice until they verify (Fortify email verification is ON by
    design). The billing panel itself needs only `auth`.
- `POST /organizations` (auth) — create an additional org (owner =
  caller); the new org becomes the session-selected tenant.
- `GET /organizations/mine` (auth) — the caller's orgs with
  plan/status/trial_ends_at/role (JSON).
- `POST /organizations/{org}/members` (org owner/admin) — add a member
  by email (user must exist; creates only the `OrganizationMember` —
  **no** project memberships are auto-added, **no** invite email in v1).
- Org switcher: minimal — the session-selected org is used by
  `ResolveTenant`; a full switcher UI is a follow-up.

## Platform admin-area security fix (§1 follow-up, §2)

`User::isAdmin()` is true for anyone holding the Admin role, and §4
makes every org owner an Admin-role holder. In **SaaS mode** the
platform admin area now requires the raw `users.is_admin` flag
(platform operator only) — see `TenantAuthorizer::isPlatformAreaAdmin()`
/ `::platformAreaBefore()`. When `SAAS_MODE=0`, behaviour is unchanged.

Gated list (all deny a non-platform user in SaaS mode, even though an
Admin-role holder holds all 191 permission keys — the permission
fallbacks are deliberately not consulted in SaaS mode):

| Surface | Enforcement point |
| --- | --- |
| User policy (`App\Core\Auth\User\Policies\UserPolicy` + `App\Core\Identity\Policies\UserPolicy`) | `before()` → `platformAreaBefore()` (false in SaaS mode) |
| Role policy | `before()` → `platformAreaBefore()` |
| Setting policy | `before()` → `platformAreaBefore()` |
| Queue policy | `before()` + `viewAny`/`manage` bodies (the `queue.viewAny`/`queue.manage` gates call the methods directly, bypassing `before()`) |
| ScheduledTask policy | `before()` → `platformAreaBefore()` |
| `admin` gate | `AuthUserServiceProvider` → `isPlatformAreaAdmin()` |
| cPanel gate (`manage-email-accounts`) | `CpanelServiceProvider` → platform flag only in SaaS mode |
| Telescope gate (`viewTelescope`) | `TelescopeServiceProvider::canAccessTelescope()` |

The org owner's admin surface stays open: the billing panel + org
management (members) are tenant-scoped. The platform-admin nav entries
(Users, Settings, Scheduler, Queue) hide automatically — they are driven
by the same `admin` gate / policies. No org-scoped admin screens are
built in v1 (out of scope).

Tests: `app/Core/Tenancy/Tests/Feature/PlatformAdminAreaTest.php`
(gates + policies + HTTP 403 for an org owner / 200 for a platform
admin + `SAAS_MODE=0` unchanged).

## Env vars

Already in `.env.example` (verify, do not duplicate): `SAAS_MODE`,
`BILLING_PROVIDER`, `LEMONSQUEEZY_API_KEY`, `LEMONSQUEEZY_STORE_ID`,
`LEMONSQUEEZY_VARIANT_PRO`, `LEMONSQUEEZY_WEBHOOK_SECRET`,
`SADSHA_BUILD_PRICE_MONTHLY=99`, `SADSHA_BUILD_TRIAL_DAYS=30`.

## Go-live checklist (status)

1. ⏳ **BLOCKER — Lemon Squeezy approves the "Toolzo" store**
   (currently "In Review", Test mode ON). Live billing cannot activate
   until then. The code works in test mode.
2. ⬜ Create the SadSha Build product + **one variant (Pro $99/mo)** in
   the Lemon dashboard (do NOT create products/variants in code).
3. ⬜ Set the 4 `LEMONSQUEEZY_*` env vars on the VPS.
4. ⬜ Create the webhook in Lemon (subscription_created/updated/
   cancelled/expired, order_created) with a signing secret.
5. ⬜ Test-mode checkout + webhook end-to-end, then live keys.
6. ⬜ Deploy to the shared Contabo VPS 8, then a pilot builder on one
   live project.

## Follow-ups (not v1)

- Scheduled `saas:expire-trials` artisan command (lazy transition in
  `ResolveTenant` covers v1).
- Full org-switcher UI (session-selected org works today).
- Invite email for `POST /organizations/{org}/members` (v1 requires
  the user to already exist).
- Paddle provider (only after Paddle approves the seller account).
- Dashboard banner for past-due orgs (the billing panel shows the
  past-due state today).
