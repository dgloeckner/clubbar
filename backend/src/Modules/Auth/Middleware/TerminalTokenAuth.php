<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Auth\Services\TokenService;
use Slim\Psr7\Response;

class TerminalTokenAuth implements MiddlewareInterface
{
    /**
     * @param LoginAttemptsRepository $authAttempts scoped to `terminal_auth_attempts`,
     *        which has no `email` column — attempts are recorded by IP alone.
     */
    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private LoginAttemptsRepository $authAttempts,
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
            // A token that was valid until its lifetime ran out gets its own
            // answer (#106): the terminal is not misconfigured, it needs an
            // admin to rotate it. Nothing is disclosed to a caller that does
            // not already hold the token.
            if ($this->isExpiredToken($token)) {
                return $this->unauthorized(
                    $request,
                    'terminal_token_expired',
                    'Terminal token has expired. Rotate the token in the admin panel to issue a new one.'
                );
            }

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
        // Direct SHA256 lookup: O(1) DB lookup, no per-terminal iteration.
        // The repository, not this middleware, refuses an expired token — see
        // TerminalsRepository::findByTokenHash().
        $sha256 = TokenService::hashToken($token);
        return $this->terminalsRepository->findByTokenHash($sha256);
    }

    private function isExpiredToken(string $token): bool
    {
        $sha256 = TokenService::hashToken($token);
        return $this->terminalsRepository->findExpiredByTokenHash($sha256) !== null;
    }

    private function unauthorized(ServerRequestInterface $request, string $code, string $message): ResponseInterface
    {
        $this->authAttempts->record($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1');

        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => $code, 'message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
