<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CorsMiddleware implements MiddlewareInterface
{
    private array $allowedOrigins;

    public function __construct(array $allowedOrigins = ['*'])
    {
        $this->allowedOrigins = $allowedOrigins;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Handle preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(204);
        } else {
            $response = $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = in_array('*', $this->allowedOrigins) ? '*' : (in_array($origin, $this->allowedOrigins) ? $origin : '');

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
}
