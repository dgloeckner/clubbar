<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Auth\Domain\RouteRoleMap;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Config\AppConfig;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

class AdminSessionAuth implements MiddlewareInterface
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private AppConfig $config,
        private AdminUserRolesRepository $adminUserRolesRepository,
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

        // A credential change ends every session that predates it — including
        // an attacker's, which is the point. The acting session survives
        // because the service that wrote the epoch re-stamped it (#337).
        if (SessionTimeout::predatesCredentialChange($_SESSION, $admin['credentials_changed_at'] ?? null)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            return $this->credentialsChanged();
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

        // The role gate (ADR-0044). Last of the checks, so a caller who is not
        // really signed in is told that rather than being told their office is
        // wrong — and so a demoted admin's stale session still gets the
        // session-level answers it is entitled to.
        $roles = $this->adminUserRolesRepository->rolesFor($adminId);
        if (!$this->permitted($request, $roles)) {
            return $this->insufficientRole();
        }

        // Attach admin data to request attributes
        $request = $request->withAttribute('admin_user_id', $adminId);
        $request = $request->withAttribute('admin_user', $admin);
        $request = $request->withAttribute('admin_roles', $roles);

        return $handler->handle($request)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Ask the map, keyed on the route *pattern* Slim matched rather than on
     * the concrete path.
     *
     * The pattern is what `routes.php` registered — `/api/admin/members/{memberId}`
     * — so the map is a transcription of that file and there is no path
     * matching here to get subtly wrong: no regex, no prefix that accidentally
     * covers a route nobody classified.
     *
     * A request that reached this middleware with no matched route cannot
     * happen through Slim's routing, and if it ever does it is treated as an
     * unmapped route — `admin`-only. Fail closed applies to the lookup itself,
     * not only to its table.
     *
     * @param list<AdminRole> $roles
     */
    private function permitted(ServerRequestInterface $request, array $roles): bool
    {
        $route = $request->getAttribute(RouteContext::ROUTE);
        $pattern = $route instanceof RouteInterface ? $route->getPattern() : '';

        return RouteRoleMap::permits($roles, $request->getMethod(), $pattern);
    }

    /**
     * 403, and its own error code.
     *
     * Distinct from `admin_not_authenticated` because the remedy is different
     * in kind: signing in again fixes nothing, and the SPA has to choose
     * between a login screen and the named "this section is not available for
     * your role" page (ADR-0044). The message says nothing about which role
     * would have been enough — a refusal is not a place to describe the shape
     * of what the caller is not trusted with.
     */
    private function insufficientRole(): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write(json_encode([
            'error' => 'insufficient_role',
            'message' => 'This section is not available for your role.',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
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

    /**
     * Distinct from `session_expired` because the cause and the remedy differ:
     * nothing timed out, a credential moved underneath this session, and the
     * admin needs to know the new one is what signs them back in.
     */
    private function credentialsChanged(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode([
            'error' => 'credentials_changed',
            'message' => 'Your credentials were changed. Please sign in again.',
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
