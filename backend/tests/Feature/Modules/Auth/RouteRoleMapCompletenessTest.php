<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Modules\Auth\Domain\RouteRoleMap;
use Slim\Interfaces\RouteInterface;
use Tests\Feature\HttpTestCase;

/**
 * The whole model as a single fact: every session-authenticated route is
 * classified, and every classification names a route that exists (ADR-0044,
 * #519).
 *
 * This is the test that cannot be written against per-controller checks, and it
 * is the reason enforcement is a table rather than sixty `if` statements. The
 * runtime already fails closed — an unmapped route is `admin`-only — so what
 * this adds is *noticing*: an unclassified route is a decision nobody made, and
 * a decision nobody made should fail a build rather than quietly narrow the
 * panel for two of the three roles.
 *
 * It reads the routes from the real collector, not from a copy, so adding a
 * route to `routes.php` is what makes it fail.
 */
class RouteRoleMapCompletenessTest extends HttpTestCase
{
    /**
     * The two groups behind `AdminSessionAuth`. Everything else — `/api/sync`
     * (terminal bearer tokens), `/api/health`, `/api/cron/drain`, the public
     * instance branding, login and MFA — authenticates differently or not at
     * all, and a role entry for it would be a claim this map does not make.
     */
    private const SESSION_PREFIXES = ['/api/admin', '/api/auth'];

    /**
     * The two routes under `/api/auth` that are *not* behind the session
     * middleware: they are what produces a session, so there is no caller to
     * hold a role yet.
     *
     * Named individually rather than pattern-matched. Adding a third public
     * route under this prefix should require saying so here, in a list a
     * reviewer reads, rather than passing silently because it happened to
     * resemble these two.
     */
    private const PUBLIC_AUTH_ROUTES = [
        'POST /api/auth/login',
        'POST /api/auth/mfa',
    ];

    public function test_every_session_authenticated_route_has_an_entry(): void
    {
        $missing = [];

        foreach ($this->sessionRouteKeys() as $key) {
            [$method, $pattern] = explode(' ', $key, 2);
            if (!RouteRoleMap::hasEntry($method, $pattern)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These routes are registered but not classified in RouteRoleMap:\n  %s\n\n"
            . "They currently answer 403 for every role but `admin`, which is the safe default and "
            . "probably not the intended grant. Add each to the map with the roles ADR-0044's grant "
            . "table gives it — `admin`-only is a valid answer, it just has to be written down.",
            implode("\n  ", $missing),
        ));
    }

    /**
     * The other direction. A stale entry is not dangerous — nothing routes to
     * it — but it is a grant that reads as live, and the next person to change
     * the table will reason from it.
     */
    public function test_every_entry_names_a_route_that_exists(): void
    {
        $registered = $this->sessionRouteKeys();

        $stale = array_values(array_diff(RouteRoleMap::keys(), $registered));

        $this->assertSame([], $stale, sprintf(
            "These RouteRoleMap entries name no registered route:\n  %s\n\n"
            . "A route was renamed, moved or removed and the map was not. Update the key or drop it.",
            implode("\n  ", $stale),
        ));
    }

    /**
     * `admin` must reach today exactly what it reached yesterday. ADR-0044
     * promises the migration is behaviour-preserving, and this is the half of
     * that promise which lives in the map rather than in the schema.
     */
    public function test_admin_reaches_every_registered_route(): void
    {
        foreach ($this->sessionRouteKeys() as $key) {
            [$method, $pattern] = explode(' ', $key, 2);

            $this->assertTrue(
                RouteRoleMap::permits([\App\Modules\AdminUsers\Enums\AdminRole::ADMIN], $method, $pattern),
                "admin lost access to {$key}",
            );
        }
    }

    /**
     * Nothing outside the session groups may appear. `/api/sync/*` is
     * ADR-0044's explicit non-goal, and an entry for it would suggest roles
     * apply to terminals.
     */
    public function test_the_map_classifies_nothing_outside_the_session_groups(): void
    {
        foreach (RouteRoleMap::keys() as $key) {
            [, $pattern] = explode(' ', $key, 2);

            $this->assertTrue(
                $this->isSessionRoute($pattern),
                "{$key} is outside the admin-session groups — roles do not apply to it",
            );
        }
    }

    /**
     * Every `"METHOD /pattern"` key Slim has registered behind the session
     * middleware.
     *
     * @return list<string>
     */
    private function sessionRouteKeys(): array
    {
        $keys = [];

        /** @var RouteInterface $route */
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            if (!$this->isSessionRoute($pattern)) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $key = strtoupper($method) . ' ' . $pattern;
                if ($method === 'OPTIONS' || in_array($key, self::PUBLIC_AUTH_ROUTES, true)) {
                    continue;
                }
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    private function isSessionRoute(string $pattern): bool
    {
        foreach (self::SESSION_PREFIXES as $prefix) {
            if (str_starts_with($pattern, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
