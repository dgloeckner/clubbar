<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Middleware;

use App\Modules\Auth\Middleware\TerminalTokenAuth;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Terminals\Repositories\TerminalIpSightingsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\TerminalTokenAuthenticator;
use App\Shared\Enums\AuditAction;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class TerminalTokenAuthTest extends TestCase
{
    private TerminalsRepository $terminalsRepository;
    private LoginAttemptsRepository $authAttempts;
    private AuditService $auditService;
    private TerminalIpSightingsRepository $ipSightings;
    private TerminalTokenAuth $middleware;

    protected function setUp(): void
    {
        $this->terminalsRepository = $this->createMock(TerminalsRepository::class);
        $this->authAttempts = $this->createMock(LoginAttemptsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        // The authenticator is real, not a double: what this middleware answers
        // is decided by the lookup order inside it, and a stubbed authenticator
        // would assert only that the middleware forwards its own opinion.
        $this->ipSightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->middleware = new TerminalTokenAuth(
            $this->terminalsRepository,
            $this->authAttempts,
            new TerminalTokenAuthenticator($this->terminalsRepository, $this->auditService),
            $this->ipSightings,
            $this->createMock(Logger::class),
        );
    }

    private function request(?string $authHeader): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/sync/members');
        if ($authHeader !== null) {
            $request = $request->withHeader('Authorization', $authHeader);
        }
        return $request;
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    private function terminal(array $overrides = []): array
    {
        return array_merge([
            'id' => 'terminal-1',
            'device_id' => 'device-1',
            'is_active' => 1,
            'token_expires_at' => '2026-01-01 00:00:00',
            'created_at' => '2025-01-01 00:00:00',
        ], $overrides);
    }

    /**
     * Expect the middleware to record a failed attempt against the rate limiter's
     * terminal table — by IP alone, since terminal auth presents a token rather
     * than an account (#118 moved this off a raw INSERT in the middleware).
     */
    private function expectAttemptRecorded(): void
    {
        $this->authAttempts->expects($this->once())
            ->method('record')
            ->with($this->isType('string'), null);
    }

    private function rejectingHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        return $handler;
    }

    public function test_process_returns_401_when_authorization_header_missing(): void
    {
        $this->expectAttemptRecorded();

        $response = $this->middleware->process($this->request(null), $this->rejectingHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('authorization_header_missing', $this->decode($response)['error']);
    }

    public function test_process_returns_401_when_authorization_header_is_not_bearer(): void
    {
        $this->expectAttemptRecorded();

        $response = $this->middleware->process($this->request('Basic dXNlcjpwYXNz'), $this->rejectingHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_authorization_format', $this->decode($response)['error']);
    }

    public function test_process_returns_401_for_unknown_token(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')->willReturn(null);
        $this->expectAttemptRecorded();

        $response = $this->middleware->process($this->request('Bearer unknown-token'), $this->rejectingHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_terminal_token', $this->decode($response)['error']);
    }

    /**
     * A token the repository refuses only because its lifetime ran out gets its
     * own error code (#106), so the terminal's operator is told to rotate it
     * rather than to go looking for a typo.
     */
    public function test_process_returns_401_terminal_token_expired_for_an_aged_out_token(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')->willReturn($this->terminal());
        $this->expectAttemptRecorded();

        $response = $this->middleware->process($this->request('Bearer expired-token'), $this->rejectingHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('terminal_token_expired', $this->decode($response)['error']);
    }

    public function test_process_does_not_authenticate_an_expired_token(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')->willReturn($this->terminal());
        $this->terminalsRepository->expects($this->never())->method('updateLastSync');

        $this->middleware->process($this->request('Bearer expired-token'), $this->rejectingHandler());
    }

    /**
     * The expiry lookup is a diagnostic for the 401 only — a token that
     * authenticates must never reach it.
     */
    public function test_process_does_not_run_the_expiry_lookup_when_the_token_is_valid(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn($this->terminal());
        $this->terminalsRepository->expects($this->never())->method('findByPendingTokenHash');
        $this->terminalsRepository->expects($this->never())->method('findExpiredByTokenHash');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        $response = $this->middleware->process($this->request('Bearer valid-token'), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_process_looks_up_the_terminal_by_sha256_of_the_bearer_token(): void
    {
        $token = 'my-plain-token';
        $this->terminalsRepository->expects($this->once())
            ->method('findByTokenHash')
            ->with(TokenService::hashToken($token))
            ->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')->willReturn(null);
        $this->expectAttemptRecorded();

        $this->middleware->process($this->request("Bearer {$token}"), $this->rejectingHandler());
    }

    public function test_process_returns_401_when_terminal_is_inactive(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn($this->terminal(['is_active' => 0]));
        $this->expectAttemptRecorded();

        $response = $this->middleware->process($this->request('Bearer revoked-token'), $this->rejectingHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('terminal_inactive', $this->decode($response)['error']);
    }

    public function test_process_attaches_terminal_attributes_updates_last_sync_and_delegates_on_success(): void
    {
        $terminal = $this->terminal();
        $this->terminalsRepository->method('findByTokenHash')->willReturn($terminal);
        $this->terminalsRepository->expects($this->once())->method('updateLastSync')->with('terminal-1');
        $this->authAttempts->expects($this->never())->method('record');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (ServerRequestInterface $request) use ($terminal) {
                return $request->getAttribute('terminal_id') === 'terminal-1'
                    && $request->getAttribute('terminal') === $terminal;
            }))
            ->willReturn(new Response(200));

        $response = $this->middleware->process($this->request('Bearer valid-token'), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * ADR-0041: every authenticated request is a sighting, because "two
     * addresses at once" can only be answered from the ordinary traffic — there
     * is no separate moment a terminal announces where it is.
     */
    public function test_process_records_an_ip_sighting_for_an_authenticated_request(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn($this->terminal());

        $this->ipSightings->expects($this->once())
            ->method('record')
            ->with('terminal-1', '127.0.0.1', $this->anything());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        $this->middleware->process($this->request('Bearer valid-token'), $handler);
    }

    /**
     * A rejected request has no terminal to attribute a sighting to — that is
     * what `terminal_auth_attempts` is for, and it is keyed by IP alone.
     */
    public function test_process_records_no_sighting_when_the_token_is_rejected(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')->willReturn(null);

        $this->ipSightings->expects($this->never())->method('record');

        $response = $this->middleware->process(
            $this->request('Bearer nope'),
            $this->createMock(RequestHandlerInterface::class),
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * The observation is worth less than the sale. If the sightings table
     * cannot be written, the request it was observing still has to go through.
     */
    public function test_process_serves_the_request_when_the_sighting_cannot_be_recorded(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn($this->terminal());
        $this->ipSightings->method('record')->willThrowException(new \RuntimeException('table is gone'));

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new Response(200));

        $response = $this->middleware->process($this->request('Bearer valid-token'), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Overlap rotation's whole point (#395): the token an admin staged
     * authenticates the first time it is presented, and that first use is what
     * installs it — nobody has to declare the rollout finished.
     */
    public function test_process_promotes_a_pending_token_on_its_first_use_and_authenticates_it(): void
    {
        $token = 'staged-token';
        $promoted = $this->terminal(['token_expires_at' => '2027-01-01 00:00:00']);

        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn($this->terminal());
        $this->terminalsRepository->expects($this->once())
            ->method('promotePendingToken')
            ->with('terminal-1', TokenService::hashToken($token))
            ->willReturn($promoted);
        $this->terminalsRepository->expects($this->once())->method('updateLastSync')->with('terminal-1');
        $this->auditService->expects($this->once())
            ->method('log')
            ->with(AuditAction::TERMINAL_TOKEN_ACTIVATED);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($this->callback(fn(ServerRequestInterface $request) => $request->getAttribute('terminal') === $promoted))
            ->willReturn(new Response(200));

        $response = $this->middleware->process($this->request("Bearer {$token}"), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Two syncs arriving together: the losing one finds the token already
     * installed and carries on rather than answering 401 to a request holding a
     * perfectly good credential.
     */
    public function test_process_authenticates_a_pending_token_another_request_promoted_first(): void
    {
        $terminal = $this->terminal();

        $this->terminalsRepository->method('findByTokenHash')
            ->willReturnOnConsecutiveCalls(null, $terminal);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn($terminal);
        $this->terminalsRepository->method('promotePendingToken')->willReturn(null);
        // The request that lost the race must not write a second activation.
        $this->auditService->expects($this->never())->method('log');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));

        $response = $this->middleware->process($this->request('Bearer staged-token'), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A pending token that was never entered and has since aged out is still a
     * token that expired — telling its operator "unknown token" would send them
     * hunting a typo in something that was never wrong.
     */
    public function test_process_reports_an_aged_out_pending_token_as_expired(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')
            ->willReturn($this->terminal(['token_expires_at' => null, 'pending_token_expires_at' => '2026-01-01 00:00:00']));
        $this->expectAttemptRecorded();

        $response = $this->middleware->process($this->request('Bearer stale-staged-token'), $this->rejectingHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('terminal_token_expired', $this->decode($response)['error']);
    }
}
