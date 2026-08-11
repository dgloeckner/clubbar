<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Controllers;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Auth\Services\TotpService;
use App\Shared\Config\AppConfig;
use App\Shared\Services\AuditService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * POST /api/auth/2fa/confirm — same replay guard as mfa(), for completeness (#338).
 *
 * Low value here in practice, since the secret being enrolled is brand new —
 * but a prior enrollment attempt on this account could in principle have
 * already recorded a time-step, and the check is cheap to keep consistent.
 */
class AuthControllerConfirm2faTest extends TestCase
{
    private AdminUsersRepository $adminUsersRepository;
    private TotpService $totpService;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->adminUsersRepository = $this->createMock(AdminUsersRepository::class);
        $this->totpService = $this->createMock(TotpService::class);

        $this->controller = new AuthController(
            $this->createMock(AuthService::class),
            $this->createMock(AdminUsersService::class),
            $this->adminUsersRepository,
            $this->totpService,
            $this->createMock(AuditService::class),
            new Validator($this->createMock(PDO::class)),
            $this->createMock(LoginAttemptsRepository::class),
            new AppConfig(),
            $this->createMock(StepUpAuthService::class),
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = ['totp_pending_secret' => 'pending-secret'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/2fa/confirm')
            ->withAttribute('admin_user_id', 'admin-1')
            ->withParsedBody(['code' => '123456']);
    }

    private function admin(?int $totpLastTimestep): array
    {
        return [
            'id' => 'admin-1',
            'email' => 'admin@example.com',
            'totp_last_timestep' => $totpLastTimestep,
        ];
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    public function test_rejects_an_invalid_code(): void
    {
        $this->totpService->method('verifyCodeWithTimestep')->willReturn(null);

        $this->adminUsersRepository->expects($this->never())->method('saveTotp');

        $response = $this->controller->confirm2fa($this->request(), new Response());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_code', $this->decode($response)['error']);
    }

    public function test_rejects_a_replayed_code_at_the_same_time_step_as_last_recorded(): void
    {
        $this->totpService->method('verifyCodeWithTimestep')->willReturn(1000);
        $this->adminUsersRepository->method('findById')->willReturn($this->admin(1000));

        $this->adminUsersRepository->expects($this->never())->method('saveTotp');

        $response = $this->controller->confirm2fa($this->request(), new Response());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_code', $this->decode($response)['error']);
    }

    public function test_accepts_and_persists_the_confirming_time_step_when_no_prior_step_was_recorded(): void
    {
        $this->totpService->method('verifyCodeWithTimestep')->willReturn(1000);
        $this->totpService->method('encrypt')->with('pending-secret')->willReturn('encrypted-secret');
        $this->adminUsersRepository->method('findById')->willReturn($this->admin(null));

        $this->adminUsersRepository->expects($this->once())
            ->method('saveTotp')
            ->with('admin-1', 'encrypted-secret', 1000);

        $response = $this->controller->confirm2fa($this->request(), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Two-factor authentication enabled', $this->decode($response)['message']);
    }

    public function test_accepts_a_newer_time_step_than_the_one_last_recorded(): void
    {
        $this->totpService->method('verifyCodeWithTimestep')->willReturn(1000);
        $this->totpService->method('encrypt')->willReturn('encrypted-secret');
        $this->adminUsersRepository->method('findById')->willReturn($this->admin(999));

        $this->adminUsersRepository->expects($this->once())
            ->method('saveTotp')
            ->with('admin-1', 'encrypted-secret', 1000);

        $response = $this->controller->confirm2fa($this->request(), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }
}
