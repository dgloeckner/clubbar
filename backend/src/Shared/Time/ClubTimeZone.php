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

    /** The configured zone's name, or {@see self::DEFAULT}. */
    public static function name(): string
    {
        $configured = trim(Env::get(self::ENV_KEY, self::DEFAULT));
        if ($configured === '') {
            return self::DEFAULT;
        }

        // A typo must not take a notice down: a mail that arrives with the
        // wrong hour still reaches somebody, one that throws in the builder
        // reaches nobody and blocks the drain behind it.
        try {
            new DateTimeZone($configured);
        } catch (\Exception) {
            return self::DEFAULT;
        }

        return $configured;
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
}
