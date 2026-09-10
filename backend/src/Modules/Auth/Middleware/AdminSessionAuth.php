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
use App\Modules\Auth\Domain\SessionRotation;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

class AdminSessionAuth implements MiddlewareInterface
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private AppConfig $config,
        private AdminUserRolesRepository $adminUserRolesRepository,
        // Optional, and last, so the unit suite can build the middleware with
        // the three collaborators it actually exercises. Nothing here logs on
        // the happy path; the one call is the stale-tombstone notice below.
        private ?Logger $logger = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($this->config->sessionCookieName);
            session_start();
        }

        // A request that was in flight when this session's ID rotated — or one
        // whose rotation the browser never heard about, because the panel
        // cancelled it — arrives holding the previous ID. It finds a tombstone
        // rather than a deleted file, and is carried across to the real session
        // (#340 follow-up). Done before every check below, because a tombstone
        // is not the session those checks are about: it holds no admin, no
        // stamps and no roles.
        $this->followRotation();

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
            $this->rotateSessionId();
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
     * Follow a tombstone left by a recent rotation, if this request arrived on one.
     *
     * `session_start()` on the successor's ID makes PHP re-send the session
     * cookie, which is the half that matters: a browser that missed the
     * rotation — because the panel aborted the request carrying it — is holding
     * an ID it can never use again, and this is the only thing that hands it
     * the current one.
     *
     * Strict mode needs no relaxing here. The successor's file exists, so PHP
     * adopts the ID rather than refusing it; that is exactly the check strict
     * mode is for, and it is what stops this from becoming a way to have any
     * made-up ID honoured.
     *
     * The chain is walked rather than followed one link, because a successor
     * can have rotated again while this request was queued — every link is
     * checked against its own grace window, so walking further never extends
     * the window, it only declines to stop halfway. See
     * {@see SessionRotation::MAX_HOPS}.
     */
    private function followRotation(): void
    {
        if (SessionRotation::successorWithinGrace($_SESSION) === null) {
            if (SessionRotation::isStaleTombstone($_SESSION)) {
                $this->logger?->info('Session arrived on a rotated ID past its grace window', [
                    'grace_seconds' => SessionRotation::GRACE_SECONDS,
                ]);
            }

            return;
        }

        $visited = [session_id() => true];

        for ($hop = 0; $hop < SessionRotation::MAX_HOPS; $hop++) {
            $successor = SessionRotation::successorWithinGrace($_SESSION);
            if ($successor === null || isset($visited[$successor])) {
                return;
            }

            $visited[$successor] = true;
            session_write_close();
            session_id($successor);
            session_start();
        }
    }

    /**
     * Rotate the session ID, leaving a forwarding address rather than a hole.
     *
     * The old file is rewritten as a tombstone instead of being deleted, so a
     * request still carrying the old ID is forwarded rather than handed a fresh
     * empty session.
     *
     * The order is the point, and it is not the obvious one. The obvious
     * sequence — mint an ID with `session_create_id()`, tombstone the old
     * session, then start the new one — cannot work here: `use_strict_mode`
     * (ADR-0016) exists to refuse an ID with no file behind it, which is
     * exactly what a freshly created ID is, so PHP discards the successor and
     * invents a different one, stranding the tombstone. Turning the directive
     * off for that one call is what PHP's manual suggests, and it is worse:
     * `ini_set()` on a session directive is refused while a session is active,
     * so the restore afterwards silently does nothing and strict mode stays off
     * for the rest of the request. On `/api/admin/security-check` that is not
     * even quiet — the self-check reads the live value and reports the
     * hardening as missing.
     *
     * `session_regenerate_id(false)` mints it instead. PHP creates the
     * successor's file itself, and `false` keeps the old one, after which every
     * `session_start()` below names an ID that exists and strict mode is never
     * in the way. Re-opening the old session to overwrite it costs two extra
     * opens per rotation interval and does not disturb the cookie: PHP replaces
     * the session cookie header rather than appending, so the response carries
     * one `Set-Cookie`, naming the successor.
     *
     * `session_write_close()` is what persists the tombstone — and it releases
     * the session lock with it, so a request queued behind this one is
     * forwarded the moment the tombstone is written rather than after this
     * request has finished.
     */
    private function rotateSessionId(): void
    {
        $previousId = session_id();
        if ($previousId === false || $previousId === '') {
            return;
        }

        // Mints the successor and writes its file, keeping the old one.
        if (session_regenerate_id(false) === false) {
            return;
        }

        $successorId = session_id();
        $data        = $_SESSION;

        session_write_close();
        session_id($previousId);
        session_start();
        $_SESSION = SessionRotation::tombstone($successorId);
        session_write_close();

        session_id($successorId);
        session_start();
        $_SESSION = $data;
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
