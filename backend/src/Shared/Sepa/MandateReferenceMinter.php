<?php

declare(strict_types=1);

namespace App\Shared\Sepa;

use App\Modules\Settlements\Repositories\SepaConfigRepository;

/**
 * Mints the mandate reference (UMR) a member reads off their own bank statement.
 *
 * `<PREFIX>-<zero-padded number>`, e.g. `CB-000042`. ADR-0006 originally minted
 * a UUID without hyphens; SEPA never asked for that — it requires only
 * uniqueness per creditor, at most 35 characters, in the SEPA charset — and 32
 * hex characters are unreadable both on a Kontoauszug and on the paper a
 * self-registering member signs.
 *
 * One install is one Gläubiger-ID, so a single counter row is all the
 * uniqueness that is owed. Every reference is minted here, on the backend,
 * inside the caller's transaction: the terminal never mints one, and
 * self-registration is a synchronous API call.
 *
 * **Existing references are never re-minted** — they are on signed paper and in
 * collections already sent to the bank, and a return is matched by `MREF+`
 * months later. A mixed population of old and new forms is fine for the bank.
 */
final class MandateReferenceMinter
{
    /** Used when the club has not chosen one — Club Bar's own initials. */
    public const DEFAULT_PREFIX = 'CB';

    /**
     * Six digits covers a club that opens a mandate a day for 2 700 years.
     * Beyond it the number simply grows: padding is a floor, not a cap, and
     * nothing downstream parses a reference back apart.
     */
    public const PAD_TO = 6;

    /** The SEPA cap on a mandate reference. */
    public const MAX_LENGTH = 35;

    /**
     * Ten leaves 24 characters for the number after the separator, which the
     * counter cannot reach inside a BIGINT — so a valid prefix guarantees a
     * valid reference for every number this install will ever mint.
     */
    public const MAX_PREFIX_LENGTH = 10;

    /** The SEPA character set: `0-9 a-z A-Z + ? / - : ( ) . , '`. */
    public const PREFIX_PATTERN = "/^[0-9A-Za-z+?\\/\\-:().,']+$/";

    public function __construct(
        private MandateReferenceCounter $counter,
        private SepaConfigRepository $sepaConfig,
    ) {}

    /**
     * The next real reference. Consumes a number.
     */
    public function mint(): string
    {
        return self::format($this->prefix(), $this->counter->next());
    }

    /**
     * A reference-shaped value for the self-registration honeypot, which must
     * answer a bot with a receipt indistinguishable from a real one.
     *
     * It must NOT consume a number. With a counter, a burnt number would let a
     * bot read the club's mandate count by probing — the count leaks through the
     * receipt itself, which is precisely what the trap exists to hide. So this
     * is random within the padded width, and lands nowhere.
     */
    public function decoy(): string
    {
        return self::format($this->prefix(), random_int(1, 10 ** self::PAD_TO - 1));
    }

    /**
     * The club's prefix, or the default. Falsy is the default too: a config row
     * predating migration 069 has NULL here, and a club that clears the field is
     * asking for the default back rather than for `-000042`.
     */
    public function prefix(): string
    {
        $configured = $this->sepaConfig->getConfig()['mandate_reference_prefix'] ?? null;

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_PREFIX;
    }

    public static function format(string $prefix, int $number): string
    {
        return $prefix . '-' . str_pad((string) $number, self::PAD_TO, '0', STR_PAD_LEFT);
    }

    /**
     * Whether a prefix is one the club may store. Shared by the API validation
     * and the tests so there is one answer to the question.
     */
    public static function isValidPrefix(string $prefix): bool
    {
        return $prefix !== ''
            && strlen($prefix) <= self::MAX_PREFIX_LENGTH
            && preg_match(self::PREFIX_PATTERN, $prefix) === 1;
    }
}
