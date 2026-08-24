<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

use App\Modules\Notifications\Enums\DigestCadence;
use App\Shared\Time\ClubTimeZone;
use DateTimeImmutable;

/**
 * The stretch of time one near-limit digest speaks for.
 *
 * This is the digest's entire idempotency, and it is worth being explicit about
 * how little machinery that takes. The scan does not remember what it has sent;
 * it names the window it is currently inside, hands that to
 * `AdminNotifier::warnAdmins()` as the occasion, and
 * `UNIQUE (kind, subject_id, dedup_key)` refuses the second and every later
 * attempt. A scheduler ticking every fifteen minutes therefore produces one
 * weekly digest, without a lookup and so without a race between two overlapping
 * ticks — the same argument ADR-0039 makes for the Deckelauszug's period.
 *
 * ### The clock is the club's
 *
 * {@see ClubTimeZone}, not UTC. The window boundary decides which morning the
 * mail lands on, and a club in Berlin whose "Monday digest" arrives at 01:00
 * Monday in January and 02:00 in July — or, for a daily cadence, on Sunday
 * night — would be reading a UTC boundary as if it were a local one. This is
 * the same correction #637 made to the timestamps inside the mails.
 *
 * ### Why there is no catch-up
 *
 * ADR-0039's statement scan refuses a period that is not the current one, so a
 * scheduler that was off for a year does not produce twelve statements per
 * member on the day it returns. This class needs no equivalent rule, because it
 * never looks at a window other than the one it is in: a digest reports a
 * *current* condition — who is near their ceiling right now — and last March's
 * window has no content to reconstruct and nobody who wants it. A scheduler
 * that comes back after a month sends one digest, describing today.
 */
final readonly class DigestWindow
{
    private function __construct(
        public DigestCadence $cadence,
        public string $key,
    ) {}

    /**
     * The window `$instant` falls inside, or null when the cadence is off.
     *
     * The key formats are chosen to be unambiguous when read out of a
     * `dedup_key` by a human debugging a missing digest:
     *
     * | Cadence | Key | Note |
     * |---|---|---|
     * | daily | `2026-08-24` | the club's calendar day |
     * | weekly | `2026-W35` | ISO-8601 week, so it never splits a Monday |
     * | monthly | `2026-08` | the same shape `StatementPeriod` uses |
     *
     * **`o`, not `Y`, for the weekly key.** PHP's `W` is the ISO week number
     * and `o` is the ISO week-numbering *year*, which is not always the
     * calendar year: 29 December 2025 is `2026-W01`. Pairing `W` with `Y` would
     * produce `2025-W01` for that Monday and again for the one in January, and
     * the second week's digest would be swallowed by the unique index as a
     * duplicate of the first — a silently missing mail at the turn of a year,
     * which is the hardest kind of bug to be told about.
     *
     * A key never exceeds ten characters, which matters because
     * `AdminNotifier` builds `occasion:adminUserId` into a `VARCHAR(64)` and an
     * admin id is 36 of those. Ten leaves seventeen spare.
     */
    public static function containing(DigestCadence $cadence, DateTimeImmutable $instant): ?self
    {
        $local = $instant->setTimezone(ClubTimeZone::zone());

        $key = match ($cadence) {
            DigestCadence::OFF => null,
            DigestCadence::DAILY => $local->format('Y-m-d'),
            DigestCadence::WEEKLY => $local->format('o-\WW'),
            DigestCadence::MONTHLY => $local->format('Y-m'),
        };

        return $key === null ? null : new self($cadence, $key);
    }
}
