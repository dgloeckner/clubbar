<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Stops the run, once and with a usable message, when the Feature suite is
 * about to start against a database that is not there.
 *
 * Every Feature test connects in `setUp()`, so an unstarted stack produced 748
 * identical errors — one per test, each a PDOException stack trace ending in
 * `ConnectionFactory::create()`. Nothing in that output says *the database is
 * not running*, and it took thirty seconds to print, because each test paid its
 * own connect timeout. The condition is environmental and known before the
 * first test runs, so it is reported before the first test runs.
 *
 * Deliberately a TCP reachability probe rather than a login: "the container is
 * not up" is what this catches, and a probe needs no credentials and cannot
 * fail for a second reason. A database that accepts connections but rejects
 * these credentials is a real failure and is left to the tests to report.
 *
 * The Unit suite never triggers this — it is keyed to the suite name, so
 * `--testsuite Unit` on a machine with no stack stays green.
 */
final class RequireDatabase implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $facade->registerSubscriber(new class implements StartedSubscriber {
            public function notify(Started $event): void
            {
                // Fires for every suite and nested test-class suite; only the
                // top-level Feature suite is the one worth gating.
                if ($event->testSuite()->name() !== 'Feature') {
                    return;
                }

                $host = getenv('DB_HOST') ?: 'database';
                $port = (int) (getenv('DB_PORT') ?: 3306);

                $socket = @fsockopen($host, $port, $errno, $errstr, 3.0);
                if (is_resource($socket)) {
                    fclose($socket);

                    return;
                }

                fwrite(STDERR, self::message($host, $port, $errstr));
                // The suite cannot proceed and every test would report the same
                // thing, so stop here rather than 748 times.
                exit(1);
            }

            private static function message(string $host, int $port, string $errstr): string
            {
                return <<<TEXT

                    ERROR  The Feature suite needs a database and none is reachable.

                           Tried {$host}:{$port} — {$errstr}

                           Start the stack, then re-run:

                               scripts/dev-setup.sh

                           To run only the tests that need no database:

                               php vendor/bin/phpunit --testsuite Unit

                           Point the suite at a different server with DB_HOST / DB_PORT.


                    TEXT;
            }
        });
    }
}
