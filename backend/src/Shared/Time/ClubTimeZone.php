<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Shared\Config\Env;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The zone the club *reads* in — the other half of #365 (#637).
 *
 * {@see Utc} pins everything the application **writes**: columns hold UTC, the
 * API labels them "Z". That is only half a timestamp, and the half that was
 * missing is the conversion back. In the admin UI the browser does it, so a
 * sale stored as `14:29:57Z` is shown to a Berlin reader as `16:29`. A mail has
 * no browser: `MailFormat` formatted the stored instant with `date()`, which —
 * precisely *because* the runtime is pinned — is UTC on every host. Every time
 * of day in every mail was two hours early in summer, one in winter, and a sale
 * after 22:00 CEST was listed under the previous day.
 *
 * So the server needs a zone of its own to render in. It cannot ask the reader
 * — there is nobody there at drain time, and a club's mail goes to a committee
 * in one place anyway — so it is configuration:
 *
 * | | |
 * |---|---|
 * | `CLUB_TIMEZONE` | Any IANA name; empty or unknown falls back to the default |
 * | default | `Europe/Berlin` — this is a German Verein's till |
 *
 * **This class never touches the default time zone.** `date_default_timezone_set()`
 * is what #365 was about: the ~40 repository writes that call `date('Y-m-d H:i:s')`
 * would start writing local time into columns the API labels "Z" the moment it
 * moved. Reading is an explicit per-value conversion instead.
 *
 * ### A calendar day is not an instant
 *
 * `2026-08-21` as a settlement due date means the 21st, in every zone; adding an
 * offset to it is how a deadline silently moves a day. `2026-08-21 14:29:57` is
 * an instant and *must* be shifted. {@see self::moment()} therefore branches on
 * the shape of the value, which is the same rule the frontend's `parseApiDate()`
 * applies to the same two field kinds — the API writes date-only fields without
 * a time and instants with one, so the shape is the contract.
 */
final class ClubTimeZone
{
    /** Env var naming the zone mails and other server-rendered surfaces read in. */
    public const ENV_KEY = 'CLUB_TIMEZONE';

    /** Used when nothing is configured, and when what is configured is not a zone. */
    public const DEFAULT = 'Europe/Berlin';

    /** `2026-08-21` — a calendar day, carrying no instant to convert. */
    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * A stored value's shape: a date, optionally followed by a time and
     * optionally by a zone. Anything else — `now`, `+1 day`, a half-written
     * string — is refused rather than handed to the parser, which would happily
     * turn a relative expression into a plausible-looking timestamp.
     */
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?\s*(Z|[+-]\d{2}:?\d{2})?$/i';

    /** A deployment stated its zone, and it is usable. */
    public const SOURCE_CONFIGURED = 'configured';

    /** Nothing was stated; {@see self::DEFAULT} applies. */
    public const SOURCE_DEFAULT = 'default';

    /** Something was stated and is not a zone; {@see self::DEFAULT} applies. */
    public const SOURCE_INVALID = 'invalid';

    /** The configured zone's name, or {@see self::DEFAULT}. */
    public static function name(): string
    {
        return self::resolve()[0];
    }

    /**
     * Why the effective zone is the one it is.
     *
     * The fallback is deliberately silent at the point of use — a mail that
     * arrives with the wrong hour still reaches somebody, one that throws in
     * the builder reaches nobody and blocks the drain behind it. Silent is not
     * the same as unreportable, though: a club that never stated its zone is
     * reading its books on Berlin's clock by accident rather than by decision,
     * and a club that stated `Europe/Berlim` is doing so despite having tried.
     * Both are invisible on every screen, because a wrong hour looks exactly
     * like a right one. This is what lets the admin panel say so.
     */
    public static function source(): string
    {
        return self::resolve()[1];
    }

    /**
     * The effective zone and where it came from.
     *
     * @return array{0: string, 1: self::SOURCE_*}
     */
    private static function resolve(): array
    {
        $configured = trim(Env::get(self::ENV_KEY, ''));
        if ($configured === '') {
            return [self::DEFAULT, self::SOURCE_DEFAULT];
        }

        try {
            new DateTimeZone($configured);
        } catch (\Exception) {
            return [self::DEFAULT, self::SOURCE_INVALID];
        }

        return [$configured, self::SOURCE_CONFIGURED];
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::name());
    }

    /**
     * Read a stored value as something to show a human, or null if it is not
     * one.
     *
     * The returned value's *components* are what should be printed:
     *
     * - an instant (`2026-08-21 14:29:57`) is read as UTC unless it names its
     *   own offset, then converted — `16:29:57` in Berlin in August;
     * - a calendar day (`2026-08-21`) keeps its date, at midnight local;
     * - anything else is null, and the caller keeps whatever fallback it has.
     */
    public static function moment(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $zone = self::zone();

        try {
            if (preg_match(self::DATE_ONLY, $value) === 1) {
                return new DateTimeImmutable($value . ' 00:00:00', $zone);
            }

            if (preg_match(self::INSTANT, $value) === 1) {
                // The second argument applies only when the string names no
                // zone of its own, which is exactly the assumption we want:
                // a bare column value is UTC (ADR-0031, #365).
                return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($zone);
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }

    /**
     * The club's calendar day containing `$instant`, as `Y-m-d`.
     *
     * The counterpart of {@see self::moment()} for aggregation: a figure headed
     * "today" or a bar on a daily chart names a day the club *had*, and a sale
     * at 23:30 UTC belongs to the following one in Berlin.
     */
    public static function dayOf(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(self::zone())->format('Y-m-d');
    }

    /** The club's current calendar day. `$now` is injectable for tests. */
    public static function today(?DateTimeImmutable $now = null): string
    {
        return self::dayOf($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /** Monday of the club week containing `$now`, as a club calendar day. */
    public static function startOfWeek(?DateTimeImmutable $now = null): string
    {
        $local = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(self::zone());

        return $local->modify('monday this week')->format('Y-m-d');
    }

    /** The first of the club month containing `$now`, as a club calendar day. */
    public static function startOfMonth(?DateTimeImmutable $now = null): string
    {
        $local = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(self::zone());

        return $local->format('Y-m-01');
    }

    /**
     * The UTC instant a club calendar day begins at, as MariaDB spells it.
     *
     * A club day is a **half-open range of UTC instants**, `[startsAtUtc,
     * endsBeforeUtc)`, and both ends are constructed *in the zone* rather than
     * by adding an offset. That is what makes the two days a year that are not
     * 24 hours long come out right: 2026-03-29 is 23 hours in Berlin and
     * 2026-10-25 is 25, and a fixed offset gets one end of each wrong.
     *
     * Null for anything that is not a calendar day, so a caller can keep
     * whatever bound it already had rather than silently querying a window
     * built from a parse failure.
     */
    public static function startsAtUtc(string $day): ?string
    {
        return self::boundary($day, 0);
    }

    /**
     * The UTC instant strictly after a club calendar day ends.
     *
     * Exclusive, not a `23:59:59` inclusive bound: the upper end of a day is
     * the start of the next one, which needs no assumption about the column's
     * resolution and stays right when a day is 23 or 25 hours long.
     */
    public static function endsBeforeUtc(string $day): ?string
    {
        return self::boundary($day, 1);
    }

    /** `$day` plus `$addDays`, at midnight club time, expressed in UTC. */
    private static function boundary(string $day, int $addDays): ?string
    {
        $day = trim($day);
        if (preg_match(self::DATE_ONLY, $day) !== 1) {
            return null;
        }

        try {
            $local = new DateTimeImmutable($day . ' 00:00:00', self::zone());
        } catch (\Exception) {
            return null;
        }

        // A calendar-shaped string PHP still rolls forward (2026-13-45) is a
        // typo, not a date: refuse it rather than query a plausible window.
        if ($local->format('Y-m-d') !== $day) {
            return null;
        }

        if ($addDays !== 0) {
            $local = $local->modify(sprintf('+%d day', $addDays));
        }

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
