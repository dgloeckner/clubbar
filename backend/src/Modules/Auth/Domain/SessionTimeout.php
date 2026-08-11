<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain;

/**
 * When an admin session has outlived its welcome.
 *
 * Pattern 013 has always promised two independent limits, and until #118 the
 * code enforced neither — a session lived as long as PHP's `session.gc_maxlifetime`
 * happened to allow, which on a shared host is whatever someone else configured.
 * The absolute limit is the one that matters: an idle timeout alone lets a
 * stolen cookie be kept alive forever by polling.
 *
 * The stamps live in the session itself so no server-side store is needed, and
 * the rules are expressed over a plain array so they can be checked without a
 * request, a cookie, or a running session.
 */
final class SessionTimeout
{
    /** Inactivity tolerated before the session dies. */
    public const IDLE_SECONDS = 7200; // 2 hours

    /** Total lifetime, however active the session is. */
    public const ABSOLUTE_SECONDS = 86400; // 24 hours

    /** Session key: when the session was fully authenticated. */
    public const AUTHENTICATED_AT = 'authenticated_at';

    /** Session key: when the session last carried a request. */
    public const LAST_ACTIVITY_AT = 'last_activity_at';

    /** Session key: when the session ID was last rotated. */
    public const REGENERATED_AT = 'regenerated_at';

    /**
     * Stamp a freshly authenticated session. Called once, when the second factor
     * has passed (or when there is none to pass).
     *
     * Also stamps `REGENERATED_AT`: login and the MFA upgrade both call
     * `session_regenerate_id(true)` immediately before this, so the periodic
     * rotation clock (#340) starts ticking from the same moment.
     *
     * @param array<string, mixed> $session
     */
    public static function begin(array &$session, ?int $now = null): void
    {
        $now ??= time();
        $session[self::AUTHENTICATED_AT] = $now;
        $session[self::LAST_ACTIVITY_AT] = $now;
        $session[self::REGENERATED_AT] = $now;
    }

    /**
     * Whether the session ID is due for periodic rotation (#340).
     *
     * A session with no stamp — either newly adopted or predating this rule —
     * is due immediately; `markRegenerated` then starts its clock.
     *
     * @param array<string, mixed> $session
     */
    public static function shouldRegenerateId(array $session, int $intervalSeconds, ?int $now = null): bool
    {
        $now ??= time();
        $last = $session[self::REGENERATED_AT] ?? null;

        return !is_int($last) || ($now - $last) >= $intervalSeconds;
    }

    /**
     * Record that the session ID was just rotated, restarting the interval.
     *
     * @param array<string, mixed> $session
     */
    public static function markRegenerated(array &$session, ?int $now = null): void
    {
        $session[self::REGENERATED_AT] = $now ?? time();
    }

    /**
     * Record activity, restarting the idle clock but never the absolute one.
     *
     * A session carrying no stamps predates this rule; it is adopted at `$now`
     * rather than being killed, so shipping the timeouts does not log everyone out.
     *
     * @param array<string, mixed> $session
     */
    public static function touch(array &$session, ?int $now = null): void
    {
        $now ??= time();
        if (!is_int($session[self::AUTHENTICATED_AT] ?? null)) {
            $session[self::AUTHENTICATED_AT] = $now;
        }
        $session[self::LAST_ACTIVITY_AT] = $now;
    }

    /**
     * Whether either limit has been reached. An unstamped session has not.
     *
     * @param array<string, mixed> $session
     */
    public static function hasExpired(array $session, ?int $now = null): bool
    {
        $now ??= time();

        $authenticatedAt = $session[self::AUTHENTICATED_AT] ?? null;
        if (is_int($authenticatedAt) && ($now - $authenticatedAt) >= self::ABSOLUTE_SECONDS) {
            return true;
        }

        $lastActivityAt = $session[self::LAST_ACTIVITY_AT] ?? null;

        return is_int($lastActivityAt) && ($now - $lastActivityAt) >= self::IDLE_SECONDS;
    }
}
