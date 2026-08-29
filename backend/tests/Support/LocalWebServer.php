<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A PHP built-in webserver for tests that must read back what a real HTTP
 * response actually carried.
 *
 * The CLI SAPI never populates `headers_list()` from a `header()` call — there
 * is no response to queue it onto — so a header assertion made in-process
 * proves nothing. These tests therefore talk to a real server, and this class
 * is the one place that starts and stops one.
 *
 * It exists because starting one the obvious way leaks the process. Given a
 * *string* command, `proc_open()` runs it through `/bin/sh -c`, so the pid PHP
 * tracks is the **shell**, not the server:
 *
 *     sh -c '/usr/bin/php8.3' -S 127.0.0.1:43835 -t '/tmp/…' '/tmp/…/router.php'
 *      └── php -S            ← the actual server, a *grandchild*
 *
 * `proc_terminate()` signals the shell. The shell dies, `php -S` does not, and
 * it is reparented to init. The orphan still holds the stdout and stderr pipes
 * it inherited from phpunit, so **the write end of phpunit's output pipe never
 * closes**: `phpunit | tail`, `phpunit | grep`, and every CI step that captures
 * output block on EOF forever, minutes after phpunit itself exited 0. One clean
 * Unit run leaked 16 such servers, which is what made the suite look like it
 * hung when it had in fact finished in thirteen seconds.
 *
 * Passing the command as an **array** execs the binary directly: no shell, one
 * pid, and `proc_terminate()` reaches the server itself. That is the whole fix,
 * and {@see \Tests\Unit\Support\LocalWebServerTest} is its regression test.
 *
 * Never reintroduce a string command here.
 *
 * The second hazard is the port. `php -S` cannot bind one that is already
 * taken — it prints "Failed to listen" and exits — and readiness used to be
 * "something answers on that port", which is satisfied just as well by
 * *whoever already had it*. The test then ran its assertions against a
 * stranger. That is not hypothetical: a Claude Code cloud session holds
 * 127.0.0.1:32859, inside this class's own random range, and it answers **401
 * to every request**, so a run that happened to draw that port failed with
 * `Failed asserting that 401 is identical to 404` in HttpProbeTest — a
 * security probe reading as broken when nothing was.
 *
 * So a port is *claimed before it is used*: bound here first, and only handed
 * to `php -S` once this process has proven it is free. Whoever else holds it is
 * excluded outright rather than mistaken for the server, and readiness now also
 * watches the child, so losing the remaining microsecond-wide race retries on a
 * new port instead of trusting the answer.
 */
final class LocalWebServer
{
    /** @param resource $process */
    private function __construct(
        private mixed $process,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Start a server running $router, optionally serving files from
     * $documentRoot, and wait until it answers.
     *
     * Ports are picked at random and retried: PHP's built-in server cannot bind
     * port 0, and parallel test processes must not collide. Returns null when no
     * attempt succeeded, which callers turn into a skip — a port that cannot be
     * claimed is an environment limit, not a failing assertion.
     *
     * $portSource exists so the regression test can aim every attempt at one
     * port it is deliberately holding; nothing else should pass it.
     */
    public static function start(
        string $router,
        ?string $documentRoot = null,
        int $attempts = 10,
        ?callable $portSource = null,
    ): ?self {
        $portSource ??= static fn(): int => random_int(20000, 60000);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $port = self::claimPort($portSource());
            if ($port === null) {
                continue;
            }

            // Array form, deliberately — see the class docblock. A string here
            // reintroduces the shell wrapper and leaks the server.
            $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}"];
            if ($documentRoot !== null) {
                $command[] = '-t';
                $command[] = $documentRoot;
            }
            $command[] = $router;

            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                continue;
            }

            if (self::waitForPort($port, $process)) {
                return new self($process, "http://127.0.0.1:{$port}");
            }

            self::terminate($process);
        }

        return null;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Stop the server. Safe to call more than once, because `tearDown()` runs
     * for skipped and errored tests too and may follow an explicit stop.
     */
    public function stop(): void
    {
        self::terminate($this->process);
    }

    /** @param resource $process */
    private static function terminate(mixed $process): void
    {
        if (!is_resource($process)) {
            return;
        }

        proc_terminate($process);
        // Reaps the child, so the suite leaves no zombie behind either.
        proc_close($process);
    }

    /**
     * $port back again once this process has proven it can bind it, or null
     * when somebody else already holds it.
     *
     * Binding it here and letting go is what makes the handover safe. A port
     * that merely *looks* unused because nobody asked is the one that produced
     * the 401 in the class docblock; a port this process held a moment ago is
     * one no permanent listener owns.
     */
    private static function claimPort(int $port): ?int
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
        if (!is_resource($socket)) {
            return null;
        }
        fclose($socket);

        return $port;
    }

    /**
     * @param resource $process
     */
    private static function waitForPort(int $port, mixed $process): bool
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            // Checked before the connect, not after: a server that lost the
            // race for the port has already exited, and the only thing that
            // could answer now is somebody else. Retrying on a fresh port is
            // right; accepting that answer is how the wrong server gets tested.
            $status = proc_get_status($process);
            if (is_array($status) && $status['running'] === false) {
                return false;
            }

            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }
            usleep(100_000);
        }

        return false;
    }
}
