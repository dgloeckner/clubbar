<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\LocalWebServer;
use Tests\Support\TempTree;

/**
 * Regression test for the process leak described in {@see LocalWebServer}.
 *
 * The leak was invisible from inside the suite: phpunit exited 0 in thirteen
 * seconds and every assertion passed. It only showed up as `phpunit | tail`
 * hanging for minutes, because sixteen orphaned `php -S` processes still held
 * the write end of phpunit's stdout pipe. A test that merely asserted the
 * server answered would have stayed green throughout, so this one asserts on
 * the process table instead.
 */
final class LocalWebServerTest extends TestCase
{
    use TempTree;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = self::makeTempTree('clubbar-localwebserver');
        file_put_contents($this->root . '/router.php', "<?php echo 'ok';");
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            self::removeTempTree($this->root);
        }

        parent::tearDown();
    }

    public function test_it_serves_requests_while_running(): void
    {
        $server = $this->startOrSkip();

        try {
            $this->assertSame('ok', file_get_contents($server->baseUrl() . '/'));
        } finally {
            $server->stop();
        }
    }

    /**
     * The regression itself: after stop(), nothing is left listening.
     *
     * A leaked server keeps answering, because killing the `sh -c` wrapper never
     * touched it.
     */
    public function test_stop_leaves_no_process_behind(): void
    {
        $server = $this->startOrSkip();
        $port = (int) parse_url($server->baseUrl(), PHP_URL_PORT);

        $this->assertNotSame([], $this->serverPidsOnPort($port), 'server was not running to begin with');

        $server->stop();

        $this->assertSame(
            [],
            $this->serverPidsOnPort($port),
            'a php -S process survived stop() — the command was probably passed to '
            . 'proc_open() as a string again, which wraps it in sh -c and orphans the server',
        );
    }

    /**
     * The mechanism, asserted directly: the server must be a *direct* child, not
     * a grandchild behind a shell. Only then does proc_terminate() reach it.
     */
    public function test_the_server_runs_without_a_shell_wrapper(): void
    {
        $server = $this->startOrSkip();
        $port = (int) parse_url($server->baseUrl(), PHP_URL_PORT);

        try {
            foreach ($this->serverPidsOnPort($port) as $pid) {
                $this->assertSame(
                    [],
                    $this->childrenOf($pid),
                    "php -S (pid {$pid}) has children, so it was started through a shell",
                );
            }
        } finally {
            $server->stop();
        }
    }

    private function startOrSkip(): LocalWebServer
    {
        $server = LocalWebServer::start($this->root . '/router.php', $this->root);
        if ($server === null) {
            $this->markTestSkipped('Could not start a local webserver to probe');
        }

        return $server;
    }

    /**
     * Pids of any `php -S` bound to $port, read from the process table rather
     * than from the helper — the point is what survived it.
     *
     * @return list<int>
     */
    private function serverPidsOnPort(int $port): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmdline = @file_get_contents($file);
            if ($cmdline === false || $cmdline === '') {
                continue;
            }

            $args = explode("\0", $cmdline);
            if (!in_array('-S', $args, true) || !in_array("127.0.0.1:{$port}", $args, true)) {
                continue;
            }

            $pids[] = (int) basename(dirname($file));
        }

        return $pids;
    }

    /** @return list<int> */
    private function childrenOf(int $pid): array
    {
        $children = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
            $stat = @file_get_contents($file);
            if ($stat === false) {
                continue;
            }

            // The comm field is parenthesised and may contain spaces, so split
            // on the last ')' before reading the fields after it.
            $tail = substr($stat, (int) strrpos($stat, ') ') + 2);
            $fields = explode(' ', $tail);
            if ((int) ($fields[1] ?? 0) === $pid) {
                $children[] = (int) basename(dirname($file));
            }
        }

        return $children;
    }
}
