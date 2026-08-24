<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth\Repositories;

use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * The counters behind the login/MFA gate, against a real database (#78).
 *
 * The interesting behaviour is not that COUNT(*) counts, but the scoping: a row
 * carries both an IP and an account, and clearing by account must take that
 * admin out of *both* counters while leaving every other admin's rows — including
 * rows from the same host — untouched.
 */
class LoginAttemptsRepositoryTest extends DatabaseTestCase
{
    private LoginAttemptsRepository $repository;

    /** Unique per test run so parallel/repeated runs never see each other's rows. */
    private string $ip;
    private string $emailA;
    private string $emailB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new LoginAttemptsRepository($this->db);

        $suffix = substr($this->generateUuid(), 0, 8);
        // Documentation range (RFC 5737) with a random last octet — never a real client.
        $this->ip = '198.51.100.' . (crc32($suffix) % 200 + 1);
        $this->emailA = "a-{$suffix}@example.test";
        $this->emailB = "b-{$suffix}@example.test";

        // Clear the address *before* the test, not only after it.
        //
        // The emails are unique per test; the IP is one of two hundred, and
        // four Feature classes draw from the same pool while several of them
        // write rows keyed on an email of their own. So a run where two of
        // them land on the same address leaves this one counting a stranger's
        // attempt — `test_clearing_is_scoped_to_the_account_that_authenticated`
        // asserts `countRecentByIp() === 1` and gets 2, in CI only, on maybe one
        // run in ten. tearDown() cannot prevent that: it runs after the damage.
        //
        // `AuthRouteRateLimitTest` already clears in setUp for exactly this
        // reason; this class was the asymmetry.
        $this->clearAttempts();
    }

    protected function tearDown(): void
    {
        $this->clearAttempts();
        parent::tearDown();
    }

    /** Every row this test could see or leave, on either dimension. */
    private function clearAttempts(): void
    {
        $stmt = $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email IN (?, ?)');
        $stmt->execute([$this->ip, $this->emailA, $this->emailB]);
    }

    public function test_a_recorded_attempt_counts_on_both_dimensions(): void
    {
        $this->repository->record($this->ip, $this->emailA);

        $this->assertSame(1, $this->repository->countRecentByIp($this->ip, 15));
        $this->assertSame(1, $this->repository->countRecentByEmail($this->emailA, 15));
    }

    public function test_one_host_probing_several_accounts_accumulates_on_the_ip(): void
    {
        $this->repository->record($this->ip, $this->emailA);
        $this->repository->record($this->ip, $this->emailB);

        $this->assertSame(2, $this->repository->countRecentByIp($this->ip, 15));
        $this->assertSame(1, $this->repository->countRecentByEmail($this->emailA, 15));
    }

    public function test_clearing_is_scoped_to_the_account_that_authenticated(): void
    {
        $this->repository->record($this->ip, $this->emailA);
        $this->repository->record($this->ip, $this->emailA);
        $this->repository->record($this->ip, $this->emailB);

        $this->repository->clearForEmail($this->emailA);

        $this->assertSame(0, $this->repository->countRecentByEmail($this->emailA, 15));
        // The other admin's failure from the same host survives — it may well be
        // an attacker, and full authentication of account A says nothing about it.
        $this->assertSame(1, $this->repository->countRecentByEmail($this->emailB, 15));
        $this->assertSame(1, $this->repository->countRecentByIp($this->ip, 15));
    }

    public function test_attempts_outside_the_window_stop_counting(): void
    {
        $this->repository->record($this->ip, $this->emailA);
        $stmt = $this->db->prepare(
            'UPDATE login_attempts SET attempted_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE email = ?'
        );
        $stmt->execute([$this->emailA]);

        // Windowed, never permanent: a locked-out admin waits, they do not phone anyone.
        $this->assertSame(0, $this->repository->countRecentByIp($this->ip, 15));
        $this->assertSame(0, $this->repository->countRecentByEmail($this->emailA, 15));
    }

    public function test_records_without_an_email_for_tables_that_have_no_such_column(): void
    {
        $terminalAttempts = new LoginAttemptsRepository($this->db, 'terminal_auth_attempts');

        $terminalAttempts->record($this->ip);

        $this->assertSame(1, $terminalAttempts->countRecentByIp($this->ip, 15));

        $this->db->prepare('DELETE FROM terminal_auth_attempts WHERE ip_address = ?')->execute([$this->ip]);
    }

    /**
     * `countRecentByIp`/`countRecentByEmail` never look back more than 15
     * minutes, but nothing before this deleted a row that old — `clearForEmail()`
     * only fires for the account whose own login just succeeded, so a probed
     * or mistyped account's attempts sat forever. This pins the one path back
     * to empty.
     */
    public function test_pruning_removes_only_what_is_older_than_the_cutoff(): void
    {
        $this->repository->record($this->ip, $this->emailA);
        $stmt = $this->db->prepare(
            'UPDATE login_attempts SET attempted_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE email = ?'
        );
        $stmt->execute([$this->emailA]);
        $this->repository->record($this->ip, $this->emailB);

        $removed = $this->repository->pruneOlderThan(date('Y-m-d H:i:s', strtotime('-1 day')));

        $this->assertSame(1, $removed);
        // The count check bypasses the window filter entirely, since a pruned
        // row and a merely-out-of-window one must not read alike here.
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ?');
        $stmt->execute([$this->emailA]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
        $this->assertSame(1, $this->repository->countRecentByEmail($this->emailB, 15));
    }

    /** Same method, the table with no `email` column — the prune is generic over both. */
    public function test_pruning_works_on_a_table_with_no_email_column(): void
    {
        $terminalAttempts = new LoginAttemptsRepository($this->db, 'terminal_auth_attempts');
        $terminalAttempts->record($this->ip);
        $this->db->prepare(
            'UPDATE terminal_auth_attempts SET attempted_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE ip_address = ?'
        )->execute([$this->ip]);

        $removed = $terminalAttempts->pruneOlderThan(date('Y-m-d H:i:s', strtotime('-1 day')));

        $this->assertSame(1, $removed);
        $this->assertSame(0, $terminalAttempts->countRecentByIp($this->ip, 15));
    }
}
