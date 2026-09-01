<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Domain;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\RouteRoleMap;
use PHPUnit\Framework\TestCase;

/**
 * The route→roles map of ADR-0044 (#519) — the table the whole epic's security
 * property rests on.
 *
 * Two things are under test here: the *semantics* (default-deny, additive
 * grants, intersection) and a reading of the ADR's grant table at the places
 * where a mistake would be quiet rather than loud — the boundaries inside a
 * module, where some verbs are granted and the ones next to them are not.
 *
 * Completeness — every registered route has an entry — is asserted against the
 * real route collector in `RouteRoleMapCompletenessTest`, because that is the
 * only place the question can be answered honestly.
 */
class RouteRoleMapTest extends TestCase
{
    // ── semantics ────────────────────────────────────────────────────────

    /**
     * The rule that keeps this design true a year from now: a route nobody
     * classified is `admin`-only, so forgetting the map makes a Kassenwart
     * report "I cannot reach the new page" instead of leaving a Getränkewart
     * quietly reading member data for six months.
     */
    public function test_a_route_with_no_entry_is_admin_only(): void
    {
        $this->assertSame(
            [AdminRole::ADMIN],
            RouteRoleMap::rolesFor('GET', '/api/admin/invented-next-year'),
        );
    }

    public function test_an_unmapped_route_refuses_every_lesser_role(): void
    {
        $this->assertFalse(RouteRoleMap::permits([AdminRole::KASSENWART], 'GET', '/api/admin/invented'));
        $this->assertFalse(RouteRoleMap::permits([AdminRole::GETRAENKEWART], 'GET', '/api/admin/invented'));
        $this->assertTrue(RouteRoleMap::permits([AdminRole::ADMIN], 'GET', '/api/admin/invented'));
    }

    /**
     * Grants are additive and never subtractive (ADR-0044: "no deny rules").
     * Holding both lesser roles is the union of what each may reach — and
     * still not `admin`.
     */
    public function test_holding_two_roles_is_the_union_of_both(): void
    {
        $both = [AdminRole::KASSENWART, AdminRole::GETRAENKEWART];

        $this->assertTrue(RouteRoleMap::permits($both, 'GET', '/api/admin/members'), 'the Kassenwart half');
        $this->assertTrue(RouteRoleMap::permits($both, 'GET', '/api/admin/products'), 'the Getränkewart half');
        $this->assertFalse(RouteRoleMap::permits($both, 'GET', '/api/admin/audit-log'), 'neither half is admin');
    }

    public function test_an_account_holding_no_roles_is_refused_everywhere(): void
    {
        $this->assertFalse(RouteRoleMap::permits([], 'GET', '/api/admin/members'));
        $this->assertFalse(RouteRoleMap::permits([], 'GET', '/api/admin/products'));
        $this->assertFalse(RouteRoleMap::permits([], 'GET', '/api/auth/profile'));
    }

    /**
     * The method is part of the key. `GET /sepa-config` and `PATCH
     * /sepa-config` are the same path and different grants — the treasurer
     * reads the Gläubiger-ID to check it against the bank, and writing it is a
     * once-in-years act in the same class as an encryption key.
     */
    public function test_the_method_is_part_of_the_key(): void
    {
        $kassenwart = [AdminRole::KASSENWART];

        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/sepa-config'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'PATCH', '/api/admin/sepa-config'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/sepa-config'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'PUT', '/api/admin/sepa-config'));
    }

    public function test_the_method_is_matched_case_insensitively(): void
    {
        $this->assertSame(
            RouteRoleMap::rolesFor('GET', '/api/admin/members'),
            RouteRoleMap::rolesFor('get', '/api/admin/members'),
        );
    }

    // ── the grant table, where its edges are ─────────────────────────────

    /**
     * ADR-0044: the Kassenwart does not get the whole member module. Erasure
     * and the subject-access export are irreversible or maximally revealing,
     * and none of them is settlement work.
     */
    public function test_the_kassenwart_reads_and_edits_members_but_cannot_erase_one(): void
    {
        $kassenwart = [AdminRole::KASSENWART];

        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/members'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/members'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'PATCH', '/api/admin/members/{memberId}'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/members/{memberId}/collection-hold/clear'));

        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'DELETE', '/api/admin/members/{memberId}'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/members/{memberId}/anonymize'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/members/{memberId}/export'));
    }

    /**
     * What the epic actually contains: capability acquisition, escalation and
     * persistence. Every one of these is a way to mint authority, redirect the
     * alarm, or check whether anyone is watching.
     */
    public function test_the_kassenwart_cannot_acquire_capability_or_hide(): void
    {
        $kassenwart = [AdminRole::KASSENWART];

        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/admin-users'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/admin-users'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/terminals'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/terminals/{id}/rotate-token'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/encryption-keys'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/audit-log'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/mail-config'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'PATCH', '/api/admin/mail-config'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/security-check'));
        $this->assertFalse(RouteRoleMap::permits($kassenwart, 'PATCH', '/api/admin/instance-config'));
    }

    /**
     * The one grant that is wider than its payload (#677).
     *
     * The scheduler gate refuses the *treasurer's* finalize button, so the
     * warning has to reach the treasurer — while the response's operator half
     * (the cron command naming this installation's document root, the URL
     * trigger, the drain's PHP build) stays `admin`-only. The route says who
     * may knock; `SchedulerStatusDto::toOfficeArray()` says what they hear,
     * and `SchedulerStatusDtoTest` is where that half is asserted.
     *
     * The Getränkewart is out: nothing they can do is blocked by the gate, so
     * a banner about it would be a warning they can neither act on nor be hurt
     * by — and leaving them out is what stops a request that could only ever
     * answer 403.
     */
    public function test_the_scheduler_status_reaches_the_kassenwart_and_stops_there(): void
    {
        $this->assertTrue(RouteRoleMap::permits([AdminRole::KASSENWART], 'GET', '/api/admin/scheduler'));
        $this->assertTrue(RouteRoleMap::permits([AdminRole::ADMIN], 'GET', '/api/admin/scheduler'));
        $this->assertFalse(RouteRoleMap::permits([AdminRole::GETRAENKEWART], 'GET', '/api/admin/scheduler'));
    }

    /** Settlement work is the office, SEPA export included. */
    public function test_the_kassenwart_runs_settlements_end_to_end(): void
    {
        $kassenwart = [AdminRole::KASSENWART];

        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/settlements'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/settlements/{id}/submit'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/settlements/{id}/export/sepa-xml'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/settlements/{id}/reverse'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'POST', '/api/admin/transactions/{transactionId}/storno'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/notifications'));
        $this->assertTrue(RouteRoleMap::permits($kassenwart, 'GET', '/api/admin/bank-lookup'));
    }

    /**
     * The Getränkewart holds the drinks list and nothing about the club's
     * money or its members. `/dashboard` and `/statistics/monthly` are not
     * aggregates — they carry named members and their individual Deckel.
     */
    public function test_the_getraenkewart_holds_the_drinks_list_and_no_member_data(): void
    {
        $wart = [AdminRole::GETRAENKEWART];

        $this->assertTrue(RouteRoleMap::permits($wart, 'GET', '/api/admin/products'));
        $this->assertTrue(RouteRoleMap::permits($wart, 'PATCH', '/api/admin/products/{productId}'));
        $this->assertTrue(RouteRoleMap::permits($wart, 'PATCH', '/api/admin/products/{productId}/status'));
        $this->assertTrue(RouteRoleMap::permits($wart, 'DELETE', '/api/admin/categories/{categoryId}'));

        $this->assertFalse(RouteRoleMap::permits($wart, 'GET', '/api/admin/dashboard'));
        $this->assertFalse(RouteRoleMap::permits($wart, 'GET', '/api/admin/statistics/monthly'));
        $this->assertFalse(RouteRoleMap::permits($wart, 'GET', '/api/admin/members'));
        $this->assertFalse(RouteRoleMap::permits($wart, 'GET', '/api/admin/settlements'));
        $this->assertFalse(RouteRoleMap::permits($wart, 'GET', '/api/admin/transactions'));
        $this->assertFalse(RouteRoleMap::permits($wart, 'GET', '/api/admin/sepa-config'));
    }

    /** The sales figures are the one surface all three offices share. */
    public function test_every_role_reads_the_reports(): void
    {
        foreach (AdminRole::cases() as $role) {
            $this->assertTrue(
                RouteRoleMap::permits([$role], 'GET', '/api/admin/reports/{reportType}'),
                $role->value . ' must reach the reports',
            );
        }
    }

    /**
     * Terminal activity is not one of them. Its rows are till sessions and
     * per-terminal takings — a cash-handling question rather than a drinks
     * one — so it follows the treasury and not the reports route beside it.
     */
    public function test_terminal_activity_is_treasury_work(): void
    {
        $this->assertTrue(
            RouteRoleMap::permits([AdminRole::ADMIN], 'GET', '/api/admin/reports/terminal-activity'),
        );
        $this->assertTrue(
            RouteRoleMap::permits([AdminRole::KASSENWART], 'GET', '/api/admin/reports/terminal-activity'),
        );
        $this->assertFalse(
            RouteRoleMap::permits([AdminRole::GETRAENKEWART], 'GET', '/api/admin/reports/terminal-activity'),
        );
    }

    /**
     * Own profile, own password, own 2FA. These act on the caller's own
     * account and gate no confidentiality — the caller already authenticated
     * as that account — so every role reaches them.
     */
    public function test_every_role_manages_its_own_credentials(): void
    {
        foreach (AdminRole::cases() as $role) {
            foreach ([
                ['GET', '/api/auth/profile'],
                ['PATCH', '/api/auth/profile'],
                ['PATCH', '/api/auth/change-password'],
                ['POST', '/api/auth/2fa/setup'],
                ['POST', '/api/auth/2fa/confirm'],
                ['POST', '/api/auth/2fa/reset'],
                ['POST', '/api/auth/logout'],
            ] as [$method, $pattern]) {
                $this->assertTrue(
                    RouteRoleMap::permits([$role], $method, $pattern),
                    "{$role->value} must reach {$method} {$pattern}",
                );
            }
        }
    }

    /** `admin` is a strict superset: it reaches everything in the table. */
    public function test_admin_reaches_every_mapped_route(): void
    {
        foreach (RouteRoleMap::keys() as $key) {
            [$method, $pattern] = explode(' ', $key, 2);

            $this->assertTrue(
                RouteRoleMap::permits([AdminRole::ADMIN], $method, $pattern),
                "admin must reach {$key}",
            );
        }
    }

    /**
     * No entry may be empty. An empty grant list is indistinguishable from
     * `admin`-only at runtime but reads like "nobody", and the difference
     * would surface as an argument in review rather than as a failure.
     */
    public function test_no_entry_grants_nothing(): void
    {
        foreach (RouteRoleMap::keys() as $key) {
            [$method, $pattern] = explode(' ', $key, 2);

            $this->assertNotEmpty(RouteRoleMap::rolesFor($method, $pattern), "{$key} grants nothing");
        }
    }
}
