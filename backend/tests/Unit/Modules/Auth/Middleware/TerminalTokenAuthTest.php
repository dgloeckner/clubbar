<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Middleware;

use App\Modules\Auth\Middleware\TerminalTokenAuth;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Terminals\Repositories\TerminalsRepository;
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
    private TerminalTokenAuth $middleware;

    protected function setUp(): void
    {
        $this->terminalsRepository = $this->createMock(TerminalsRepository::class);
        $this->authAttempts = $this->createMock(LoginAttemptsRepository::class);
        $this->middleware = new TerminalTokenAuth($this->terminalsRepository, $this->authAttempts);
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
        return array_merge(['id' => 'terminal-1', 'is_active' => 1], $overrides);
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
        $this->expectAttemptRecorded();

        $response = $this->middleware->process($this->request('Bearer unknown-token'), $this->rejectingHandler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_terminal_token', $this->decode($response)['error']);
    }

    public function test_process_looks_up_the_terminal_by_sha256_of_the_bearer_token(): void
    {
        $token = 'my-plain-token';
        $this->terminalsRepository->expects($this->once())
            ->method('findByTokenHash')
            ->with(TokenService::hashToken($token))
            ->willReturn(null);
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
}
