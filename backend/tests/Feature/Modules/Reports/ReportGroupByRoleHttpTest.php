<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reports;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * `group_by` refused by role, through the real stack (ADR-0044, #515).
 *
 * The route is granted to all three offices — it is how the Getränkewart reads
 * per-product sales figures — so this boundary exists only inside the request.
 * Gate the route and the drinks list loses its numbers; leave the parameter
 * alone and `?group_by=member` answers with a ranked list of named members and
 * their personal spend.
 */
class ReportGroupByRoleHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';
    private const REPORT_TYPES = ['revenue', 'consumption', 'transactions'];

    /** @var list<string> */
    private array $createdAdmins = [];

    protected function tearDown(): void
    {
        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdAdmins = [];

        parent::tearDown();
    }

    public function test_a_getraenkewart_cannot_group_any_report_by_member(): void
    {
        $this->signInHolding(AdminRole::GETRAENKEWART);

        foreach (self::REPORT_TYPES as $type) {
            $response = $this->report($type, 'member');

            $this->assertSame(403, $response->getStatusCode(), "{$type} grouped by member must be refused");
            $this->assertSame('insufficient_role', $this->decode($response)['error']);
        }
    }

    public function test_a_getraenkewart_reads_every_dimension_the_drinks_list_needs(): void
    {
        $this->signInHolding(AdminRole::GETRAENKEWART);

        foreach (['category', 'product', 'day', 'week', 'month', 'year'] as $dimension) {
            $response = $this->report('revenue', $dimension);

            $this->assertSame(200, $response->getStatusCode(), "revenue by {$dimension} must be readable");
        }
    }

    /**
     * Refused, not quietly answered with the default grouping. A report that
     * silently changed what it grouped by would be read as though it had
     * answered the question that was asked.
     *
     * 400 rather than 403, and for every role: a dimension the reports do not
     * have is a malformed request. Answering 403 would tell an admin their
     * office is wrong when they have mistyped `moth`.
     */
    public function test_an_unrecognised_dimension_is_a_bad_request_for_every_role(): void
    {
        foreach (AdminRole::cases() as $role) {
            $this->signInHolding($role);

            $response = $this->report('revenue', 'household');

            $this->assertSame(400, $response->getStatusCode(), "{$role->value} must get a validation error");
        }
    }

    public function test_the_treasurer_and_the_admin_keep_the_member_dimension(): void
    {
        foreach ([AdminRole::KASSENWART, AdminRole::ADMIN] as $role) {
            $this->signInHolding($role);

            $response = $this->report('revenue', 'member');

            $this->assertSame(200, $response->getStatusCode(), "{$role->value} must keep group_by=member");
        }
    }

    /**
     * ADR-0044's line is "what does the office need", not "is it aggregated" —
     * and a count of distinct members carries no names, so it stays for
     * everybody. Removing it would cost the Getränkewart a genuine signal
     * (how many people bought anything) to protect nothing.
     */
    public function test_the_unique_member_count_survives_for_the_drinks_list(): void
    {
        $this->signInHolding(AdminRole::GETRAENKEWART);

        $body = $this->decode($this->report('revenue', 'product'));

        $this->assertArrayHasKey('unique_member_count', $body['summary'] ?? []);
    }

    /**
     * Terminal activity is settled on the route, not on a dimension: the whole
     * report is till sessions and per-terminal takings, so there is no grouping
     * to argue about — the bar office is simply outside it.
     */
    public function test_terminal_activity_is_the_treasury_report(): void
    {
        foreach ([AdminRole::ADMIN, AdminRole::KASSENWART] as $role) {
            $this->signInHolding($role);

            $this->assertNotSame(
                403,
                $this->terminalActivity()->getStatusCode(),
                "{$role->value} must reach terminal activity",
            );
        }

        $this->signInHolding(AdminRole::GETRAENKEWART);

        $this->assertSame(403, $this->terminalActivity()->getStatusCode());
    }

    private function terminalActivity(): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'GET',
            '/api/admin/reports/terminal-activity?date_from=2026-01-01&date_to=2026-12-31',
        );
    }

    private function report(string $type, string $groupBy): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'GET',
            "/api/admin/reports/{$type}?date_from=2026-01-01&date_to=2026-12-31&group_by={$groupBy}",
        );
    }

    private function signInHolding(AdminRole ...$roles): void
    {
        $id = Uuid::v4();
        $this->createdAdmins[] = $id;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "report-roles-{$id}@example.test", self::PASSWORD_HASH, 'Report Roles', 'de']);

        $grant = $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)');
        foreach ($roles as $role) {
            $grant->execute([$id, $role->value]);
        }

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $id;
        SessionTimeout::begin($_SESSION);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
}
