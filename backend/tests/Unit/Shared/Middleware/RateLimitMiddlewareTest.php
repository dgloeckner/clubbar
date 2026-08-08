<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Middleware;

use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Shared\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Two counting dimensions, one gate (ruling #145).
 *
 * Per-IP alone gives a club's shared WiFi a single budget for several admins
 * while a distributed attacker never trips it; per-account alone lets one host
 * cycle through admin1@, admin2@, admin3@ unimpeded. The middleware blocks when
 * *either* dimension is exhausted.
 */
class RateLimitMiddlewareTest extends TestCase
{
    private LoginAttemptsRepository $attempts;

    protected function setUp(): void
    {
        $this->attempts = $this->createMock(LoginAttemptsRepository::class);
    }

    private function request(string $ip = '203.0.113.7'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/login', ['REMOTE_ADDR' => $ip]);
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));
        return $handler;
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    private function emailResolver(?string $email): \Closure
    {
        return static fn(): ?string => $email;
    }

    public function test_passes_through_below_both_limits(): void
    {
        $this->attempts->method('countRecentByIp')->willReturn(4);
        $this->attempts->method('countRecentByEmail')->willReturn(4);

        $middleware = new RateLimitMiddleware($this->attempts, 5, 15, false, $this->emailResolver('admin@example.com'));

        $this->assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }

    public function test_blocks_when_the_ip_dimension_is_exhausted(): void
    {
        $this->attempts->method('countRecentByIp')->willReturn(5);

        $middleware = new RateLimitMiddleware($this->attempts, 5, 15, false, $this->emailResolver('admin@example.com'));
        $response = $middleware->process($this->request(), $this->handler());

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('too_many_attempts', $this->decode($response)['error']);
    }

    public function test_blocks_when_only_the_account_dimension_is_exhausted(): void
    {
        // A distributed attack: every request from a fresh IP, all against one admin.
        $this->attempts->method('countRecentByIp')->willReturn(0);
        $this->attempts->method('countRecentByEmail')->willReturn(5);

        $middleware = new RateLimitMiddleware($this->attempts, 5, 15, false, $this->emailResolver('admin@example.com'));

        $this->assertSame(429, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }

    public function test_keeps_the_existing_429_shape(): void
    {
        $this->attempts->method('countRecentByIp')->willReturn(5);

        $middleware = new RateLimitMiddleware($this->attempts, 5, 15);
        $response = $middleware->process($this->request(), $this->handler());

        $body = $this->decode($response);
        $this->assertSame('too_many_attempts', $body['error']);
        $this->assertSame(900, $body['retry_after_seconds']);
        $this->assertSame('900', $response->getHeaderLine('Retry-After'));
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_without_a_resolver_only_the_ip_dimension_is_queried(): void
    {
        // Terminal token auth has no account to name, and its table has no email column.
        $this->attempts->method('countRecentByIp')->willReturn(0);
        $this->attempts->expects($this->never())->method('countRecentByEmail');

        $middleware = new RateLimitMiddleware($this->attempts, 10, 15);

        $this->assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }

    public function test_an_unresolvable_account_falls_back_to_the_ip_dimension(): void
    {
        // e.g. a login request with no email field, or an MFA request with no pending session.
        $this->attempts->method('countRecentByIp')->willReturn(0);
        $this->attempts->expects($this->never())->method('countRecentByEmail');

        $middleware = new RateLimitMiddleware($this->attempts, 5, 15, false, $this->emailResolver(null));

        $this->assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }

    public function test_disabled_middleware_queries_nothing(): void
    {
        $this->attempts->expects($this->never())->method('countRecentByIp');
        $this->attempts->expects($this->never())->method('countRecentByEmail');

        $middleware = new RateLimitMiddleware($this->attempts, 5, 15, true, $this->emailResolver('admin@example.com'));

        $this->assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }
}
