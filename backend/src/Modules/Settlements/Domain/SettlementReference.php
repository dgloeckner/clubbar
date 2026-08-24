<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain;

/**
 * The one way a settlement is named — to a bank, to a member, and to the
 * Kassenwart.
 *
 * A settlement used to carry four identifiers that disagreed with each other:
 * the UUID in the admin URL, a random `SEPA-<12 hex>` in `MsgId`, the first 16
 * hex digits of the UUID in `PmtInfId`, and two truncated UUIDs in
 * `EndToEndId`. Meanwhile the two fields a *human* reads — the Verwendungszweck
 * on a bank statement and the Vorabankündigung — named nothing at all, so a
 * member asking "what was this collection for?" had no string to quote and the
 * treasurer had nothing to look it up by.
 *
 * The identifier is the settlement's own id. What this class fixes is its
 * *rendering*: 32 lowercase hex digits, hyphens stripped, one form everywhere.
 * That matters because the payoff is paste-matching — the string a member reads
 * off their statement has to be the string that finds the run in the admin
 * panel, character for character.
 *
 * Hyphens go for two reasons. A UUID is 36 characters with them and ISO 20022
 * caps `MsgId` and `PmtInfId` at 35, so the canonical form has to be the short
 * one or it would not fit the fields it exists for. And the SEPA character set
 * permits `-`, so a hyphenated id would survive sanitisation and quietly become
 * a second form of the same value.
 *
 * **One field is deliberately not this.** {@see EndToEndId} must be unique per
 * line in the file *and* fit 35 characters, and it has to name a member as well
 * as a run — two 32-character ids do not fit in 35. It stays
 * `E2E-<12 hex settlement>-<12 hex member>`: the documented exception, not an
 * oversight, and the one value that is persisted rather than derived
 * (`settlement_items.end_to_end_id`) because a returned collection resolves
 * against it.
 */
final class SettlementReference
{
    /**
     * A UUID minus its four hyphens. Inside ISO 20022's 35-character cap for
     * `MsgId`, `PmtInfId` and `EndToEndId`, with three to spare.
     */
    public const LENGTH = 32;

    /**
     * The canonical rendering of a settlement's identity.
     *
     * Every caller uses this. Nothing anywhere strips the hyphens inline again
     * — that is how the three divergent truncations got in.
     */
    public static function of(string $settlementId): string
    {
        return str_replace('-', '', strtolower(trim($settlementId)));
    }

    /**
     * A reference as typed by a human, reduced to something comparable.
     *
     * A treasurer pastes the Verwendungszweck off a bank statement, which may
     * carry the hyphenated form, stray spaces, or upper case depending on the
     * bank's portal. All three name the same settlement.
     */
    public static function normalise(string $input): string
    {
        return self::of(str_replace(' ', '', $input));
    }
}
