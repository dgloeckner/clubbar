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

        parent::tearDown();
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
