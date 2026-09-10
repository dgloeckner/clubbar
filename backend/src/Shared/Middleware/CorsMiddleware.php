<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Decides which browser origins may read an API response.
 *
 * The list comes from {@see \App\Shared\Config\AppConfig::$corsAllowedOrigins},
 * which defaults to the installation's own origin — never `*` (#875). A
 * wildcard remains possible, because an operator can still ask for one, but it
 * is a stated choice rather than what a deployment gets by saying nothing.
 */
class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedOrigins;

    /**
     * @param list<string> $allowedOrigins Origins as a browser sends them
     *        (`https://panel.club.de`), or the single entry `*`. Empty answers
     *        no cross-origin request at all.
     */
    public function __construct(array $allowedOrigins = ['*'])
    {
        $this->allowedOrigins = array_values($allowedOrigins);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Handle preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(204);
        } else {
            $response = $handler->handle($request);
        }

        $wildcard = in_array('*', $this->allowedOrigins, true);
        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = $wildcard ? '*' : (in_array($origin, $this->allowedOrigins, true) ? $origin : '');

        // Anything but a wildcard means this response depends on the request's
        // Origin, and a shared cache that does not know that would hand one
        // site's `Access-Control-Allow-Origin` to the next one to ask. Set
        // whether or not the origin was allowed: the *refusal* is origin-
        // dependent too, and caching that as the answer for everyone is the
        // same bug pointing the other way.
        if (!$wildcard) {
            $response = self::varyOn($response, 'Origin');
        }

        if ($allowOrigin) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token')
                ->withHeader('Access-Control-Max-Age', '86400');

            // Credentials (cookies/session) only work with a specific origin — never with wildcard.
            // https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS#requests_with_credentials
            if ($allowOrigin !== '*') {
                $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
            }
        }

        return $response;
    }

    /**
     * Add one field to `Vary` without discarding what a handler already put
     * there — `withHeader()` would replace it, and the field it replaced would
     * be the one that mattered.
     */
    private static function varyOn(ResponseInterface $response, string $field): ResponseInterface
    {
        foreach (explode(',', $response->getHeaderLine('Vary')) as $existing) {
            if (strcasecmp(trim($existing), $field) === 0) {
                return $response;
            }
        }

        return $response->withAddedHeader('Vary', $field);
    }
}
