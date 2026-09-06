<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Enums;

use App\Shared\Version\ReleaseVersion;

/**
 * How a terminal's reported version stands against the backend's own
 * (ADR-0054 requirement 10).
 *
 * The invariant the updater enforces is *equality*, so there is exactly one
 * healthy answer and four unhealthy ones — but only one of them is an alarm.
 * [BEHIND] is the normal state of every terminal in the club for the hours
 * between a backend upgrade and that night's update run; a page that shouts
 * about it teaches the club to stop reading the page, which is the same
 * argument CLAUDE.md's empty-digest rule makes about mail.
 *
 * [BLOCKED] is the one that needs a human. A terminal that failed an update
 * blacklisted that tag, and exact-match means the only version it would ever
 * consider next is the one it just blacklisted — so it sits on the last working
 * version and does nothing until a release moves the backend forward. Nothing
 * on the terminal says so, which is why this state exists at all.
 */
enum TerminalVersionState: string
{
    /** No terminal has reported a version yet, or the backend is not on a release tag. */
    case UNKNOWN = 'unknown';

    /** The terminal runs exactly what the backend reports. The invariant holds. */
    case CURRENT = 'current';

    /** Older than the backend, and expected to catch up on its next nightly run. */
    case BEHIND = 'behind';

    /**
     * An update to a specific tag failed on this terminal, and the updater will
     * never retry it. The terminal is frozen until a newer release ships.
     */
    case BLOCKED = 'blocked';

    /**
     * Newer than the backend. The updater never produces this — it is a
     * hand-installed terminal, or a backend rolled back under one. Reported
     * rather than enforced: refusing to sync a too-new terminal would turn
     * version skew into a bar that cannot sell drinks.
     */
    case AHEAD = 'ahead';

    /**
     * @param string|null $reported     what the terminal last sent in `X-Terminal-Version`
     * @param string|null $blocked      the tag whose update failed there, if any
     * @param string|null $backend      this backend's own version, from `/api/health`
     */
    public static function classify(?string $reported, ?string $blocked, ?string $backend): self
    {
        // A `dev` backend never moves a terminal (ADR-0054), so it has no
        // opinion about whether one is up to date either.
        $comparison = ReleaseVersion::compareTags($reported, $backend);
        if ($comparison === null) {
            return self::UNKNOWN;
        }

        if ($comparison === 0) {
            return self::CURRENT;
        }
        if ($comparison > 0) {
            return self::AHEAD;
        }

        // Behind *and* carrying a blocked tag is the frozen case — but only
        // when the blocked tag is one the terminal would otherwise be offered.
        // A tag blocked long ago, below what the terminal now runs, is history:
        // the terminal moved on, and saying "blocked" about it would keep an
        // alarm on screen that nothing can clear.
        if (ReleaseVersion::isReleaseTag($blocked)
            && (ReleaseVersion::compareTags($blocked, $reported) ?? 0) > 0
        ) {
            return self::BLOCKED;
        }

        return self::BEHIND;
    }
}
