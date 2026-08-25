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
     */
    public static function start(string $router, ?string $documentRoot = null, int $attempts = 10): ?self
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $port = random_int(20000, 60000);

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

            if (self::waitForPort($port)) {
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

    private static function waitForPort(int $port): bool
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
}
