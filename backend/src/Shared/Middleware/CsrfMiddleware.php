<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $method = $request->getMethod();

        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $token = $request->getHeaderLine('X-CSRF-Token');
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (empty($sessionToken) || empty($token) || !hash_equals($sessionToken, $token)) {
            $response = new SlimResponse(403);
            $response->getBody()->write(json_encode([
                'error' => 'csrf_validation_failed',
                'message' => 'CSRF token missing or invalid',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
