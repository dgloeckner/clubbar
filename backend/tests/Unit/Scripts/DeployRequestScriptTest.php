<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The seam is the CLI contract of scripts/deploy-request.sh: a label and a URL
 * in, the parsed JSON and an exit code out. Both deploy workflows call it that
 * way, so the tests shell out and drive it against a real HTTP server rather
 * than reaching into its internals.
 *
 * What is actually under test is the *diagnosis*. The integration deploy sat
 * red for three days printing nothing but a jq parse error, because the old
 * `curl -sf | jq` pipeline discarded the status code, the headers and the body.
 * Each case below is one of the answers a deploy target really gave or really
 * can give, and asserts that the failure names the cause.
 */
class DeployRequestScriptTest extends TestCase
{
    use TempTree;

    /** @var resource|null */
    private $server = null;

    private string $docRoot = '';

    private int $port = 0;

    protected function setUp(): void
    {
        foreach (['curl', 'jq'] as $binary) {
            if (self::which($binary) === null) {
                self::markTestSkipped("{$binary} is not installed; the script is a curl/jq wrapper.");
            }
        }

        $this->docRoot = self::makeTempTree('deploy-request');
        $this->startServer();
    }

    /**
     * The empty-path guard is load-bearing, and it is not defensive
     * programming — it is a fix.
     *
     * `setUp()` skips before `$docRoot` is assigned whenever `curl` or `jq` is
     * missing, and PHPUnit still runs `tearDown()` for a skipped test. With
     * `$docRoot` left at `''`, `glob('' . '/*')` is `glob('/*')` — the
     * container's **root directory** — and the loop below then unlinked every
     * non-directory entry in it.
     *
     * In the backend dev container (`webdevops/php-apache:8.3`, which ships
     * curl but no jq) that meant deleting `/bin`, `/lib`, `/lib64`, `/sbin` and
     * `/entrypoint`, all of which are symlinks at `/` and all of which
     * `unlink()` happily removes. Losing `/lib64` takes the ELF interpreter
     * with it, so from that moment every dynamically linked binary failed to
     * exec with `no such file or directory` — `docker compose exec … php`, the
     * healthcheck (hence "Up (unhealthy)" while the already-running php-fpm
     * kept serving), and even a re-`up` of the same container, which could no
     * longer stat `/entrypoint`. CI never saw it because the CI image has jq,
     * so `setUp()` completes and `$docRoot` is a real temp directory.
     */
    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        self::removeTempTree($this->docRoot);
    }

    // ── the happy path ────────────────────────────────────────────────

    public function test_reports_success_and_prints_the_json_when_the_server_answers_ok(): void
    {
        $this->respondWith('router.php', <<<'PHP'
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'extracted' => 214, 'skipped' => 3]);
            PHP);

        $result = $this->runScript('Extract');

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('HTTP 200', $result['output']);
        // The JSON reaches the log — a deploy that worked should still say what
        // the server did.
        $this->assertStringContainsString('"extracted": 214', $result['output']);
    }

    // ── the failure that produced "Invalid numeric literal" ────────────

    public function test_html_at_200_is_reported_as_upgrade_php_missing_from_the_document_root(): void
    {
        // Exactly what the site serves for a path that is not on disk: .htaccess
        // hands it to index.php, which reads out the SPA shell at HTTP 200.
        $this->respondWith('router.php', <<<'PHP'
            header('Content-Type: text/html');
            echo "<!doctype html>\n<html><head><title>Club Bar</title></head><body></body></html>";
            PHP);

        $result = $this->runScript('Extract');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('served HTML, not JSON', $result['output']);
        $this->assertStringContainsString('not in the document root', $result['output']);
        // The body itself is in the log, so the next person can see what came back.
        $this->assertStringContainsString('<!doctype html>', $result['output']);
    }

    public function test_a_redirect_is_reported_as_the_request_never_reaching_upgrade_php(): void
    {
        $this->respondWith('router.php', <<<'PHP'
            http_response_code(301);
            header('Location: https://example.invalid/upgrade.php');
            PHP);

        $result = $this->runScript('Extract');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('HTTP 301', $result['output']);
        $this->assertStringContainsString('https://example.invalid/upgrade.php', $result['output']);
    }

    // ── upgrade.php answering honestly ────────────────────────────────

    public function test_a_json_error_body_is_surfaced_as_the_error_message(): void
    {
        $this->respondWith('router.php', <<<'PHP'
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => '.upgrade-package.zip not found on server.']);
            PHP);

        $result = $this->runScript('Extract');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('.upgrade-package.zip not found on server.', $result['output']);
    }

    public function test_an_invalid_key_is_surfaced_rather_than_hidden_behind_a_curl_failure(): void
    {
        // The old pipeline used `curl -sf`, which threw this body away and left
        // the step failing on curl's exit code alone.
        $this->respondWith('router.php', <<<'PHP'
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid upgrade key.']);
            PHP);

        $result = $this->runScript('Migrate');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('HTTP 403', $result['output']);
        $this->assertStringContainsString('Invalid upgrade key.', $result['output']);
    }

    public function test_a_downgrade_refusal_is_named_as_such(): void
    {
        $this->respondWith('router.php', <<<'PHP'
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => 'Downgrade not allowed.',
                'current_version' => '1.4.0',
                'package_version' => '1.3.0',
            ]);
            PHP);

        $result = $this->runScript('Extract');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('refused a downgrade', $result['output']);
        // The versions are the whole point of a 409 — they have to reach the log.
        $this->assertStringContainsString('1.4.0', $result['output']);
    }

    public function test_an_unreachable_server_fails_without_pretending_to_have_an_answer(): void
    {
        $result = $this->runScript('Extract', 'http://127.0.0.1:1/upgrade.php');

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('could not be reached', $result['output']);
    }

    // ── plumbing ──────────────────────────────────────────────────────

    /**
     * @return array{exit: int, output: string}
     */
    private function runScript(string $label, ?string $url = null): array
    {
        $command = sprintf(
            '%s %s %s 10',
            escapeshellarg(self::scriptPath()),
            escapeshellarg($label),
            escapeshellarg($url ?? "http://127.0.0.1:{$this->port}/upgrade.php?key=secret&action=extract")
        );

        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    /** Write the router the built-in server answers every request with. */
    private function respondWith(string $file, string $body): void
    {
        file_put_contents($this->docRoot . '/' . $file, "<?php\n" . $body . "\n");
    }

    private function startServer(): void
    {
        // Port 0 lets the OS pick, but PHP's built-in server does not report the
        // port it got, so a port is picked here and retried on collision —
        // parallel test runs share the machine.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                ['php', '-S', "127.0.0.1:{$port}", '-t', $this->docRoot, $this->docRoot . '/router.php'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes
            );

            if (!is_resource($process)) {
                continue;
            }

            // A router is needed before the server can answer, and each test
            // writes its own; this placeholder only proves the port is live.
            $this->respondWith('router.php', 'http_response_code(204);');

            if ($this->waitForPort($port)) {
                $this->server = $process;
                $this->port   = $port;

                return;
            }

            proc_terminate($process);
            proc_close($process);
        }

        self::fail('Could not start a PHP built-in server for the deploy-request tests.');
    }

    private function waitForPort(int $port): bool
    {
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    private static function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    /**
     * Locate scripts/deploy-request.sh by walking up from this file, so the
     * suite finds it on a host checkout, in CI and in the backend container —
     * where /app *is* backend/ and the repo-root scripts/ arrives through the
     * ./scripts:/scripts mount in docker-compose.yml.
     */
    private static function scriptPath(): string
    {
        for ($dir = __DIR__; ; $dir = dirname($dir)) {
            $candidate = $dir . '/scripts/deploy-request.sh';
            if (is_file($candidate)) {
                return $candidate;
            }
            if (dirname($dir) === $dir) {
                break;
            }
        }

        self::fail(
            'Cannot find scripts/deploy-request.sh above ' . __DIR__ . '. '
            . 'In the backend container it comes from the ./scripts:/scripts mount in docker-compose.yml.'
        );
    }
}
