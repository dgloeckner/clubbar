<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

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
 * `cron_heartbeat` is a singleton, so this suite snapshots it in setUp and
 * restores it in tearDown rather than creating its own — the singleton
 * equivalent of Pattern 001's isolation rule. Restoring matters more than
 * usual: the seeded row is what keeps every other settlement test green.
 */
class SchedulerGateHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    private string $adminId;
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
}
