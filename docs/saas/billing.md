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
| Paddle (`PaddleProvider`) | **Implemented — the live gateway.** Product "SadSha Build" + $99/mo price created 2026-11-23 (see go-live checklist). |
| Lemon Squeezy (`LemonSqueezyProvider`) | **Deprecated.** Store application rejected 2026-11-16. Kept only as a rollback path until Paddle is proven live; delete in a follow-up commit after cutover. |

### Paddle details (verified against Paddle docs, 2026-11-23)

- Checkout: the org's Paddle customer is resolved first (`POST
  /customers {email}` when the org has no `billing_customer_id` yet),
  then `POST /transactions {items: [{price_id, quantity: 1}],
  customer_id, custom_data: {organization_id}}`; the checkout URL is
  `data.checkout.url` (`<default payment link>?_ptxn=<txn id>`).
  The price carries **NO trial period** — the 30-day trial is handled
  in the app (org trialing at signup, 1-project cap). All Paddle API
  calls send `Authorization: Bearer <PADDLE_API_KEY>` and
  `Paddle-Version: 1`.
- Webhook signature: `Paddle-Signature` header,
  `ts=<unix>;h1=<hex>[;h1=...]`; signed payload is
  `ts + ':' + rawBody`, HMAC-SHA256 with the webhook secret,
  timing-safe compare against any `h1`, timestamps more than 300s old
  rejected (same algorithm as the Toolzo Paddle worker, §5.3).
  Event type comes from the payload `event_type` field. Verification
  runs over `$request->getContent()` before any JSON parsing.
- Customer portal: `POST /customers/{id}/portal-sessions`
  (optionally `{subscription_ids: [...]}`) returns
  `urls.general.overview` — a one-time, time-limited link; mint a
  fresh session per click (the billing panel mints one per page load),
  never cache it.
- Cancel: `POST /subscriptions/{id}/cancel
  {effective_from: "next_billing_period"}` — access runs to the end of
  the current period; the `subscription.canceled` webhook confirms.
  Local state is updated on the webhook, not on the cancel call.
- Webhook route: `POST /billing/webhook/paddle`, CSRF-exempt
  (`bootstrap/app.php` `validateCsrfTokens(except: [...])`) — authenticity
  comes from the HMAC, not a session token.

### Lemon Squeezy details (deprecated — kept for reference until deletion)

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
Paddle sends a per-delivery `event_id`, so the event id is
`"paddle:" + event_id` — an exact replay is skipped. Lemon Squeezy
sends no per-delivery id, so the event id is
`"lemonsqueezy:" + sha256(raw body)` — an exact replay is skipped, a
redelivery with different content is a new event. The unique index
makes the skip race-safe.

### Webhook → org mapping

Paddle events are matched to an org via
`data.custom_data.organization_id` first, then via
`data.customer_id` matching a stored `billing_customer_id`, then via
`data.subscription_id` matching a stored `billing_subscription_id`.
Unmatched deliveries return 200 and are logged (Paddle retries
non-2xx, so a transient mismatch must not 500).

| Paddle event | Org change |
| --- | --- |
| `subscription.created` / `subscription.updated` (`active`) | plan paid, status active, `past_due_at` cleared |
| `subscription.updated` (`trialing`) | plan trial, status trialing (defensive — the Paddle price has no trial) |
| `subscription.updated` / `subscription.past_due` (`past_due`) | status past_due, `past_due_at` set |
| `subscription.paused` (`paused`) | status past_due (the app has no paused state; a resume flips it back to active) |
| `subscription.canceled` (or status `canceled`) | status canceled |
| `transaction.completed` / `transaction.paid` (with `subscription_id`) | records `billing_customer_id` + `billing_subscription_id`, confirms active (a successful renewal cures past_due) |
| `transaction.completed` / `transaction.paid` (no `subscription_id`) | records `billing_customer_id` only; plan/status unchanged |
| `transaction.payment_failed` / `transaction.past_due` | status past_due, `past_due_at` set |

Lemon events are matched to an org via `meta.custom_data.organization_id`
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
`POST /billing/checkout` → provider checkout URL → redirect to the
provider. When the provider env is absent the button is **disabled with a
clear "billing not configured yet" state**. Cancel posts to
`POST /billing/cancel`. When the org has a Paddle customer id, a
"Manage billing" button opens the Paddle customer portal (one-time
link, minted per page load).

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
`BILLING_PROVIDER`, `PADDLE_API_KEY`, `PADDLE_PRICE_PRO`,
`PADDLE_WEBHOOK_SECRET`, `SADSHA_BUILD_PRICE_MONTHLY=99`,
`SADSHA_BUILD_TRIAL_DAYS=30`. Lemon vars (`LEMONSQUEEZY_*`) are
deprecated — kept only as a rollback path; remove after the Paddle
cutover.

## Domain (decided 2026-11-23)

Launch domain: **`build.toolzo.app`** (free subdomain of toolzo.app,
no purchase). `APP_URL=https://build.toolzo.app` on the VPS. The DNS A
record (`build.toolzo.app` → VPS IP) can only be created once the VPS
exists — on hold until ~24–25 Nov 2026. The Paddle webhook destination
is `https://build.toolzo.app/billing/webhook/paddle`.

## Go-live checklist (status)

1. ✅ **Paddle product + price created 2026-11-23** — product "SadSha
   Build" (`pro_01m843jze5pp0syw0c653709mc`), one price
   (`pri_01m843khh1rzqbppp09r4gx2jj`): **$99.00 USD, Recurring, billed
   Monthly, NO trial period, Active**, standard digital goods. Set
   `PADDLE_PRICE_PRO=pri_01m843khh1rzqbppp09r4gx2jj` on the VPS (the
   price id lives here in the docs and in the env var — never
   hard-coded in app code).
2. ⬜ Set `BILLING_PROVIDER=paddle` + the 3 `PADDLE_*` env vars on the
   VPS (API key + webhook secret via Secure Vault, never chat/files).
3. ⬜ Create the Paddle webhook notification destination
   (`https://build.toolzo.app/billing/webhook/paddle`, events
   `subscription.created/updated/canceled/past_due/paused/resumed` +
   `transaction.completed/paid/payment_failed`) — only after the domain
   is live over HTTPS.
4. ⬜ Paddle sandbox test-mode checkout + webhook end-to-end, then
   live keys.
5. ⬜ Verify the billing panel shows Upgrade (not "not configured")
   with `BILLING_PROVIDER=paddle`.
6. ⬜ Deploy to the shared Contabo VPS M, then a pilot builder on one
   live project.
7. ⬜ Delete the Lemon provider, route, config and env vars in a
   follow-up commit AFTER Paddle is proven live.

## Follow-ups (not v1)

- Scheduled `saas:expire-trials` artisan command (lazy transition in
  `ResolveTenant` covers v1).
- Full org-switcher UI (session-selected org works today).
- Invite email for `POST /organizations/{org}/members` (v1 requires
  the user to already exist).
- Delete the Lemon Squeezy provider, route, config and env vars after
  Paddle is proven live (see go-live checklist step 7).
- Dashboard banner for past-due orgs (the billing panel shows the
  past-due state today).
