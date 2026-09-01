<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain;

/**
 * Ending whatever admin session the *browser* is holding, from a route that is
 * not itself behind {@see \App\Modules\Auth\Middleware\AdminSessionAuth}.
 *
 * There is exactly one situation that needs this: a public endpoint that hands
 * the browser a new identity. `POST /api/invitations/accept` is that endpoint —
 * an invitee frequently follows their link in a browser somebody else is
 * already signed in to (the admin who invited them, on the club's own laptop),
 * and until #798 accepting there left that other session untouched. The panel
 * then sent the invitee to `/login`, which redirects an authenticated browser
 * to the dashboard, and the new admin landed inside the *inviting* admin's
 * account without ever entering a password.
 *
 * The session is destroyed server-side **and** the cookie is expired, rather
 * than only one of the two. Destroying alone leaves the browser presenting a
 * dead id on every request — harmless, because `session.use_strict_mode`
 * (ADR-0016) refuses to adopt an uninitialised id, but indistinguishable from a
 * live session to anything reading the request. Expiring alone would leave the
 * session file usable by anybody who has the id.
 */
final class BrowserSession
{
    /**
     * End the session this request arrived with, if it arrived with one.
     *
     * @param string $cookieName The configured session cookie name
     *                           ({@see \App\Shared\Config\AppConfig::$sessionCookieName}).
     * @return bool Whether there was a session to end — for the caller's log,
     *              never for its control flow.
     */
    public static function endIfPresent(string $cookieName): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // No cookie, no session: starting one here would create the very
            // thing this method exists to remove.
            if (!isset($_COOKIE[$cookieName])) {
                return false;
            }

            session_name($cookieName);
            session_start();
        }

        $_SESSION = [];
        session_destroy();
        self::expireCookie($cookieName);

        return true;
    }

    /**
     * Tell the browser to drop the cookie.
     *
     * The attributes have to match the ones the session cookie was set with
     * (`RuntimeHardening::applySessionDirectives()`) or the browser keeps it —
     * and under the `__Host-` prefix the configuration picks on HTTPS, a
     * deletion that omits `Secure` or `Path=/` is rejected outright.
     */
    private static function expireCookie(string $cookieName): void
    {
        // headers_sent() is false in a real request and true under PHPUnit,
        // which has already written to stdout by the time a test gets here.
        if (headers_sent()) {
            return;
        }

        $params = session_get_cookie_params();

        setcookie($cookieName, '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'] ?: '/',
            'secure'   => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }
}
