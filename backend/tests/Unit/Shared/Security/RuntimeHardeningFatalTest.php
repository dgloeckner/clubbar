<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Middleware\ErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * The hardening's whole reason for existing is a failure that happens *before*
 * the application can handle it, so asserting it in-process would be asserting
 * the wrong thing: PHPUnit installs its own error handler, and a fatal ends the
 * process. These tests run a real PHP process instead, started the way a badly
 * configured host would start it — `display_errors=1` on the command line — and
 * read what a caller would have seen.
 */
class RuntimeHardeningFatalTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/failing-boot.php';

    /** A value from the failing connection that must never reach a caller. */
    private const DB_PASSWORD = 'correct-horse-battery-staple';

    private string $savePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savePath = sys_get_temp_dir() . '/clubbar-fatal-sessions-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->savePath)) {
            foreach (glob($this->savePath . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->savePath);
        }
        parent::tearDown();
    }

    public function test_a_fatal_before_the_error_handler_answers_generically(): void
    {
        $result = $this->runFixture('hardened');

        $this->assertNotSame(0, $result['exit'], 'The fixture is supposed to fail');
        $this->assertStringContainsString(ErrorHandler::GENERIC_500_MESSAGE, $result['stdout']);
        $this->assertStringContainsString('"error":"internal_error"', $result['stdout']);
    }

    public function test_the_generic_answer_carries_nothing_else(): void
    {
        $result = $this->runFixture('hardened');

        $body = json_decode(trim($result['stdout']), true);

        $this->assertIsArray($body, "Expected a JSON body, got: {$result['stdout']}");
        $this->assertSame(['error', 'message'], array_keys($body));
        $this->assertStringNotContainsString(self::DB_PASSWORD, $result['stdout']);
        $this->assertStringNotContainsString('PDOException', $result['stdout']);
        $this->assertStringNotContainsString('Stack trace', $result['stdout']);
        $this->assertStringNotContainsString($this->fixtureDirectory(), $result['stdout']);
    }

    /**
     * Suppressing the display is only half of it — an error nobody can read
     * afterwards is its own outage.
     */
    public function test_the_failure_is_still_reported_to_the_error_log(): void
    {
        $result = $this->runFixture('hardened');

        $this->assertStringContainsString('PDOException', $result['stderr']);
    }

    public function test_debug_mode_still_shows_the_failure(): void
    {
        $result = $this->runFixture('debug');

        $this->assertStringContainsString(
            'PDOException',
            $result['stdout'],
            'APP_DEBUG exists to make this visible; hardening must not override it'
        );
    }

    /**
     * The ordering inside the real file, not a re-creation of it: `bootstrap.php`
     * must harden the runtime before it opens the database connection, because
     * that connection is the failure this was written for.
     *
     * The fatal handler is not registered on the CLI (a shutdown function
     * printing JSON has no business in a console), so what is measured here is
     * the display suppression: nothing on stdout, everything in the log.
     */
    public function test_bootstrap_hardens_before_it_connects_to_the_database(): void
    {
        if (file_exists(dirname(__DIR__, 4) . '/.env')) {
            $this->markTestSkipped('A backend/.env overrides the fixture credentials — bootstrap would connect for real');
        }

        $result = $this->runFixture('bootstrap');

        $this->assertNotSame(0, $result['exit'], 'The fixture points at a host that cannot resolve');
        $this->assertSame('', trim($result['stdout']), 'A host defaulting display_errors=On must still print nothing');
        $this->assertStringNotContainsString(self::DB_PASSWORD, $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('PDOException', $result['stderr']);
    }

    private function fixtureDirectory(): string
    {
        return dirname(self::FIXTURE);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runFixture(string $mode): array
    {
        $command = sprintf(
            '%s -d display_errors=1 -d display_startup_errors=1 -d zend.exception_ignore_args=0 %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::FIXTURE),
            escapeshellarg($mode),
            escapeshellarg($this->savePath),
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'Could not start the fixture process');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
