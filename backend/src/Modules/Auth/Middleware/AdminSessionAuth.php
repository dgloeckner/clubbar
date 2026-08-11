<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Config\AppConfig;
use Slim\Psr7\Response;

class AdminSessionAuth implements MiddlewareInterface
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private AppConfig $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($this->config->sessionCookieName);
            session_start();
        }

        $adminId = $_SESSION['admin_user_id'] ?? null;
        if (!$adminId) {
            return $this->unauthorized();
        }

        // Pattern 013's two limits: 2h idle, 24h absolute. Checked before the
        // session is touched, so a request cannot extend a session it arrived
        // too late for.
        if (SessionTimeout::hasExpired($_SESSION)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            return $this->sessionExpired();
        }

        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin || !(bool) $admin['is_active']) {
            return $this->unauthorized();
        }

        SessionTimeout::touch($_SESSION);

        // Periodic session-ID rotation (#340): limits how long a leaked ID stays
        // usable in a long-lived session. Checked after the expiry check above,
        // so an expired session is destroyed rather than rotated.
        if (SessionTimeout::shouldRegenerateId($_SESSION, $this->config->sessionRegenInterval)) {
            session_regenerate_id(true);
            SessionTimeout::markRegenerated($_SESSION);
        }

        // Block access for authenticated-but-not-enrolled users, except on setup/confirm routes
        if (($_SESSION['totp_setup_required'] ?? false) === true) {
            $path = $request->getUri()->getPath();
            $exempted = ['/api/auth/2fa/setup', '/api/auth/2fa/confirm'];
            if (!in_array($path, $exempted, true)) {
                return $this->totpSetupRequired();
            }
        }

        // Attach admin data to request attributes
        $request = $request->withAttribute('admin_user_id', $adminId);
        $request = $request->withAttribute('admin_user', $admin);

        return $handler->handle($request)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function unauthorized(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => 'admin_not_authenticated']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function sessionExpired(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode([
            'error' => 'session_expired',
            'message' => 'Your session has expired. Please sign in again.',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function totpSetupRequired(): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write(json_encode([
            'error' => 'totp_setup_required',
            'message' => 'Two-factor authentication setup is required before accessing the admin panel.',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
