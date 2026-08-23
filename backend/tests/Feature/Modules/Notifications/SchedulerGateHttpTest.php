<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Notifications\Services\SchedulerStatusService;
use App\Modules\Settlements\Domain\SettlementLeadTime;
use Tests\Feature\HttpTestCase;

/**
 * The scheduler gate through the real stack (#405).
 *
 * Two things are asserted here that a unit test cannot reach: that the status
 * endpoint is mounted behind the admin session and answers the shape the banner
 * reads, and that the refusal survives the middleware — a typed 409 with
 * `scheduler_not_verified`, not the generic 422 the frontend would have to
 * parse prose out of.
 *
 * A third since #677: which *office* gets which half of that shape. The
 * redaction itself is `SchedulerStatusDtoTest`'s; what is asserted here is that
 * the real stack applies it to the roles `AdminSessionAuth` resolved, and that
 * the Getränkewart is still refused at the middleware.
 *
 * `cron_heartbeat` is a singleton, so this suite snapshots it in setUp and
 * restores it in tearDown rather than creating its own — the singleton
 * equivalent of Pattern 001's isolation rule. Restoring matters more than
 * usual: the seeded row is what keeps every other settlement test green.
 */
class SchedulerGateHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    private string $adminId;
    /** @var list<string> Accounts minted mid-test for the per-office reads. */
    private array $extraAdminIds = [];
    private array $originalHeartbeat = [];
    private string $csrfToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->uuid();
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$this->adminId, "scheduler-{$this->adminId}@example.test", self::PASSWORD_HASH, 'Scheduler', 'de']);
        $this->grantRoles($this->adminId);

        $this->originalHeartbeat = $this->db->query('SELECT * FROM cron_heartbeat WHERE id = 1')->fetch() ?: [];

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $this->adminId;
        \App\Modules\Auth\Domain\SessionTimeout::begin($_SESSION);
        $this->csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $this->csrfToken;
    }

    protected function tearDown(): void
    {
        $this->restoreHeartbeat();

        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);
        foreach ($this->extraAdminIds as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    /** No run has ever been observed — the state a fresh installation is in. */
    private function clearHeartbeat(): void
    {
        $this->db->exec(
            'UPDATE cron_heartbeat SET last_run_at = NULL, source = NULL, sent = 0, failed = 0,
                    php_version = NULL, missing_extensions = NULL WHERE id = 1'
        );
    }

    private function recordRun(string $at = 'NOW()'): void
    {
        $this->db->exec(
            "UPDATE cron_heartbeat SET last_run_at = {$at}, source = 'cli', sent = 2, failed = 0,
                    php_version = '8.3.33', missing_extensions = '' WHERE id = 1"
        );
    }

    private function uuid(): string
    {
        return \App\Shared\Utils\Uuid::v4();
    }

    private function restoreHeartbeat(): void
    {
        if ($this->originalHeartbeat === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE cron_heartbeat SET last_run_at = ?, source = ?, sent = ?, failed = ?,
                    php_version = ?, missing_extensions = ? WHERE id = 1'
        );
        $stmt->execute([
            $this->originalHeartbeat['last_run_at'],
            $this->originalHeartbeat['source'],
            $this->originalHeartbeat['sent'],
            $this->originalHeartbeat['failed'],
            $this->originalHeartbeat['php_version'],
            $this->originalHeartbeat['missing_extensions'] ?? null,
        ]);
    }

    public function test_status_reports_an_unverified_installation_with_its_setup_instructions(): void
    {
        $this->clearHeartbeat();

        $response = $this->request('GET', '/api/admin/scheduler');
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertFalse($body['verified']);
        $this->assertNull($body['last_run_at']);
        // The banner prints this verbatim, so it has to be a command and not a
        // description of one.
        $this->assertStringEndsWith('/backend/bin/cron.php', $body['setup']['cli_command']);
        $this->assertSame(
            SchedulerStatusService::RECOMMENDED_INTERVAL_MINUTES,
            $body['setup']['recommended_interval_minutes'],
        );
    }

    public function test_status_reports_a_verified_installation_after_a_run(): void
    {
        $this->recordRun();

        $body = $this->decode($this->request('GET', '/api/admin/scheduler'));

        $this->assertTrue($body['verified']);
        $this->assertNotNull($body['last_run_at']);
        $this->assertSame('cli', $body['source']);
        $this->assertSame([], $body['missing_extensions'], 'checked and complete is not the same as unknown');
    }

    /** A heartbeat from two years ago still verifies: the gate asks "ever". */
    public function test_status_reports_a_stale_run_as_verified(): void
    {
        $this->recordRun('NOW() - INTERVAL 730 DAY');

        $this->assertTrue($this->decode($this->request('GET', '/api/admin/scheduler'))['verified']);
    }

    public function test_the_status_endpoint_requires_a_session(): void
    {
        $_SESSION = [];

        $this->assertSame(401, $this->request('GET', '/api/admin/scheduler')->getStatusCode());
    }

    /**
     * The refusal, end to end.
     *
     * The member id is deliberately one that does not exist: the gate runs
     * before anything about the settlement is resolved, so what this asserts is
     * the *order* — an installation with no scheduler is told about the
     * scheduler, not about its member selection.
     */
    public function test_finalizing_a_direct_debit_is_refused_while_no_run_has_been_observed(): void
    {
        $this->clearHeartbeat();

        $response = $this->request('POST', '/api/admin/settlements', [
            'member_ids' => [$this->uuid()],
            'execution_date' => SettlementLeadTime::earliestBusinessDay(),
            'method' => 'direct_debit',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(409, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('scheduler_not_verified', $body['error']);
        $this->assertStringContainsString('backend/bin/cron.php', $body['message'], 'the remedy travels with the refusal');
    }

    /**
     * With a run recorded the gate is out of the way, and the request fails on
     * its own merits instead — here, on a member that does not exist. Same
     * request, different reason: that difference is the gate.
     */
    public function test_the_same_request_gets_past_the_gate_once_a_run_is_recorded(): void
    {
        $this->recordRun();

        $response = $this->request('POST', '/api/admin/settlements', [
            'member_ids' => [$this->uuid()],
            'execution_date' => SettlementLeadTime::earliestBusinessDay(),
            'method' => 'direct_debit',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertNotSame('scheduler_not_verified', $this->decode($response)['error'] ?? null);
    }

    /**
     * Sign this request context in as a fresh account holding `$roles`.
     *
     * A second account rather than a re-grant on the first: `admin_user_roles`
     * has no "replace" and stripping `admin` off the fixture mid-test would
     * make the assertions depend on the order they run in.
     */
    private function actAs(AdminRole ...$roles): void
    {
        $id = $this->uuid();
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "scheduler-office-{$id}@example.test", self::PASSWORD_HASH, 'Office', 'de']);
        $this->grantRoles($id, ...$roles);
        $this->extraAdminIds[] = $id;

        $_SESSION['admin_user_id'] = $id;
    }

    /**
     * The whole point of #677: the office the gate actually blocks can read
     * whether the gate is closed. Before this, every page a Kassenwart opened
     * fired a request that could only answer 403, and the warning meant to
     * pre-empt their own refusal was the one thing they could not load.
     */
    public function test_a_kassenwart_reads_the_flag_the_banner_needs(): void
    {
        $this->clearHeartbeat();
        $this->actAs(AdminRole::KASSENWART);

        $response = $this->request('GET', '/api/admin/scheduler');
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertFalse($body['verified']);
        $this->assertSame(
            SchedulerStatusService::RECOMMENDED_INTERVAL_MINUTES,
            $body['setup']['recommended_interval_minutes'],
        );
    }

    /**
     * And reads nothing else. The grant widened, the payload did not: the CLI
     * command names this installation's document root and the drain URL names
     * its trigger endpoint, which is the operator detail ADR-0031 keeps behind
     * the operator's session.
     */
    public function test_a_kassenwart_reads_no_operator_detail(): void
    {
        $this->clearHeartbeat();
        $this->actAs(AdminRole::KASSENWART);

        $body = $this->decode($this->request('GET', '/api/admin/scheduler'));

        $this->assertSame(['verified', 'setup'], array_keys($body));
        $this->assertSame(['recommended_interval_minutes'], array_keys($body['setup']));
    }

    /** The same session, on the same route, still gets the whole thing as admin. */
    public function test_an_admin_still_reads_the_setup_command(): void
    {
        $this->clearHeartbeat();

        $body = $this->decode($this->request('GET', '/api/admin/scheduler'));

        $this->assertStringEndsWith('/backend/bin/cron.php', $body['setup']['cli_command']);
        $this->assertArrayHasKey('php_version', $body);
    }

    /**
     * The Getränkewart is refused at the middleware, deliberately and not by
     * omission: nothing they can do is blocked by the scheduler gate, so a
     * banner about it would warn them of a refusal they will never meet. The
     * panel does not ask on their behalf either — which is what keeps this 403
     * a bookmark's answer rather than something on every page load.
     */
    public function test_a_getraenkewart_is_refused_by_name(): void
    {
        $this->actAs(AdminRole::GETRAENKEWART);

        $response = $this->request('GET', '/api/admin/scheduler');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('insufficient_role', $this->decode($response)['error']);
    }
}
