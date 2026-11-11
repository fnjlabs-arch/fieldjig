<?php

namespace App\Core\Tenancy\Services;

use App\Core\Identity\Models\User;

/**
 * Policy-level tenant gate — the second enforcement layer (the first is the
 * TenantScope global scope on lists). Domain policies call canAccess()
 * before any isAdmin() shortcut: a tenant Admin *role* must never see
 * another org's data, and capability (role) and data visibility (tenancy)
 * are separate layers.
 *
 * isAdmin() audit (§1 design doc) — every isAdmin() usage in app/ was
 * reviewed 2026-11-11:
 * - Project/Client policies: gated here (this class). Safe.
 * - Project/Client list queries (User Projects Index, Dashboard Widget,
 *   Admin Projects Index): the TenantScope global scope already filters
 *   the query, so their isAdmin() early-returns cannot leak other orgs.
 * - Admin-area policies (User/Role/Setting/Queue/ScheduledTask, cPanel,
 *   Telescope gates): grant capability by isAdmin(), which INCLUDES the
 *   Admin role. FIXED in §2 (billing): in SaaS mode these gates require
 *   the raw users.is_admin platform flag — see
 *   TenantAuthorizer::platformAreaBefore() / ::isPlatformAreaAdmin().
 *   Org owners keep only their tenant-scoped surface (billing panel +
 *   org management). When SaaS mode is off, behaviour is unchanged.
 * - All other domains' records (Dailies, Timecards, Invoices, …) reach
 *   the org only through their project/client. They are NOT directly
 *   scoped in §1; their entry points are the project/client gates above.
 *   Direct per-domain scoping is follow-up work, not §1.
 */
class TenantAuthorizer
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Platform admin = the users.is_admin flag ONLY. Never User::isAdmin(),
     * which also returns true for the Admin role — tenant org owners hold
     * that role and must stay inside their own orgs.
     */
    public static function isPlatformAdmin(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Who may use the platform admin area (User/Role/Setting/Queue/
     * ScheduledTask policies, the "admin" gate, cPanel, Telescope)?
     *
     * In SaaS mode this is the platform operator ONLY (raw is_admin flag):
     * an org owner holds the Admin *role* (§4) and therefore holds every
     * permission key, so the role-based isAdmin() check would grant them
     * platform-wide screens. When SaaS mode is off, behaviour is
     * unchanged: isAdmin() (flag OR Admin role).
     */
    public static function isPlatformAreaAdmin(User $user): bool
    {
        if (! app(TenantContext::class)->isEnabled()) {
            return $user->isAdmin();
        }

        return self::isPlatformAdmin($user);
    }

    /**
     * "before" hook result for platform admin-area policies.
     *
     * Returns true for a platform-area admin, false for everyone else in
     * SaaS mode (outright deny — falling through to the permission checks
     * would still grant access, because an Admin-role holder holds all
     * 191 permission keys), and null when SaaS mode is off and the user
     * is not an admin (fall through to the per-ability permission
     * checks, as before).
     */
    public static function platformAreaBefore(User $user): ?bool
    {
        if (self::isPlatformAreaAdmin($user)) {
            return true;
        }

        if (app(TenantContext::class)->isEnabled()) {
            return false;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function organizationIdsFor(User $user): array
    {
        if (self::isPlatformAdmin($user)) {
            return [];
        }

        return $user->organizations()
            ->orderBy('organizations.created_at')
            ->pluck('organizations.id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * May this user access a record belonging to the given organization?
     * A null organization id means legacy/unassigned data: only the
     * platform admin may see it, never a tenant user.
     */
    public function canAccess(?string $organizationId, User $user): bool
    {
        if (! $this->context->isEnabled()) {
            return true;
        }

        if (self::isPlatformAdmin($user)) {
            return true;
        }

        if ($organizationId === null) {
            return false;
        }

        return in_array($organizationId, $this->organizationIdsFor($user), true);
    }

    /**
     * @param  object{organization_id: ?string}|null  $model
     */
    public function canAccessModel(?object $model, User $user): bool
    {
        if ($model === null) {
            return false;
        }

        return $this->canAccess($model->organization_id ?? null, $user);
    }

    public function authorize(?string $organizationId, User $user): void
    {
        abort_unless($this->canAccess($organizationId, $user), 403);
    }
}
