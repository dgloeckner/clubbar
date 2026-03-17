<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Auth\Services\TokenService;
use Slim\Psr7\Response;

class TerminalTokenAuth implements MiddlewareInterface
{
    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private PDO $pdo,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return $this->unauthorized($request, 'authorization_header_missing', 'Authorization header required');
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized($request, 'invalid_authorization_format', 'Expected Bearer token');
        }

        $token = substr($authHeader, 7);
        $terminal = $this->findTerminalByToken($token);

        if (!$terminal) {
            return $this->unauthorized($request, 'invalid_terminal_token', 'Invalid terminal token');
        }

        if (!(bool) $terminal['is_active']) {
            return $this->unauthorized($request, 'terminal_inactive', 'Terminal is inactive');
        }

        // Update last sync timestamp
        $this->terminalsRepository->updateLastSync($terminal['id']);

        $request = $request->withAttribute('terminal_id', $terminal['id']);
        $request = $request->withAttribute('terminal', $terminal);

        return $handler->handle($request);
    }

    private function findTerminalByToken(string $token): ?array
    {
        // Direct SHA256 lookup: O(1) DB lookup, no per-terminal iteration
        $sha256 = TokenService::hashToken($token);
        return $this->terminalsRepository->findByTokenHash($sha256);
    }

    private function unauthorized(ServerRequestInterface $request, string $code, string $message): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $this->pdo->prepare(
            'INSERT INTO terminal_auth_attempts (ip_address) VALUES (:ip)'
        );
        $stmt->execute(['ip' => $ip]);

        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => $code, 'message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
