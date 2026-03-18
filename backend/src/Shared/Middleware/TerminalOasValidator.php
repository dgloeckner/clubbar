<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps league/openapi-psr7-validator and only activates it for terminal API paths.
 *
 * Admin routes (/api/admin/*) are not in terminal.yaml and must be passed through
 * untouched. Without this guard, the OAS validator throws NoPath on any admin route.
 */
class TerminalOasValidator implements MiddlewareInterface
{
    private const TERMINAL_PREFIXES = [
        '/api/health',
        '/api/sync',
        '/api/terminal',
    ];

    public function __construct(
        private readonly MiddlewareInterface $validator
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $path = $request->getUri()->getPath();

        foreach (self::TERMINAL_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $this->validator->process($request, $handler);
            }
        }

        // Not a terminal path — bypass OAS validation entirely
        return $handler->handle($request);
    }
}
