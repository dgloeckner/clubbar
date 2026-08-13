<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Config\Env;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Boots the real Slim application and dispatches PSR-7 requests in-process.
 *
 * Unit tests call a controller method directly, which proves nothing about the
 * middleware in front of it — and #78 was exactly that kind of bug: the handler
 * was fine, the route simply carried no rate limiter. This harness exercises the
 * wiring in `routes.php` and `ServiceFactory` without a web server, and lets a
 * test choose the source IP, which the E2E suite cannot do from one host.
 *
 * Environment comes from the process (as it does in Docker and CI). A stray
 * `backend/.env` takes precedence over it — `Env::load()` is authoritative once
 * the file exists — so local runs should not keep one alongside these tests.
 */
abstract class HttpTestCase extends TestCase
{
    protected App $app;
    protected PDO $db;

    private ?string $previousAppEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        // bootstrap.php configures session ini settings, which PHP refuses while a
        // session is active — and an earlier test in this process may have left one
        // open. Close it so the boot is warning-free; tests that need session state
        // start their own after setUp.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }

        $this->previousAppEnv = getenv('APP_ENV') === false ? null : getenv('APP_ENV');
        // The OAS validation middleware is only mounted under APP_ENV=test, and it
        // is not what these tests are about; keep the stack to the real routes.
        putenv('APP_ENV=local');

        foreach ($this->environment() as $key => $value) {
            $_ENV[$key] = $value;
        }
        Env::reset();

        $this->db = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_NAME']),
            $_ENV['DB_USER'],
            $_ENV['DB_PASS'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $this->app = require __DIR__ . '/../../bootstrap.php';
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
        putenv($this->previousAppEnv === null ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    /**
     * Environment the booted app sees. Mirrors DatabaseTestCase's defaults so a
     * plain `phpunit` run works against the docker-compose database.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        return [
            'DB_HOST' => getenv('DB_HOST') ?: 'database',
            'DB_NAME' => getenv('DB_NAME') ?: 'clubbar',
            'DB_USER' => getenv('DB_USER') ?: 'clubbar',
            'DB_PASS' => getenv('DB_PASS') ?: 'clubbar',
            // Rate limiting is off in the E2E environment (the suite fails logins on
            // purpose); these tests are about the limiter, so they turn it back on.
            'DISABLE_LOGIN_RATE_LIMITING' => 'false',
            'DISABLE_TERMINAL_RATE_LIMITING' => 'false',
            // Matches the encrypted TOTP secret seeded by db/seed.sql. Test-only.
            'TOTP_ENCRYPTION_KEY' => getenv('TOTP_ENCRYPTION_KEY')
                ?: '0000000000000000000000000000000000000000000000000000000000000001',
            // The published dev fingerprint key (ADR-0035); accepted because
            // APP_ENV is a development value here.
            'IBAN_FINGERPRINT_KEY' => getenv('IBAN_FINGERPRINT_KEY')
                ?: '0000000000000000000000000000000000000000000000000000000000000002',
        ];
    }

    /**
     * Dispatch a request through the full middleware stack.
     *
     * @param array<string, mixed> $body   JSON request body
     * @param array<string, string> $server extra server params, e.g. REMOTE_ADDR
     * @param array<string, string> $headers extra request headers, e.g. X-CSRF-Token
     */
    protected function request(
        string $method,
        string $path,
        array $body = [],
        array $server = [],
        array $headers = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, $server + ['REMOTE_ADDR' => '127.0.0.1']);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
