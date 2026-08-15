<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Middleware;

use App\Modules\Auth\Middleware\AdminSessionAuth;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Config\AppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class AdminSessionAuthTest extends TestCase
{
    private AdminUsersRepository $adminUsersRepository;
    private AdminSessionAuth $middleware;

    protected function setUp(): void
    {
        $this->adminUsersRepository = $this->createMock(AdminUsersRepository::class);
        $this->middleware = new AdminSessionAuth($this->adminUsersRepository, new AppConfig());

        // The middleware only calls session_start() when no session is active yet;
        // start one up front so it never runs mid-test and clobbers the $_SESSION
        // array a test just set.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function request(string $path = '/api/admin/members'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path);
    }

    private function admin(array $overrides = []): array
    {
        return array_merge(['id' => 'admin-1', 'is_active' => 1], $overrides);
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));
        return $handler;
    }

    public function test_process_returns_401_when_session_has_no_admin_user_id(): void
    {
        $_SESSION = [];
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($this->request(), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('admin_not_authenticated', $this->decode($response)['error']);
    }

    public function test_process_returns_401_when_admin_no_longer_exists(): void
    {
        // Covers both "admin was deleted" and "session references an unknown id".
        $_SESSION = ['admin_user_id' => 'deleted-admin'];
        $this->adminUsersRepository->method('findById')->with('deleted-admin')->willReturn(null);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($this->request(), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('admin_not_authenticated', $this->decode($response)['error']);
    }

    public function test_process_returns_401_when_admin_is_inactive(): void
    {
        $_SESSION = ['admin_user_id' => 'admin-1'];
        $this->adminUsersRepository->method('findById')->willReturn($this->admin(['is_active' => 0]));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($this->request(), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('admin_not_authenticated', $this->decode($response)['error']);
    }

    public function test_process_returns_403_when_totp_setup_required_and_route_not_exempt(): void
    {
        $_SESSION = ['admin_user_id' => 'admin-1', 'totp_setup_required' => true];
        $this->adminUsersRepository->method('findById')->willReturn($this->admin());
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($this->request('/api/admin/members'), $handler);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('totp_setup_required', $this->decode($response)['error']);
    }

    public static function exemptRouteProvider(): array
    {
        return [
            ['/api/auth/2fa/setup'],
            ['/api/auth/2fa/confirm'],
        ];
    }

    /** @dataProvider exemptRouteProvider */
    public function test_process_allows_totp_enrollment_routes_despite_setup_gate(string $path): void
    {
        $_SESSION = ['admin_user_id' => 'admin-1', 'totp_setup_required' => true];
        $this->adminUsersRepository->method('findById')->willReturn($this->admin());

        $response = $this->middleware->process($this->request($path), $this->passthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_process_attaches_admin_attributes_and_delegates_on_success(): void
    {
        $_SESSION = ['admin_user_id' => 'admin-1'];
        $admin = $this->admin();
        $this->adminUsersRepository->method('findById')->willReturn($admin);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (ServerRequestInterface $request) use ($admin) {
                return $request->getAttribute('admin_user_id') === 'admin-1'
                    && $request->getAttribute('admin_user') === $admin;
            }))
            ->willReturn(new Response(200));

        $response = $this->middleware->process($this->request(), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Session timeouts (#118) ─────────────────────────────────────────────

    public function test_process_refuses_a_session_left_idle_past_the_limit(): void
    {
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::AUTHENTICATED_AT => time() - SessionTimeout::IDLE_SECONDS - 60,
            SessionTimeout::LAST_ACTIVITY_AT => time() - SessionTimeout::IDLE_SECONDS - 60,
        ];
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($this->request(), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('session_expired', $this->decode($response)['error']);
        $this->assertSame([], $_SESSION, 'the expired session is emptied, not merely rejected');
    }

    public function test_process_refuses_a_session_past_the_absolute_limit_however_active(): void
    {
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::AUTHENTICATED_AT => time() - SessionTimeout::ABSOLUTE_SECONDS - 1,
            SessionTimeout::LAST_ACTIVITY_AT => time(), // busy right up to the deadline
        ];
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($this->request(), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('session_expired', $this->decode($response)['error']);
    }

    public function test_process_does_not_consult_the_database_for_an_expired_session(): void
    {
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::LAST_ACTIVITY_AT => time() - SessionTimeout::IDLE_SECONDS,
        ];
        $this->adminUsersRepository->expects($this->never())->method('findById');

        $this->middleware->process($this->request(), $this->createMock(RequestHandlerInterface::class));
    }

    public function test_process_records_activity_so_the_idle_clock_restarts(): void
    {
        $stale = time() - 60;
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::AUTHENTICATED_AT => $stale,
            SessionTimeout::LAST_ACTIVITY_AT => $stale,
        ];
        $this->adminUsersRepository->method('findById')->willReturn($this->admin());

        $this->middleware->process($this->request(), $this->passthroughHandler());

        $this->assertGreaterThan($stale, $_SESSION[SessionTimeout::LAST_ACTIVITY_AT]);
        $this->assertSame($stale, $_SESSION[SessionTimeout::AUTHENTICATED_AT], 'the absolute clock is not restarted');
    }

    public function test_process_adopts_a_session_that_predates_the_timeout_rule(): void
    {
        $_SESSION = ['admin_user_id' => 'admin-1'];
        $this->adminUsersRepository->method('findById')->willReturn($this->admin());

        $response = $this->middleware->process($this->request(), $this->passthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsInt($_SESSION[SessionTimeout::AUTHENTICATED_AT]);
    }

    // ── Periodic session-ID rotation (#340) ─────────────────────────────────

    private function middlewareWithRegenInterval(int $seconds): AdminSessionAuth
    {
        $_ENV['SESSION_REGEN_INTERVAL'] = (string) $seconds;
        try {
            return new AdminSessionAuth($this->adminUsersRepository, new AppConfig());
        } finally {
            unset($_ENV['SESSION_REGEN_INTERVAL']);
        }
    }

    public function test_process_rotates_the_session_id_once_the_interval_has_elapsed(): void
    {
        $middleware = $this->middlewareWithRegenInterval(10);
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::REGENERATED_AT => time() - 11,
        ];
        $this->adminUsersRepository->method('findById')->willReturn($this->admin());
        $idBefore = session_id();

        $response = $middleware->process($this->request(), $this->passthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame($idBefore, session_id(), 'the session ID is rotated once due');
        $this->assertGreaterThanOrEqual(time() - 1, $_SESSION[SessionTimeout::REGENERATED_AT]);
    }

    public function test_process_does_not_rotate_the_session_id_before_the_interval_elapses(): void
    {
        $middleware = $this->middlewareWithRegenInterval(900);
        $regeneratedAt = time() - 5;
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::REGENERATED_AT => $regeneratedAt,
        ];
        $this->adminUsersRepository->method('findById')->willReturn($this->admin());
        $idBefore = session_id();

        $response = $middleware->process($this->request(), $this->passthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($idBefore, session_id(), 'the session ID is left alone before the interval elapses');
        $this->assertSame($regeneratedAt, $_SESSION[SessionTimeout::REGENERATED_AT]);
    }

    public function test_process_never_rotates_an_expired_session(): void
    {
        $middleware = $this->middlewareWithRegenInterval(10);
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::AUTHENTICATED_AT => time() - SessionTimeout::ABSOLUTE_SECONDS - 1,
            SessionTimeout::LAST_ACTIVITY_AT => time(),
            SessionTimeout::REGENERATED_AT => time() - 11,
        ];
        $this->adminUsersRepository->expects($this->never())->method('findById');

        $response = $middleware->process($this->request(), $this->createMock(RequestHandlerInterface::class));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $_SESSION, 'expiry is checked before rotation, so an expired session is destroyed, not rotated');
    }

    /* ─────────── The credentials epoch (PR #469, ADR-0026 amendment) ─────────── */

    /**
     * ADR-0026 used to leave other sessions alive after a credential change, on
     * the reasoning that ending them needed server-side session enumeration. It
     * needs one comparison instead — and this is it.
     */
    public function test_a_session_older_than_the_credential_change_is_refused(): void
    {
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::AUTHENTICATED_AT => time() - 600,
        ];
        $this->adminUsersRepository->method('findById')->willReturn(
            $this->admin(['credentials_changed_at' => date('Y-m-d H:i:s')])
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($this->request(), $handler);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'credentials_changed',
            $this->decode($response)['error'],
            'distinct from session_expired: nothing timed out, a credential moved',
        );
    }

    /**
     * The session that performed the change stamps itself one second past its
     * own write, so it lands strictly after the epoch and keeps working.
     */
    public function test_the_session_that_made_the_change_still_works(): void
    {
        $now = time();
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::AUTHENTICATED_AT => $now + 1,
        ];
        $this->adminUsersRepository->method('findById')->willReturn(
            $this->admin(['credentials_changed_at' => date('Y-m-d H:i:s', $now)])
        );

        $response = $this->middleware->process($this->request(), $this->passthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Every account carries no epoch until somebody changes a credential, so a
     * null must sign nobody out — otherwise deploying the column would log out
     * every admin at once.
     */
    public function test_an_account_that_never_changed_a_credential_is_untouched(): void
    {
        $_SESSION = [
            'admin_user_id' => 'admin-1',
            SessionTimeout::AUTHENTICATED_AT => time() - 600,
        ];
        $this->adminUsersRepository->method('findById')->willReturn(
            $this->admin(['credentials_changed_at' => null])
        );

        $response = $this->middleware->process($this->request(), $this->passthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
    }
}
