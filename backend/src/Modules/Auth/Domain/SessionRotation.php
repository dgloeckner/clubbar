<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain;

/**
 * Carrying a session across a periodic ID rotation without ending it.
 *
 * The rotation of #340 replaced the session ID every `SESSION_REGEN_INTERVAL`
 * seconds with `session_regenerate_id(true)` — the `true` deleting the old
 * session file there and then. With `session.use_strict_mode` on (ADR-0016)
 * that deletion is not a tidy-up, it is a trapdoor: any request still carrying
 * the previous ID finds no file, is refused adoption, and is handed a *fresh
 * empty session* instead. Its `Set-Cookie` then writes that empty session into
 * the browser, so the tab is not merely refused once — it is pinned to a
 * session that will never authenticate.
 *
 * Two entirely ordinary things in the admin panel trigger it:
 *
 *   - **Concurrency.** Opening a page fires several requests at once (a list
 *     query and a profile read, plus the dashboard's ten-second poll). PHP
 *     serialises them on the session lock; whichever one rotates deletes the
 *     file out from under the others.
 *   - **A cancelled request.** The panel aborts superseded requests. PHP still
 *     runs the rotation to completion, but the browser never receives the new
 *     cookie — so it keeps presenting an ID the server has already destroyed.
 *
 * The fix is the one PHP's own manual describes: do not delete, *forward*. The
 * old session is reduced to a **tombstone** — the successor's ID and the moment
 * of rotation, and nothing else — and a request arriving on the old ID within
 * {@see GRACE_SECONDS} follows that pointer to the real session. PHP re-sends
 * the cookie as it goes, which is what repairs a browser that missed the
 * rotation.
 *
 * A tombstone is not a login. It carries no `admin_user_id`, no `csrf_token`
 * and none of {@see SessionTimeout}'s stamps, so the worst a leaked
 * pre-rotation ID buys is the successor it was already one response away from
 * — and only for the grace window, after which it is refused like any other
 * unauthenticated session.
 *
 * The window is deliberately a **non-consuming bearer window**: it is not
 * marked used on its first hop, and nothing binds a hop to who is presenting
 * it (#898). Both are accepted rather than fixed. Binding to the User-Agent
 * was considered and rejected outright — trivially replayed by anyone holding
 * the cookie, and it breaks a legitimate browser update mid-session. Making
 * the tombstone single-use was considered and rejected on evidence, not
 * intuition: `e2etests/tests/api/session-rotation.spec.ts` ("serves every
 * request of a burst that straddles a rotation") asserts that every request of
 * a concurrent burst arriving on the same rotated-away ID is served, which is
 * exactly the shape a page load produces (a list query and a profile read
 * racing the dashboard poll) and exactly what single-use would refuse for
 * every request but the one PHP's session lock lets through first. What is
 * left, per the amendment to ADR-0025, is bounded: the hop only ever lands
 * where the tombstone already pointed, for a window sized to milliseconds of
 * in-flight requests, not a session lifetime.
 *
 * The rules live here, over a plain array, so they can be checked without a
 * request, a cookie or a running session; the raw session calls they describe
 * are in {@see \App\Modules\Auth\Middleware\AdminSessionAuth}.
 */
final class SessionRotation
{
    /**
     * How long a rotated-away ID may still be exchanged for its successor.
     *
     * It only has to cover requests that were already in flight when the
     * rotation happened (milliseconds) and the browser's next request after a
     * cancelled one (a page navigation, so well under a second). Ten seconds is
     * generous for both — #898 tightened this down from the original 60,
     * which cost nothing this window needs to cover but bought an attacker
     * holding a leaked pre-rotation cookie six times as long a hop, renewed on
     * every rotation for the life of the session. See the class docblock for
     * why the window is not also made single-use.
     */
    public const GRACE_SECONDS = 10;

    /**
     * How far a chain of tombstones is followed before giving up.
     *
     * A chain forms whenever a session rotates again while an earlier
     * tombstone is still inside its grace window — that is, whenever
     * `SESSION_REGEN_INTERVAL` is shorter than {@see GRACE_SECONDS}. An
     * installation's 900 seconds is far longer, so chains are a test-stack and
     * misconfiguration concern rather than an everyday one; following a few
     * links costs nothing and refusing to follow any would reintroduce the
     * sign-out on exactly those installs. Bounded so that a cycle — which no
     * code here can write, but a hand-edited session file could — cannot spin.
     */
    public const MAX_HOPS = 8;

    /** Session key: the ID this session was rotated into. */
    public const SUCCESSOR_ID = 'rotated_to';

    /** Session key: when that rotation happened. */
    public const ROTATED_AT = 'rotated_at';

    /**
     * Everything the old session is reduced to when it is rotated away.
     *
     * Deliberately built from nothing rather than by unsetting keys from the
     * live session: a tombstone must carry no authentication data, and the way
     * to guarantee that is to never copy any in. A key added to the session
     * later cannot leak into it by being forgotten here.
     *
     * @return array<string, mixed>
     */
    public static function tombstone(string $successorId, ?int $now = null): array
    {
        return [
            self::SUCCESSOR_ID => $successorId,
            self::ROTATED_AT   => $now ?? time(),
        ];
    }

    /**
     * The successor to hop to, or null when there is nowhere to hop.
     *
     * Null covers three different situations on purpose, because the caller
     * treats them identically — an ordinary session, a tombstone whose grace
     * has run out, and a malformed one. Only the second is interesting enough
     * to log, and {@see isStaleTombstone()} is what asks about it.
     *
     * @param array<string, mixed> $session
     */
    public static function successorWithinGrace(array $session, ?int $now = null): ?string
    {
        $successor = $session[self::SUCCESSOR_ID] ?? null;
        $rotatedAt = $session[self::ROTATED_AT] ?? null;

        if (!is_string($successor) || $successor === '' || !is_int($rotatedAt)) {
            return null;
        }

        // A stamp from the future is a clock that moved backwards, not a
        // licence: treat it as expired rather than as indefinitely fresh.
        $age = ($now ?? time()) - $rotatedAt;
        if ($age < 0 || $age >= self::GRACE_SECONDS) {
            return null;
        }

        return $successor;
    }

    /**
     * A tombstone that is no longer honoured — for the log line, never for
     * control flow.
     *
     * Worth naming because of how it reads without one. The request is refused
     * as `admin_not_authenticated`, which is also what a browser with no
     * session at all is told, and the two have very different causes: this one
     * means a tab sat untouched across a rotation for longer than the grace
     * window. Saying so in the day's log is the difference between diagnosing
     * that in minutes and diagnosing it the way this bug was found.
     *
     * @param array<string, mixed> $session
     */
    public static function isStaleTombstone(array $session, ?int $now = null): bool
    {
        $successor = $session[self::SUCCESSOR_ID] ?? null;

        return is_string($successor)
            && $successor !== ''
            && self::successorWithinGrace($session, $now) === null;
    }
}
