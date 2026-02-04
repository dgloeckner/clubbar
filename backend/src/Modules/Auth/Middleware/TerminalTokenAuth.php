<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Auth\Services\TokenService;
use Slim\Psr7\Response;

class TerminalTokenAuth implements MiddlewareInterface
{
    public function __construct(private TerminalsRepository $terminalsRepository) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return $this->unauthorized('authorization_header_missing', 'Authorization header required');
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('invalid_authorization_format', 'Expected Bearer token');
        }

        $token = substr($authHeader, 7);
        $terminal = $this->findTerminalByToken($token);

        if (!$terminal) {
            return $this->unauthorized('invalid_terminal_token', 'Invalid terminal token');
        }

        if (!(bool) $terminal['is_active']) {
            return $this->unauthorized('terminal_inactive', 'Terminal is inactive');
        }

        // Update last sync timestamp
        $this->terminalsRepository->updateLastSync($terminal['id']);

        $request = $request->withAttribute('terminal_id', $terminal['id']);
        $request = $request->withAttribute('terminal', $terminal);

        return $handler->handle($request);
    }

    private function findTerminalByToken(string $token): ?array
    {
        // Must check all active terminals since bcrypt requires individual verification
        $terminals = $this->terminalsRepository->findActive();
        foreach ($terminals as $terminal) {
            if (!empty($terminal['api_token_hash']) && TokenService::verifyToken($token, $terminal['api_token_hash'])) {
                return $terminal;
            }
        }
        return null;
    }

    private function unauthorized(string $code, string $message): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => $code, 'message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
