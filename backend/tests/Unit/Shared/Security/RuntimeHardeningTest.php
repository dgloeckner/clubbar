<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use App\Shared\Middleware\ErrorHandler;
use App\Shared\Security\HttpProbe;
use App\Shared\Security\RuntimeHardening;
use App\Shared\Security\SecuritySelfCheck;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0031 decision 1 rests on a claim that is only worth making if it is
 * measured: every directive below is applied by code, so it holds on a host
 * whose `php.ini` we never see. These tests read the effective value back with
 * `ini_get()` — the same thing the security self-check (#247) does at runtime —
 * rather than asserting that `ini_set()` was called.
 */
class RuntimeHardeningTest extends TestCase
{
    /** Every directive this class touches, captured so one test cannot leak into the next. */
    private const MANAGED_DIRECTIVES = [
        'display_errors',
        'display_startup_errors',
        'log_errors',
        'zend.exception_ignore_args',
        'session.use_strict_mode',
        'session.use_only_cookies',
        'session.use_trans_sid',
        'session.gc_maxlifetime',
        'session.gc_probability',
        'session.gc_divisor',
        'session.save_path',
    ];

    /** @var array<string,string|false> */
    private array $iniBackup = [];
    private array $envBackup = [];
    private string $savePath;

    protected function setUp(): void
    {
        parent::setUp();

        // PHP refuses every session directive while a session is open, and an
        // earlier test in this process may have left one — the same reason
        // HttpTestCase closes it before booting the app.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }

        foreach (self::MANAGED_DIRECTIVES as $directive) {
            $this->iniBackup[$directive] = ini_get($directive);
        }
        $this->envBackup = $_ENV;

        // A real save path, but a throwaway one: the point of the default is
        // tested separately, and applying it here would litter the checkout.
        $this->savePath = sys_get_temp_dir() . '/clubbar-sessions-' . bin2hex(random_bytes(6));

        Env::reset();
        $_ENV['SESSION_SAVE_PATH'] = $this->savePath;
        $_ENV['APP_URL'] = 'https://club.example.org';
    }

    protected function tearDown(): void
    {
        foreach ($this->iniBackup as $directive => $value) {
            if ($value !== false) {
                ini_set($directive, $value);
            }
        }
        $_ENV = $this->envBackup;
        Env::reset();

        if (is_dir($this->savePath)) {
            foreach (glob($this->savePath . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->savePath);
        }

        parent::tearDown();
    }

    public function test_production_keeps_errors_off_the_screen_and_in_the_log(): void
    {
        $warnings = $this->applyWithDebug(false);

        $this->assertSame([], $warnings);
        $this->assertSame('0', ini_get('display_errors'));
        $this->assertSame('0', ini_get('display_startup_errors'));
        $this->assertSame('1', ini_get('log_errors'));
    }

    public function test_debug_shows_errors_but_still_logs_them(): void
    {
        $this->applyWithDebug(true);

        $this->assertSame('1', ini_get('display_errors'));
        $this->assertSame('1', ini_get('display_startup_errors'));
        $this->assertSame(
            '1',
            ini_get('log_errors'),
            'Debug decides who sees an error, never whether it is recorded'
        );
    }

    public function test_production_strips_arguments_from_exception_traces(): void
    {
        $this->applyWithDebug(false);

        $this->assertSame(
            '1',
            ini_get('zend.exception_ignore_args'),
            'Otherwise the trace of a failed PDO connection carries the database password'
        );
    }

    public function test_debug_keeps_trace_arguments(): void
    {
        $this->applyWithDebug(true);

        $this->assertSame('0', ini_get('zend.exception_ignore_args'));
    }

    /**
     * PHP's compiled default is off, and ADR-0016 requires it on: without strict
     * mode PHP adopts a session ID the client made up, which is the half of
     * session fixation that regenerating the ID at login cannot reach.
     */
    public function test_session_ids_supplied_by_a_client_are_rejected(): void
    {
        $this->applyWithDebug(false);

        $this->assertSame('1', ini_get('session.use_strict_mode'));
    }

    public function test_session_ids_travel_only_in_the_cookie(): void
    {
        $this->applyWithDebug(false);

        $this->assertSame('1', ini_get('session.use_only_cookies'));
        $this->assertSame('0', ini_get('session.use_trans_sid'));
    }

    public function test_strict_mode_holds_in_debug_too(): void
    {
        $this->applyWithDebug(true);

        $this->assertSame(
            '1',
            ini_get('session.use_strict_mode'),
            'Session integrity is not a debug-sensitive value'
        );
        $this->assertSame('1', ini_get('session.use_only_cookies'));
    }

    public function test_session_lifetime_comes_from_configuration(): void
    {
        $_ENV['SESSION_MAX_AGE'] = '1234';

        $this->applyWithDebug(false);

        $this->assertSame('1234', ini_get('session.gc_maxlifetime'));
    }

    public function test_sessions_are_written_to_the_configured_private_directory(): void
    {
        $warnings = $this->applyWithDebug(false);

        $this->assertSame([], $warnings);
        $this->assertSame($this->savePath, ini_get('session.save_path'));
        $this->assertDirectoryExists($this->savePath);
    }

    /**
     * Owning the save path means owning its cleanup: a distribution that sweeps
     * the shared directory from cron ships `gc_probability=0`, and inheriting
     * that would leave every session file the app ever wrote in the package.
     */
    public function test_taking_over_the_save_path_turns_garbage_collection_back_on(): void
    {
        ini_set('session.gc_probability', '0');

        $this->applyWithDebug(false);

        $this->assertGreaterThanOrEqual(1, (int) ini_get('session.gc_probability'));
        $this->assertGreaterThanOrEqual(1, (int) ini_get('session.gc_divisor'));
    }

    public function test_an_unusable_save_path_is_reported_rather_than_applied(): void
    {
        // A path under a file, so neither the directory nor its parent can exist.
        $blocked = tempnam(sys_get_temp_dir(), 'clubbar-blocked') . '/sessions';
        $_ENV['SESSION_SAVE_PATH'] = $blocked;
        $before = ini_get('session.save_path');

        $warnings = $this->applyWithDebug(false);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($blocked, $warnings[0]);
        $this->assertSame(
            $before,
            ini_get('session.save_path'),
            'An unwritable save path is an outage, not a hardening — the host default is the safer failure'
        );

        unlink(dirname($blocked));
    }

    public function test_the_default_save_path_lives_with_the_application(): void
    {
        unset($_ENV['SESSION_SAVE_PATH']);
        Env::reset();

        $path = (new AppConfig())->sessionSavePath;

        $this->assertSame(dirname(__DIR__, 4) . '/storage/sessions', $path);
    }

    public function test_a_configured_save_path_wins_over_the_default(): void
    {
        $_ENV['SESSION_SAVE_PATH'] = '/var/clubbar-data/sessions/';
        Env::reset();

        $this->assertSame('/var/clubbar-data/sessions', (new AppConfig())->sessionSavePath);
    }

    /**
     * PHP refuses every session directive once a session is open, and silently
     * enough that the deployment would look hardened while none of it applied.
     * Booting with a session already active cannot happen in a web request; if
     * it ever does, it has to be visible.
     */
    public function test_directives_that_could_not_be_applied_are_reported(): void
    {
        session_start();

        $warnings = $this->applyWithDebug(false);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('already active', $warnings[0]);

        $_SESSION = [];
        session_destroy();
    }

    /**
     * The two halves of ADR-0031 have to describe the same deployment: this
     * class sets the `PHP_INI_ALL` directives from code (decision 1), and the
     * self-check reads them back and reports drift (decision 3). If they ever
     * disagree, a directive is either hardened without being measured — so a
     * host that dropped it stays invisible — or measured without being
     * hardened, which is a permanent red row nobody can clear.
     */
    public function test_every_directive_the_self_check_measures_is_one_this_class_applies(): void
    {
        $this->applyWithDebug(false);

        foreach (SecuritySelfCheck::EXPECTED_DIRECTIVES as $directive => $expected) {
            $actual = strtolower(trim((string) ini_get($directive)));
            $isOn = !in_array($actual, ['', '0', 'off', 'false', 'no'], true);

            $this->assertSame($expected, $isOn, "RuntimeHardening leaves {$directive} at '{$actual}'");
        }
    }

    /**
     * #383, ADR-0031 — CSP and HSTS moved from L2 (`.htaccess` only) to L0
     * after the security self-check found both silently absent on a live
     * installation whose host had stopped honouring `.htaccess`'s
     * `mod_headers` block. Run against a real webserver, mirroring
     * {@see HttpProbeTest} and {@see SecuritySelfCheckTest::withCspServer()}:
     * PHP's CLI SAPI never populates `headers_list()` from a `header()` call
     * — there is no HTTP response to queue it onto — so asserting in-process
     * would prove nothing about what a browser actually receives.
     */
    public function test_content_security_policy_and_hsts_are_set_from_code(): void
    {
        $documentRoot = sys_get_temp_dir() . '/clubbar-security-headers-' . bin2hex(random_bytes(6));
        mkdir($documentRoot, 0700, true);

        $runtimeHardeningPath = dirname(__DIR__, 4) . '/src/Shared/Security/RuntimeHardening.php';
        file_put_contents($documentRoot . '/router.php', <<<PHP
            <?php
            require {$this->exportPath($runtimeHardeningPath)};
            App\Shared\Security\RuntimeHardening::applySecurityHeaders();
            echo 'ok';
            PHP);

        $server = null;
        $baseUrl = null;
        for ($attempt = 0; $attempt < 10 && $server === null; $attempt++) {
            $port = random_int(20000, 60000);
            // An array command, not a string: proc_open() with a string runs
            // it through `sh -c`, so proc_terminate() below signals the shell
            // and the `php -S` child is orphaned rather than stopped. Those
            // orphans keep the port, the memory *and the inherited stdout
            // pipe*, which is why `phpunit | tail` — the command CLAUDE.md
            // prescribes — used to hang after the suite had already finished.
            $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", $documentRoot . '/router.php'];

            $candidate = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($candidate)) {
                continue;
            }

            if ($this->waitForPort($port)) {
                $server = $candidate;
                $baseUrl = "http://127.0.0.1:{$port}";
            } else {
                proc_terminate($candidate);
                proc_close($candidate);
            }
        }

        if ($server === null || $baseUrl === null) {
            $this->removeTree($documentRoot);
            $this->markTestSkipped('Could not start a local webserver to probe');

            return;
        }

        try {
            $response = HttpProbe::fetch($baseUrl . '/');
            $this->assertSame(200, $response['status']);

            $csp  = $response['headers']['content-security-policy'] ?? null;
            $hsts = $response['headers']['strict-transport-security'] ?? null;

            $this->assertNotNull($csp, 'Content-Security-Policy was not sent');
            $this->assertStringContainsString("default-src 'self'", $csp);
            $this->assertStringNotContainsString('unsafe-eval', $csp);

            $this->assertNotNull($hsts, 'Strict-Transport-Security was not sent');
            $this->assertMatchesRegularExpression('/max-age\s*=\s*\d+/', $hsts);
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->removeTree($documentRoot);
        }
    }

    private function exportPath(string $path): string
    {
        return var_export($path, true);
    }

    private function waitForPort(int $port): bool
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function removeTree(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    public function test_a_warning_or_notice_is_not_answered_as_a_fatal(): void
    {
        $this->assertSame('', $this->captureFatalResponse(['type' => E_WARNING], false));
        $this->assertSame('', $this->captureFatalResponse(null, false));
    }

    public function test_a_fatal_is_answered_with_the_same_body_the_error_handler_uses(): void
    {
        $body = json_decode($this->captureFatalResponse(['type' => E_ERROR], false), true);

        $this->assertSame(
            ['error' => 'internal_error', 'message' => ErrorHandler::GENERIC_500_MESSAGE],
            $body,
            'A caller must not be able to tell a pre-boot fatal from a handled one'
        );
    }

    public function test_debug_mode_leaves_a_fatal_to_php(): void
    {
        $this->assertSame('', $this->captureFatalResponse(['type' => E_ERROR], true));
    }

    private function captureFatalResponse(?array $error, bool $debug): string
    {
        ob_start();
        RuntimeHardening::respondToFatalError($error, $debug);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function applyWithDebug(bool $debug): array
    {
        $_ENV['APP_DEBUG'] = $debug ? 'true' : 'false';
        Env::reset();

        // No fatal handler: a shutdown function printing JSON would land in the
        // middle of the test runner's output. It has its own test, which runs
        // the real thing in a subprocess.
        return RuntimeHardening::apply(new AppConfig(), registerFatalHandler: false);
    }
}
