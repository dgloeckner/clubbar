<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain;

use App\Modules\AdminUsers\Enums\AdminRole;

/**
 * Which roles may reach which route (ADR-0044, #519).
 *
 * One declarative table, consulted by `AdminSessionAuth`. Not per-controller
 * checks: those scatter the model across sixty call sites, fail *open* by
 * omission — a controller with no check is simply open, and nothing surfaces
 * that — and cannot be verified by a single test over the route table, which is
 * the property that keeps this design true a year from now.
 *
 * ## Three rules
 *
 * 1. **Default-deny.** A route with no entry here is `admin`-only. When
 *    somebody adds an admin route six months from now and does not touch this
 *    file, it works for `admin` and answers 403 for everyone else until a human
 *    deliberately grants it. The failure mode becomes "a Kassenwart cannot
 *    reach the new page" — visible, reported, fixed in a minute.
 * 2. **Grants are additive.** There are no deny rules. Holding two roles is the
 *    union of both, which is routine by design: a Kassenwart covering the
 *    drinks list holds `getraenkewart` too and is still not `admin`. A future
 *    role needing "everything except X" is a signal that X belongs behind its
 *    own grant.
 * 3. **The key is method + pattern.** `GET /sepa-config` and `PATCH
 *    /sepa-config` are the same path and different grants, and that is the
 *    common shape rather than the exception.
 *
 * The pattern is the one Slim registered — `/api/admin/members/{memberId}`, not
 * the concrete path — so the map is a literal transcription of `routes.php` and
 * `RouteRoleMapCompletenessTest` can compare the two as strings. There is no
 * path matching here to get wrong, and no route can be covered by a
 * near-miss wildcard.
 *
 * Every entry lists `AdminRole::ADMIN` explicitly even though it is a strict
 * superset of the other two. Reading a row must answer "who may do this"
 * without also knowing a rule about who is implied.
 *
 * ## `/api/auth/*`
 *
 * Granted to all three roles: they act on the caller's *own* account, which
 * they have already authenticated as, so gating them by office protects
 * nothing. An account somehow holding no role at all is refused here along with
 * everything else — deliberately, since that is the fail-closed answer, and the
 * SPA's boot call to `GET /auth/profile` answering 403 `insufficient_role` is
 * exactly the named refusal screen ADR-0044 asks for.
 */
final class RouteRoleMap
{
    private const ADMIN_ONLY = [AdminRole::ADMIN];
    private const TREASURY = [AdminRole::ADMIN, AdminRole::KASSENWART];
    private const BAR = [AdminRole::ADMIN, AdminRole::GETRAENKEWART];
    private const EVERY_ROLE = [AdminRole::ADMIN, AdminRole::KASSENWART, AdminRole::GETRAENKEWART];

    /**
     * Keyed `"METHOD /pattern"`. Ordered as `routes.php` orders them, so the
     * two can be read side by side in review.
     *
     * @var array<string, list<AdminRole>>
     */
    private const MAP = [
        // ── own account: every role ──────────────────────────────────────
        'POST /api/auth/logout' => self::EVERY_ROLE,
        'GET /api/auth/profile' => self::EVERY_ROLE,
        'PATCH /api/auth/profile' => self::EVERY_ROLE,
        'PATCH /api/auth/change-password' => self::EVERY_ROLE,
        'POST /api/auth/2fa/setup' => self::EVERY_ROLE,
        'POST /api/auth/2fa/confirm' => self::EVERY_ROLE,
        'POST /api/auth/2fa/reset' => self::EVERY_ROLE,

        // ── the installation itself: admin only ──────────────────────────
        // The report names this installation's weak points, and the branding
        // is an operator surface rather than a club office.
        'GET /api/admin/security-check' => self::ADMIN_ONLY,
        // The backups page (#693). An archive carries the audit log, every
        // admin's TOTP ciphertext and the database password, and ADR-0049 draws
        // that office boundary for custody of the key — the Kassenwart holds
        // the IBAN key because SEPA collection needs it, which is a different
        // key and a different remit.
        'GET /api/admin/backups' => self::ADMIN_ONLY,
        'GET /api/admin/backups/remote' => self::ADMIN_ONLY,
        'GET /api/admin/backups/{name}' => self::ADMIN_ONLY,
        'PATCH /api/admin/instance-config' => self::ADMIN_ONLY,
        // The one row where the grant is wider than the payload (#677).
        // `SchedulerController` redacts the operator half — the cron command
        // naming this installation's document root, the URL trigger, the
        // drain's PHP build, the observed interval — for anyone who is not
        // `admin`, so what a Kassenwart reads here is the bare `verified` flag
        // and the interval the setup instructions recommend.
        //
        // Widened because the scheduler gate refuses *their* button:
        // finalizing a direct debit is blocked until a drain run has been
        // observed, every settlement route is TREASURY, and the banner meant
        // to warn them ahead of that refusal was the one request their session
        // could not make. `SchedulerStatusDtoTest` is what holds the redaction
        // to its promise.
        'GET /api/admin/scheduler' => self::TREASURY,

        // ── the club's financial position ────────────────────────────────
        // Not aggregates: `recent_transactions` carries member names,
        // `members_near_limit` lists five named members with their Deckel, and
        // `top_members[]` ranks named members by personal spend. Total
        // outstanding Deckel tells nobody whether to stock Alt.
        'GET /api/admin/dashboard' => self::TREASURY,
        'GET /api/admin/statistics/monthly' => self::TREASURY,

        // ── members ──────────────────────────────────────────────────────
        // Read, create, edit and the collection-hold clear are settlement
        // work. Erasure and the subject-access export are not: you never need
        // to erase a member to run a collection, the first two are
        // irreversible, and the third is the cleanest "download everything
        // about one named person" action in the system.
        'GET /api/admin/members' => self::TREASURY,
        'POST /api/admin/members' => self::TREASURY,
        'GET /api/admin/members/credit-balances' => self::TREASURY,
        'GET /api/admin/members/collection-holds' => self::TREASURY,
        'GET /api/admin/members/mandate-missing' => self::TREASURY,
        'GET /api/admin/members/completeness' => self::TREASURY,
        'GET /api/admin/members/{memberId}' => self::TREASURY,
        'PATCH /api/admin/members/{memberId}' => self::TREASURY,
        'DELETE /api/admin/members/{memberId}' => self::ADMIN_ONLY,
        'POST /api/admin/members/{memberId}/export' => self::ADMIN_ONLY,
        'POST /api/admin/members/{memberId}/anonymize' => self::ADMIN_ONLY,
        'POST /api/admin/members/{memberId}/collection-hold/clear' => self::TREASURY,

        // ── the drinks list ──────────────────────────────────────────────
        // The most frequent and least sensitive work in the panel, and the
        // reason the whole epic exists: it can now be handed to somebody
        // without also handing them every member's bank details.
        'GET /api/admin/categories' => self::BAR,
        'POST /api/admin/categories' => self::BAR,
        'PATCH /api/admin/categories/{categoryId}' => self::BAR,
        'PATCH /api/admin/categories/{categoryId}/status' => self::BAR,
        'DELETE /api/admin/categories/{categoryId}' => self::BAR,
        'GET /api/admin/products' => self::BAR,
        'POST /api/admin/products' => self::BAR,
        'PATCH /api/admin/products/{productId}' => self::BAR,
        'PATCH /api/admin/products/{productId}/status' => self::BAR,
        'DELETE /api/admin/products/{productId}' => self::BAR,

        // ── transactions, Storno included ────────────────────────────────
        'GET /api/admin/transactions' => self::TREASURY,
        'GET /api/admin/transactions/export' => self::TREASURY,
        'GET /api/admin/members/{memberId}/transactions' => self::TREASURY,
        'POST /api/admin/transactions/{transactionId}/storno' => self::TREASURY,
        // TREASURY, not ADMIN_ONLY. The audit log holding the violation is
        // admin-only, which is the gap #622 closes: the Kassenwart is the
        // office ADR-0045 names as the recipient, and an alert they can see
        // but not clear leaves the dashboard permanently red for exactly the
        // person most likely to be looking at it.
        'GET /api/admin/jugendschutz-violations' => self::TREASURY,
        'POST /api/admin/jugendschutz-violations/{id}/acknowledge' => self::TREASURY,

        // ── accounts, the audit log, keys, mail: admin only ──────────────
        // A Kassenwart who cannot mint an account, mint a terminal token, install
        // a key of their own or redirect the alert address cannot acquire
        // capability or persist — and cannot read the log to see whether
        // anyone is watching.
        'GET /api/admin/admin-users' => self::ADMIN_ONLY,
        'POST /api/admin/admin-users' => self::ADMIN_ONLY,
        'GET /api/admin/admin-users/{id}' => self::ADMIN_ONLY,
        'PATCH /api/admin/admin-users/{id}' => self::ADMIN_ONLY,
        'DELETE /api/admin/admin-users/{id}' => self::ADMIN_ONLY,
        'POST /api/admin/admin-users/{id}/reactivate' => self::ADMIN_ONLY,
        'POST /api/admin/admin-users/{id}/reset-password' => self::ADMIN_ONLY,
        'GET /api/admin/audit-log' => self::ADMIN_ONLY,
        'GET /api/admin/encryption-keys' => self::ADMIN_ONLY,
        'POST /api/admin/encryption-keys' => self::ADMIN_ONLY,
        'POST /api/admin/encryption-keys/{id}/activate' => self::ADMIN_ONLY,
        'POST /api/admin/encryption-keys/{id}/revoke' => self::ADMIN_ONLY,
        'POST /api/admin/encryption-keys/{id}/rotate-batch' => self::ADMIN_ONLY,
        'POST /api/admin/encryption-keys/{id}/complete-rotation' => self::ADMIN_ONLY,

        // ── settlements, SEPA export included ────────────────────────────
        // The office in full. The private key travels in the export request
        // body, which is why holding it is the Kassenwart's job rather than a
        // gap in this table.
        'GET /api/admin/settlements/execution-date-info' => self::TREASURY,
        'GET /api/admin/settlements/filter-preview' => self::TREASURY,
        'POST /api/admin/settlements/settle-filter' => self::TREASURY,
        'POST /api/admin/settlements/preview' => self::TREASURY,
        'GET /api/admin/settlements/reversal-candidates' => self::TREASURY,
        'POST /api/admin/settlements' => self::TREASURY,
        'GET /api/admin/settlements' => self::TREASURY,
        'GET /api/admin/settlements/{id}' => self::TREASURY,
        'DELETE /api/admin/settlements/{id}/cancel' => self::TREASURY,
        'POST /api/admin/settlements/{id}/submit' => self::TREASURY,
        'POST /api/admin/settlements/{id}/reverse' => self::TREASURY,
        'POST /api/admin/settlements/{id}/export/sepa-xml' => self::TREASURY,
        'GET /api/admin/settlements/{id}/export/csv' => self::TREASURY,
        'GET /api/admin/settlements/{id}/export-transactions' => self::TREASURY,

        // ── SEPA configuration: read for the office, write for the operator
        // The treasurer verifies the Gläubiger-Identifikationsnummer on screen
        // against what the bank holds before submitting a batch. Writing it is
        // a once-in-years act whose blast radius is a rejected collection run.
        'GET /api/admin/sepa-config' => self::TREASURY,
        'POST /api/admin/sepa-config' => self::ADMIN_ONLY,
        'PUT /api/admin/sepa-config' => self::ADMIN_ONLY,
        'PATCH /api/admin/sepa-config' => self::ADMIN_ONLY,

        // ── credit limits: both verbs for the office ─────────────────────
        // Deliberately *not* the sepa-config shape. That asymmetry buys
        // protection from a blast radius this setting does not have: a wrong
        // Gläubiger-ID means a rejected collection run and a Vorabankündigung
        // that no longer matches what happened, while a wrong ceiling mints no
        // credential, exports no data, and is undone by typing the right
        // number. Deciding what members may run up on their Deckel is the
        // Kassenwart's own job (ADR-0047 decision 5).
        'GET /api/admin/credit-limit-config' => self::TREASURY,
        'PATCH /api/admin/credit-limit-config' => self::TREASURY,

        // ── mail: admin only, and load-bearing ───────────────────────────
        // Every other detection in this design depends on a Kassenwart being
        // unable to redirect the alert channel to themselves.
        'GET /api/admin/mail-config' => self::ADMIN_ONLY,
        'PATCH /api/admin/mail-config' => self::ADMIN_ONLY,
        'POST /api/admin/mail-config/test-mail' => self::ADMIN_ONLY,

        // ── the queue of what was announced to members ───────────────────
        'GET /api/admin/notifications' => self::TREASURY,
        'POST /api/admin/notifications/{id}/retry' => self::TREASURY,

        // ── reports: the one surface all three offices share ─────────────
        // `group_by` is further restricted for `getraenkewart` by an
        // allow-list inside the controller (#515) — the one boundary in this
        // design that lands on a parameter rather than a route.
        'GET /api/admin/reports/terminal-activity' => self::EVERY_ROLE,
        'GET /api/admin/reports/{reportType}' => self::EVERY_ROLE,

        // ── bank lookup: resolving an IBAN's institute is treasury work ───
        'GET /api/admin/bank-lookup' => self::TREASURY,

        // ── terminals: admin only ────────────────────────────────────────
        // A terminal token is a back door into the sync API, which reads the
        // full roster outside the panel entirely.
        'GET /api/admin/terminals' => self::ADMIN_ONLY,
        'POST /api/admin/terminals' => self::ADMIN_ONLY,
        'GET /api/admin/terminals/{id}' => self::ADMIN_ONLY,
        'PATCH /api/admin/terminals/{id}' => self::ADMIN_ONLY,
        'DELETE /api/admin/terminals/{id}' => self::ADMIN_ONLY,
        'POST /api/admin/terminals/{id}/rotate-token' => self::ADMIN_ONLY,
        'POST /api/admin/terminals/{id}/revoke' => self::ADMIN_ONLY,
        'GET /api/admin/terminals/{id}/anomalies' => self::ADMIN_ONLY,
        'POST /api/admin/terminals/{id}/anomalies/{anomalyId}/acknowledge' => self::ADMIN_ONLY,
    ];

    /**
     * The roles allowed on this route — `admin` alone when the route has no
     * entry, which is the default-deny rule.
     *
     * @return list<AdminRole>
     */
    public static function rolesFor(string $method, string $pattern): array
    {
        return self::MAP[strtoupper($method) . ' ' . $pattern] ?? self::ADMIN_ONLY;
    }

    /**
     * Whether an account holding `$held` may reach this route.
     *
     * Intersection, so holding several roles grants the union of them, and
     * holding none grants nothing.
     *
     * @param list<AdminRole> $held
     */
    public static function permits(array $held, string $method, string $pattern): bool
    {
        $allowed = self::rolesFor($method, $pattern);

        foreach ($held as $role) {
            if (in_array($role, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    public static function hasEntry(string $method, string $pattern): bool
    {
        return isset(self::MAP[strtoupper($method) . ' ' . $pattern]);
    }

    /**
     * Every key in the table, for the completeness test to compare against the
     * routes Slim actually registered.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }
}
