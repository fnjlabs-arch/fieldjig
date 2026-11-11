# SadSha Build — Onboarding permission model (§4)

Fixes Cleaner caveat #1: the built-in **User** role held **0 of 191
permissions**, so a plain user got 403 on every core page, including
"My Timecards" / "My Dailies".

## Root cause

No domain permission definition set `built_in_roles`, so
`PermissionRegistry::builtInRolePermissions()['User']` was empty and
`DomainPermissionSynchronizer` left the built-in User role with zero
permissions. The Admin role is hard-synced to all permissions on every
sync; that part already worked.

## Fix (uses the codebase's own seam — no new mechanism)

`'built_in_roles' => ['User']` was added to 23 permission definitions
in each domain's `Permissions/*.php` (see list below). The
synchronizer's `syncWithoutDetaching` for the User role makes this
**additive**: an admin's per-role customizations are preserved, and
later definition changes only add the new defaults.

## How sync is triggered

`PermissionServiceProvider::boot()` registers the foundation
permissions, and each domain provider registers its own; then

```php
$this->app->booted(fn () => $synchronizer->syncIfChanged());
```

runs `DomainPermissionSynchronizer::syncIfChanged()` **on every app
boot**. It hashes the definitions + built-in grants and syncs only
when the hash changed (cache key
`permissions.domain-definitions.hash`), so a deploy picks the new
defaults up automatically — no artisan call needed. `sync()` itself
is also callable directly (tests do this). After a sync it flushes
role caches and calls `User::bumpPermissionCacheVersion()` so the
authorization snapshot (union of the user's active roles'
permissions, read by `User::hasPermission()`) is rebuilt.

Note for existing installs: because the User sync is additive,
orgs that already exist keep whatever an admin granted them, and
gain these 23 defaults on the next boot after deploy.

## Default member set (23 keys)

A field member can see their own org's projects / timecards /
dailies / tasks, create and edit their own timecards and dailies, and
view documents. The set was derived from the actual permission
classes and the policies / Livewire mounts that consume them (e.g.
"My Timecards" authorizes `viewAny` on `Timecard`, which is
`timecards.view` OR `timecards.view-all` — `view` alone opens it;
`view` on a single record is additionally scoped to the owner).

| Area | Keys |
| --- | --- |
| Announcements | `announcements.view` (dashboard announcements) |
| Clients | `clients.view` (read-only; which client a project belongs to) |
| Dailies | `dailies.view`, `dailies.create`, `dailies.edit`, `dailies.submit` |
| Documents | `documents.view` |
| Plans | `plans.view`, `plans.compare`, `plans.annotate` (own annotations only; `plans.manage-annotations` stays admin) |
| Projects | `projects.view`, `projects.view-documents` |
| Task categories | `task-categories.view` |
| Tasks | `tasks.view`, `tasks.create`, `tasks.edit`, `tasks.edit-status`, `tasks.edit-progress`, `tasks.edit-notes` |
| Timecards | `timecards.view`, `timecards.create`, `timecards.edit`, `timecards.submit` |

Notes on the boundaries:

- `tasks.edit` already implies the sub-edits in `TaskPolicy`
  (`updateStatus` etc. accept `edit` OR the sub-key); the sub-keys
  are granted explicitly so the role reads as intended and survives
  a future tightening of `edit`. `tasks.edit-priority` and
  `tasks.edit-assignee` are NOT granted as keys — but be aware they
  are currently reachable through `tasks.edit` alone in the policy,
  so reassigning / reprioritizing is an admin-grant decision, not
  something this list blocks.
- `projects.view` also covers address access
  (`AddressAccessService::canViewAny` accepts `projects.view`), so
  no separate `addresses.view` grant is needed.
- `documents.view` plus `projects.view-documents` covers both the
  Documents index and the Documents tab on a project.

## Deliberately NOT granted

- `*-view-all` (`projects.view-all`, `timecards.view-all`,
  `dailies.view-all`) — a member sees only their own records /
  assigned projects, never the whole company.
- Financials: `projects.view-financials`, all `payroll-*`,
  `financial-reports.*`, `operational-reports.*`.
- All `delete`, `approve`, `reject` keys (incl.
  `timecards.approve`, `dailies.approve`, `change-orders.approve`).
- `projects.create` / `projects.edit` / `projects.delete` —
  projects are created by the org owner/admin (trial cap: 1 project,
  enforced in §1).
- **Invoices + RFIs stay admin-only**: no `invoices.*`, no `rfis.*`.
  There are no user-level invoice/RFI routes; in a small builder the
  owner is the admin. Do not invent user-level invoice/RFI access.
- Change orders, submittals, stock orders: no keys. A member does
  not need them for daily field work; an admin can grant
  e.g. `change-orders.view` per role if a foreman needs it.
- Admin areas: `users.*`, `roles.*`, `settings.*`,
  `navigation.view-admin`, `project-access.*`, `audit-logs.*`,
  `scheduler.*`, `queue.*`, `cpanel.*`.
- `payroll-stubs.view-own` (own pay stub) is NOT in the default set
  even though it is own-only: pay is financial data and the §4
  principle is "NOT financials". An admin can grant it per role;
  revisit if pilot builders ask for self-service pay stubs.
- `documents.create` / `documents.update` / `projects.upload-documents`:
  members view documents; uploads stay with the admin/foreman in v1.

## First user of a new org = Admin role

The signup flow (§3) assigns the built-in **Admin** role to the org
owner. That role already syncs to **all 191 permissions** on every
sync — the role system supports it with no change from §4. Org
owners must **NOT** get `is_admin=true`: that flag is for the
platform (SaaS) operator only (`User::isAdmin()` = flag OR Admin
role). Capability (role) and data visibility (tenancy, §1) are
separate layers; the §1 audit of `isAdmin()` usages is what makes
giving tenants the Admin role safe.

## Verification (2026-11-11, branch `remix/fieldjig-permissions`)

- `php artisan tinker`: registry holds 191 permissions;
  `builtInRolePermissions()['User']` holds the 23 keys above.
- `app/Core/Auth/Permission/Tests/Feature/OnboardingPermissionsTest.php`
  (5 tests, 28 assertions): registry set is exactly the 23 keys;
  after `sync()` the User role holds exactly them and Admin holds
  all 191; HTTP — a User-role member gets 200 on `/timecards`,
  `/dailies`, `/projects`, `/tasks`, `/documents`, `/dashboard` and
  403 on `/admin/invoices`, `/admin/rfis`, `/admin/users`; a member
  can view/submit their own timecard, cannot view another member's,
  cannot approve, and cannot `viewAny` invoices/RFIs; an
  Admin-role user with `is_admin=false` is admin and holds
  `invoices.view`, `rfis.view`, `projects.view-financials`.
- Existing permission/role tests still pass:
  `PermissionRegistryTest`, `DomainPermissionSynchronizerTest`,
  `RolePermissionSyncTest`, `SprintZeroPermissionMatrixTest`
  (14 tests, 52 assertions).

## Source of truth

If this list changes, update the test's
`onboardingMemberPermissions()` in the same commit — the test fails
otherwise. Product decisions: "SadSha Build", $99/mo flat, 30-day
free trial on one live project.
