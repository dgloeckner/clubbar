<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Shared\Config\Env;
use App\Shared\Database\ConnectionFactory;
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

        $this->db = ConnectionFactory::create(
            $_ENV['DB_HOST'],
            $_ENV['DB_NAME'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASS'],
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
            // The published dev fingerprint key (ADR-0036); accepted because
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

    /**
     * Give a fixture admin the roles a real account has (ADR-0044, #519).
     *
     * Every account in a migrated installation holds at least one role: the
     * migration backfills `admin` onto the ones that existed, the installer and
     * the seed grant their own, and `AdminUsersService::createAdminUser()`
     * grants `admin` to the ones the panel creates. A fixture that inserts an
     * `admin_users` row and stops is therefore simulating an account that
     * cannot exist — and since the role gate fails closed, every request it
     * makes answers 403 `insufficient_role`.
     *
     * Defaults to `admin`, which is what the fixtures of every suite written
     * before roles existed were implicitly assuming.
     */
    protected function grantRoles(string $adminUserId, AdminRole ...$roles): void
    {
        $roles = $roles === [] ? [AdminRole::ADMIN] : $roles;

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)'
        );
        foreach ($roles as $role) {
            $stmt->execute([$adminUserId, $role->value]);
        }
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
