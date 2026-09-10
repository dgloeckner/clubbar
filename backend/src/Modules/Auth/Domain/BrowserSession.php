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

        // A browser that arrived just after a periodic rotation is holding the
        // tombstone, not the session (#340 follow-up). Destroying only what the
        // cookie names would leave the real session alive and reachable — which
        // is the bug this method exists to prevent, back again through a door
        // that opens for a minute after every rotation.
        self::destroyRotationSuccessor();

        $_SESSION = [];
        session_destroy();
        self::expireCookie($cookieName);

        return true;
    }

    /**
     * Destroy the session a tombstone forwards to, before the tombstone itself.
     *
     * Walks the chain rather than following one link: with a short rotation
     * interval a tombstone can point at a tombstone, and stopping at the first
     * would leave the real session standing — which is the whole failure this
     * guards against. Bounded, and refusing an ID it has already visited, so a
     * cycle no code here can write but a hand-edited session file could cannot
     * spin.
     *
     * Ends where it began: the session the browser's cookie actually names is
     * re-opened, because {@see endIfPresent()} still has to destroy that one and
     * expire the cookie for it.
     */
    private static function destroyRotationSuccessor(): void
    {
        $originalId = session_id();
        $currentId  = $originalId;
        $pointer    = $_SESSION;
        $visited    = [$originalId => true];

        for ($hop = 0; $hop < SessionRotation::MAX_HOPS; $hop++) {
            $successor = SessionRotation::successorWithinGrace($pointer);
            if ($successor === null || isset($visited[$successor])) {
                break;
            }

            session_write_close();
            session_id($successor);
            session_start();

            $visited[$successor] = true;
            $currentId = $successor;
            // Read before clearing: the successor may itself be a tombstone.
            $pointer = $_SESSION;

            $_SESSION = [];
            session_destroy();
        }

        if ($currentId === $originalId) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_id($originalId);
        session_start();
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
