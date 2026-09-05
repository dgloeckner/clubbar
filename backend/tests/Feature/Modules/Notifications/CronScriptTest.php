<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Modules\Notifications\Services\DrainService;
use App\Shared\Process\FileLock;
use Tests\Feature\DatabaseTestCase;

/**
 * `bin/cron.php` as the panel invokes it (ADR-0038 rule 3, #403).
 *
 * The seam under test is the command line, so these shell out rather than
 * reaching into the script — the same reasoning as
 * {@see \Tests\Unit\Scripts\CheckPatchCoverageScriptTest}. What a crontab runs
 * is a separate process with its own interpreter, its own configuration
 * resolution and its own lock, and none of that is exercised by calling
 * `DrainService` from a test.
 *
 * Nothing here opens a socket: the environment has no `MAIL_DSN`, so the drain
 * stops before claiming anything and the run is an idle one. That is exactly
 * the run worth testing anyway — it is what the scheduler does on all but a
 * dozen days a year, and the heartbeat it leaves is the only evidence #405 has
 * that a scheduler exists at all.
 */
class CronScriptTest extends DatabaseTestCase
{
    private CronHeartbeatRepository $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->heartbeat = new CronHeartbeatRepository($this->db);
    }

    protected function tearDown(): void
    {
        // The heartbeat is a singleton shared with every other suite. Leave it
        // looking like a scheduler that has run, which is the seeded state and
        // the one #405's gate expects.
        $this->db->prepare(
            'UPDATE cron_heartbeat SET last_run_at = NOW(), source = ?, sent = 0, failed = 0 WHERE id = 1'
        )->execute([DrainSource::CLI->value]);

        // So is `mail_config`. A run with the cadence switched on scans every
        // member, so both the switch and the rows it produced have to go back.
        $this->db->exec("DELETE FROM mail_outbox WHERE kind = 'deckel_statement'");

        if ($this->originalCadence !== null) {
            $this->db->prepare('UPDATE mail_config SET statement_cadence = ? WHERE id = 1')
                ->execute([$this->originalCadence]);
            $this->originalCadence = null;
        }

        foreach ($this->createdMembers as $memberId) {
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$memberId]);
        }
        $this->createdMembers = [];

        foreach ($this->authAttemptIps as $ip) {
            $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
            $this->db->prepare('DELETE FROM terminal_auth_attempts WHERE ip_address = ?')->execute([$ip]);
        }
        $this->authAttemptIps = [];

        parent::tearDown();
    }

    // ── statement fixtures ────────────────────────────────────────────

    /** @var list<string> */
    private array $createdMembers = [];

    /** @var list<string> */
    private array $authAttemptIps = [];

    private ?string $originalCadence = null;

    private function cadence(string $cadence): void
    {
        $this->originalCadence ??= (string) $this->db
            ->query('SELECT statement_cadence FROM mail_config WHERE id = 1')
            ->fetchColumn();

        $this->db->prepare('UPDATE mail_config SET statement_cadence = ? WHERE id = 1')->execute([$cadence]);
    }

    private function createMemberForStatement(): string
    {
        $id = $this->generateUuid();
        $this->createdMembers[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, 'Cron', 'Statement', $id . '@example.com', 'de']);

        return $id;
    }

    private function statementCount(string $memberId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM mail_outbox WHERE kind = 'deckel_statement' AND subject_id = ?"
        );
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }

    public function test_a_run_reports_what_it_did_and_stamps_the_heartbeat(): void
    {
        $this->db->prepare('UPDATE cron_heartbeat SET last_run_at = NULL, source = NULL WHERE id = 1')->execute();

        $result = $this->runCron();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('source=cli', $result['output']);

        $row = $this->heartbeat->get();
        $this->assertNotNull($row['last_run_at'], 'the scheduler ran, and that has to be visible');
        $this->assertSame(DrainSource::CLI->value, $row['source']);

        // The CLI interpreter's own version, not the web's. On mass hosting
        // those differ, and the difference is the first thing to rule out when
        // a queue will not move.
        $this->assertNotNull($row['php_version']);
    }

    /**
     * `login_attempts`/`terminal_auth_attempts` have no path back to empty
     * beyond this: `countRecentByIp`/`countRecentByEmail` never look back more
     * than 15 minutes, but `clearForEmail()` only fires for the account whose
     * own login just succeeded, and `terminal_auth_attempts` has no clearing
     * path at all. A run prunes what is older than a day and leaves what is
     * still inside it.
     */
    public function test_a_run_prunes_stale_auth_attempts(): void
    {
        $ip = $this->authAttemptIp();
        $staleLoginId = $this->insertLoginAttempt($ip, strtotime('-2 days'));
        $liveLoginId = $this->insertLoginAttempt($ip, strtotime('-1 hour'));
        $staleTerminalId = $this->insertTerminalAuthAttempt($ip, strtotime('-2 days'));
        $liveTerminalId = $this->insertTerminalAuthAttempt($ip, strtotime('-1 hour'));

        $result = $this->runCron();

        $this->assertSame(0, $result['exit'], $result['output']);

        // The prune is database-wide, and the address is one of two hundred
        // shared with every other Feature class that varies a source IP — so
        // both the number in the line and the set of rows at this address
        // belong to the run, not to this test. Assert on the four rows this
        // test planted; a stranger's row at the same address is not a
        // regression, and asserting the whole set made it read as one.
        $this->assertMatchesRegularExpression(
            '/Pruned auth attempts: [1-9]\d* login, [1-9]\d* terminal\./',
            $result['output'],
        );

        $remainingLogin = $this->remainingLoginAttemptIds($ip);
        $this->assertNotContains($staleLoginId, $remainingLogin, 'the day-old login attempt survived the prune');
        $this->assertContains($liveLoginId, $remainingLogin, 'a login attempt inside the window was pruned');

        $remainingTerminal = $this->remainingTerminalAuthAttemptIds($ip);
        $this->assertNotContains($staleTerminalId, $remainingTerminal, 'the day-old terminal attempt survived the prune');
        $this->assertContains($liveTerminalId, $remainingTerminal, 'a terminal attempt inside the window was pruned');
    }

    private function authAttemptIp(): string
    {
        // Documentation range (RFC 5737): never a real client, unique enough
        // per run that a parallel test cannot see this one's rows.
        $ip = '198.51.100.' . (crc32($this->generateUuid()) % 200 + 1);
        $this->authAttemptIps[] = $ip;

        return $ip;
    }

    private function insertLoginAttempt(string $ip, int $attemptedAt): int
    {
        $this->db->prepare(
            'INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, ?)'
        )->execute([$ip, date('Y-m-d H:i:s', $attemptedAt)]);

        return (int) $this->db->lastInsertId();
    }

    private function insertTerminalAuthAttempt(string $ip, int $attemptedAt): int
    {
        $this->db->prepare(
            'INSERT INTO terminal_auth_attempts (ip_address, attempted_at) VALUES (?, ?)'
        )->execute([$ip, date('Y-m-d H:i:s', $attemptedAt)]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<int> */
    private function remainingLoginAttemptIds(string $ip): array
    {
        $stmt = $this->db->prepare('SELECT id FROM login_attempts WHERE ip_address = ?');
        $stmt->execute([$ip]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 0));
    }

    /** @return list<int> */
    private function remainingTerminalAuthAttemptIds(string $ip): array
    {
        $stmt = $this->db->prepare('SELECT id FROM terminal_auth_attempts WHERE ip_address = ?');
        $stmt->execute([$ip]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 0));
    }

    /**
     * `flock` prevents an overlapping second run.
     *
     * The outbox claim already makes double-sending impossible; this is about
     * not stacking processes. A cron every five minutes against a run that
     * takes twelve piles up three PHP processes on a tariff that allows very
     * few, and the host kills the account rather than the loop.
     */
    public function test_flock_prevents_an_overlapping_second_run(): void
    {
        $lockPath = $this->lockPath();

        // Prove the path is the one the script uses before trusting the
        // negative result below: a lock taken on the wrong file would let this
        // test pass while the real overlap went unprevented.
        @unlink($lockPath);
        $this->assertSame(0, $this->runCron()['exit']);
        $this->assertFileExists($lockPath, 'the script must lock the file this test holds');

        $held = new FileLock($lockPath);
        $this->assertTrue($held->acquire(), 'the test itself must be able to take the lock');

        $this->db->prepare('UPDATE cron_heartbeat SET last_run_at = NULL WHERE id = 1')->execute();

        try {
            $blocked = $this->runCron();
        } finally {
            $held->release();
        }

        // Exit 0, not an error: an overlapping tick is ordinary operation, and
        // a non-zero exit would have most panels mail the account owner about it.
        $this->assertSame(0, $blocked['exit'], $blocked['output']);
        $this->assertStringContainsString('Another drain run holds', $blocked['output']);
        $this->assertNull(
            $this->heartbeat->get()['last_run_at'],
            'a run that never started must not claim it did'
        );

        // And the lock is not sticky: once released, the next tick runs.
        $this->assertSame(0, $this->runCron()['exit']);
        $this->assertNotNull($this->heartbeat->get()['last_run_at']);
    }

    public function test_help_explains_the_options_and_exits_cleanly(): void
    {
        $result = $this->runCron(['--help']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Usage: php bin/cron.php', $result['output']);
        $this->assertStringContainsString('--budget', $result['output']);
        $this->assertStringContainsString('--period', $result['output']);
    }

    /**
     * A tick queues what has become due, *then* drains (ADR-0039 decision 1,
     * #462 step 11.8).
     *
     * The ordering is the substance. A statement enqueued by this tick has to
     * leave on this tick rather than waiting for the next one — at a daily cron
     * the difference between the two is a day — so the enqueue runs before the
     * claim, exactly like the terminal anomaly scan above it. Asserting on the
     * order the two lines are printed in is the cheapest way to pin it from the
     * outside, and this test shells out precisely because the ordering lives in
     * the script rather than in any service.
     */
    public function test_a_tick_queues_the_periodic_mail_before_it_drains(): void
    {
        $this->cadence('monthly');
        $memberId = $this->createMemberForStatement();

        $result = $this->runCron();

        $this->assertSame(0, $result['exit'], $result['output']);

        $enqueue = strpos($result['output'], 'Deckel statements:');
        $drain = strpos($result['output'], 'source=cli');

        $this->assertIsInt($enqueue, 'the run must say what it queued: ' . $result['output']);
        $this->assertIsInt($drain);
        $this->assertLessThan($drain, $enqueue, 'the enqueue runs before the drain, so a due statement leaves today');

        $this->assertSame(1, $this->statementCount($memberId));
    }

    /**
     * Twice in one period is once in the outbox.
     *
     * The acceptance criterion of #462, exercised through the command a crontab
     * actually runs — two ticks, counted at the database, with no service
     * cooperating in the test's idea of what should happen.
     */
    public function test_running_the_cron_twice_in_one_period_leaves_one_statement_per_member(): void
    {
        $this->cadence('monthly');
        $memberId = $this->createMemberForStatement();

        $this->assertSame(0, $this->runCron()['exit']);
        $this->assertSame(0, $this->runCron()['exit']);

        $this->assertSame(1, $this->statementCount($memberId));
    }

    public function test_a_period_the_cadence_cannot_read_is_refused_rather_than_queued(): void
    {
        $this->cadence('monthly');
        $memberId = $this->createMemberForStatement();

        $result = $this->runCron(['--period', '2026-Q3']);

        // Exit 0: a mistyped flag on one run is not a reason to make the panel
        // mail the account owner, and the reason is printed where whoever typed
        // it is looking.
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('nothing due', $result['output']);
        $this->assertSame(0, $this->statementCount($memberId));
    }

    /** With the switch off, the tick is exactly the drain it has always been. */
    public function test_a_disabled_cadence_leaves_the_tick_a_plain_drain(): void
    {
        $this->cadence('off');
        $memberId = $this->createMemberForStatement();

        $result = $this->runCron();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('cadence off', $result['output']);
        $this->assertSame(0, $this->statementCount($memberId));
    }

    public function test_an_unknown_argument_is_refused_rather_than_ignored(): void
    {
        // A typo in a crontab is invisible; silently draining with default
        // settings would hide it until somebody wondered why --budget did
        // nothing.
        $result = $this->runCron(['--budgeting', '10']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Unknown argument', $result['output']);
    }

    public function test_quiet_says_nothing_about_a_clean_run(): void
    {
        // Panels mail whatever a cron prints. A scheduler that mails the
        // treasurer every fifteen minutes is a scheduler somebody turns off.
        $result = $this->runCron(['--quiet']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('', trim($result['output']));
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * @param list<string> $args
     * @return array{exit:int,output:string}
     */
    private function runCron(array $args = []): array
    {
        $command = [PHP_BINARY, self::scriptPath(), ...$args];

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $this->cronEnvironment(),
        );

        $this->assertIsResource($process, 'could not start bin/cron.php');

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    /**
     * What a real crontab hands the script — spelled out rather than inherited.
     *
     * A cron process gets a bare environment, and on a real installation
     * everything it needs comes out of `config.php`. There is no `config.php`
     * here, so this stands in for it, and it has to be complete: the drain
     * reaches `MembersRepository` for the salutation and the mandate reference,
     * which pulls in `IbanSealedBox`, which refuses to construct without
     * `IBAN_FINGERPRINT_KEY`. Inheriting the phpunit process's environment
     * happens to work in the backend container, where docker-compose sets those
     * variables, and fails in CI, where the job sets only `DB_*` — the script
     * then reports "cron could not start" and exits 1, correctly.
     *
     * `APP_ENV` is a development value on purpose: `IbanSealedBox` refuses the
     * published dev key outside one, which is the same reason `HttpTestCase`
     * forces it.
     *
     * @return array<string,string>
     */
    private function cronEnvironment(): array
    {
        return [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'APP_ENV' => 'local',
            'DB_HOST' => getenv('DB_HOST') ?: 'database',
            'DB_NAME' => getenv('DB_NAME') ?: 'clubbar',
            'DB_USER' => getenv('DB_USER') ?: 'clubbar',
            'DB_PASS' => getenv('DB_PASS') ?: 'clubbar',
            'TOTP_ENCRYPTION_KEY' => getenv('TOTP_ENCRYPTION_KEY')
                ?: '0000000000000000000000000000000000000000000000000000000000000001',
            'IBAN_FINGERPRINT_KEY' => getenv('IBAN_FINGERPRINT_KEY')
                ?: '0000000000000000000000000000000000000000000000000000000000000002',
        ];
    }

    /**
     * Where the script takes its lock: `storage/` under the data directory,
     * which for a development checkout and for the backend container alike is
     * the backend directory itself (ADR-0031 decision 2's fallback layout).
     */
    private function lockPath(): string
    {
        return dirname(self::scriptPath()) . '/../storage/' . DrainService::LOCK_FILENAME;
    }

    /**
     * Walk up for `bin/cron.php` rather than counting directories: the suite
     * runs from a checkout on a developer machine and from `/app` in the
     * backend container, where /app *is* backend/.
     */
    private static function scriptPath(): string
    {
        for ($dir = __DIR__; ; $dir = dirname($dir)) {
            $candidate = $dir . '/bin/cron.php';
            if (is_file($candidate)) {
                return $candidate;
            }
            if (dirname($dir) === $dir) {
                self::fail('Could not locate bin/cron.php by walking up from ' . __DIR__);
            }
        }
    }
}
