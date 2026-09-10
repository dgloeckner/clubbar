<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Middleware;

use App\Shared\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Who may read an API response (#875).
 *
 * The middleware was always able to answer this correctly; what it was given
 * was `['*']`, because that was `CORS_ORIGINS`' default and a package install
 * had no way to set the variable at all. These are the cases that hold once it
 * is handed a real list.
 */
class CorsMiddlewareTest extends TestCase
{
    public function test_an_allowed_origin_is_echoed_back_with_credentials(): void
    {
        $response = $this->dispatch(['https://panel.club.de'], 'https://panel.club.de');

        self::assertSame('https://panel.club.de', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    /**
     * Not a wildcard, not the requested origin, nothing at all: without the
     * header the browser refuses the response, which is the whole mechanism.
     */
    public function test_an_unknown_origin_gets_no_header_at_all(): void
    {
        $response = $this->dispatch(['https://panel.club.de'], 'https://evil.example.com');

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    /**
     * An empty list is what an installation with an unusable `APP_URL` gets.
     * It answers nobody rather than everybody (ADR-0031 rule 3).
     */
    public function test_an_empty_list_answers_no_cross_origin_request(): void
    {
        $response = $this->dispatch([], 'https://panel.club.de');

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * Still possible, because an operator can still ask for it — and still
     * without credentials, because a browser would refuse them anyway.
     */
    public function test_a_deliberate_wildcard_still_works_and_never_carries_credentials(): void
    {
        $response = $this->dispatch(['*'], 'https://anywhere.example.com');

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    /**
     * The response now depends on the request's Origin. A shared cache that is
     * not told so serves the first caller's `Access-Control-Allow-Origin` to
     * the next one — including serving an allowed origin's headers to a
     * refused one.
     */
    public function test_a_response_that_depends_on_the_origin_says_so(): void
    {
        self::assertSame('Origin', $this->dispatch(['https://panel.club.de'], 'https://panel.club.de')->getHeaderLine('Vary'));
        self::assertSame('Origin', $this->dispatch(['https://panel.club.de'], 'https://evil.example.com')->getHeaderLine('Vary'));

        self::assertFalse(
            $this->dispatch(['*'], 'https://anywhere.example.com')->hasHeader('Vary'),
            'A wildcard answer is the same for every caller — nothing varies'
        );
    }

    /**
     * `withHeader()` would replace whatever a handler had already varied on,
     * and the field it replaced would be the one that mattered.
     */
    public function test_it_adds_to_an_existing_vary_rather_than_replacing_it(): void
    {
        $response = $this->dispatch(
            ['https://panel.club.de'],
            'https://panel.club.de',
            (new Response())->withHeader('Vary', 'Accept-Language')
        );

        self::assertSame('Accept-Language,Origin', $response->getHeaderLine('Vary'));
    }

    public function test_it_does_not_repeat_a_vary_field_the_handler_already_set(): void
    {
        $response = $this->dispatch(
            ['https://panel.club.de'],
            'https://panel.club.de',
            (new Response())->withHeader('Vary', 'origin')
        );

        self::assertSame('origin', $response->getHeaderLine('Vary'));
    }

    /**
     * A preflight is answered by the middleware itself — the route behind it
     * is never reached, so an OPTIONS request must not need one to exist.
     */
    public function test_a_preflight_is_answered_without_reaching_the_route(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/api/health')
            ->withHeader('Origin', 'https://panel.club.de');

        $response = (new CorsMiddleware(['https://panel.club.de']))->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://panel.club.de', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * @param list<string> $allowed
     */
    private function dispatch(array $allowed, string $origin, ?ResponseInterface $from = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/health')
            ->withHeader('Origin', $origin);

        return (new CorsMiddleware($allowed))->process($request, $this->handlerReturning($from ?? new Response()));
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
