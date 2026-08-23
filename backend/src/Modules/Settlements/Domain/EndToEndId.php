<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain;

/**
 * The identifier a single collection line carries into the bank file, and the
 * one thing that comes back when the collection fails.
 *
 * German banks are required by DK *DFÜ-Abkommen Anlage 3* to quote the original
 * EndToEndId on the return booking as `EREF+`. Nothing else usable survives an
 * R-transaction: `SVWZ` is replaced by the constant `RETURN/REFUND`, so the
 * remittance text we wrote is gone (#149). `MREF+` still names the *member* —
 * `mandate_reference` is persisted and UNIQUE — but not *which collection*,
 * which is ambiguous the moment the same member is collected for the same
 * amount twice.
 *
 * So this value has to be derived, not counted. The previous form was
 * `PMT-<settlement>-<n>` where `n` was the loop index over the members that
 * survived the export's skip rules — it was never stored, and it changed
 * whenever the skips changed (a cleared IBAN, the credit exclusion of ruling
 * #141). Re-exporting the same settlement could hand the same member a
 * different identifier, which makes retrospective matching impossible even in
 * principle (#150).
 *
 * Derived from the settlement and the member instead, it is:
 *
 * - **stable** — the same two rows always yield the same identifier, so a
 *   re-export is a no-op rather than a divergence;
 * - **resolvable** — it names exactly one collection, i.e. the set of
 *   `settlement_items` for that member in that run;
 * - **within spec** — 29 characters against the ISO 20022 cap of 35, and drawn
 *   from `[0-9a-f-]`, well inside the SEPA character set.
 *
 * A collection line covers *all* of a member's items in the run (the export
 * aggregates by member), so every one of those rows stores this same value.
 *
 * **This is the one identifier that is not {@see SettlementReference}**, and
 * deliberately so. Every other field naming a settlement — `MsgId`,
 * `PmtInfId`, the Verwendungszweck, the mail, the UI — carries the canonical
 * 32-character reference. This field cannot: it has to name a *member* as well
 * as a run and still fit 35 characters, and two canonical references are 64.
 * So both halves are truncated, and the value is persisted rather than
 * recomputed at read time, because a return arriving months later resolves
 * against exactly this string.
 */
final class EndToEndId
{
    /** ISO 20022 caps EndToEndId at 35 characters; this form uses 29. */
    public const MAX_LENGTH = 35;

    private const PREFIX = 'E2E-';

    /** Half of a UUID's 32 hex digits each, which leaves the pair comfortably unique. */
    private const HALF_LENGTH = 12;

    /**
     * The identifier for one member's collection within one settlement.
     *
     * Deterministic by construction: no counter, no clock, no dependence on
     * which other members the export happened to skip.
     */
    public static function forCollection(string $settlementId, string $memberId): string
    {
        return self::PREFIX . self::half($settlementId) . '-' . self::half($memberId);
    }

    private static function half(string $uuid): string
    {
        return substr(str_replace('-', '', $uuid), 0, self::HALF_LENGTH);
    }
}
