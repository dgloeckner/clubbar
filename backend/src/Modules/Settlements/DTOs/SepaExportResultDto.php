<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Modules\Settlements\Enums\SepaExclusionReason;

/**
 * The outcome of a SEPA export: the pain.008 document, the members the export
 * deliberately left out of it, and what the two together mean for the money.
 *
 * Issue #114 describes what happens when the omissions do not travel with the
 * file: a member's IBAN is cleared between settlement creation and export, the
 * export drops them with a bare `continue`, the bank collects less than the
 * settlement records, and the shortfall is never chased because nobody is told
 * it exists. Under ruling #141 §3 an exclusion is reported in its own bucket
 * with its own reason — so the exclusions are part of the result, not
 * something a caller could forget to ask for.
 *
 * The totals are here for the same reason. `settlementAmountCents` is what the
 * books say the club is owed; `collectedAmountCents` is what the file actually
 * asks the bank for. When they differ, the difference is the shortfall and it
 * is stated as a number rather than left to be inferred from a member list.
 */
final readonly class SepaExportResultDto
{
    /**
     * @param list<ExcludedMemberDto> $excludedMembers Every member covered by
     *        the settlement that got no line in the file, with the reason.
     * @param int $collectedAmountCents The sum of the file's collection lines.
     * @param int $settlementAmountCents The settlement's own recorded total.
     * @param int $collectedMemberCount How many members got a collection line —
     *        i.e. how many IBANs the export had to decrypt (ADR-0036). The
     *        SEPA_EXPORT audit entry records this count and nothing else about
     *        the accounts themselves.
     * @param string $downloadName What the file is called when it is handed to
     *        the treasurer. It belongs to the result rather than the caller
     *        because it names *what was exported* — the run's date and its
     *        canonical reference — and the export is the only place that has
     *        both without a second read.
     */
    public function __construct(
        public string $xml,
        public array $excludedMembers = [],
        public int $collectedAmountCents = 0,
        public int $settlementAmountCents = 0,
        public int $collectedMemberCount = 0,
        public string $downloadName = 'sepa.xml',
    ) {}

    /** @return list<ExcludedMemberDto> */
    public function excludedFor(SepaExclusionReason $reason): array
    {
        return array_values(array_filter(
            $this->excludedMembers,
            static fn(ExcludedMemberDto $member): bool => $member->reason === $reason,
        ));
    }

    /**
     * Members in credit, kept as their own accessor because the two buckets
     * need opposite remedies (ruling #141 §3) and the admin UI shows them
     * separately.
     *
     * @return list<ExcludedMemberDto>
     */
    public function creditExcludedMembers(): array
    {
        return $this->excludedFor(SepaExclusionReason::CREDIT_BALANCE);
    }

    /**
     * Members the settlement counted on and the file cannot collect from —
     * an alarm rather than a worklist under the #171 SEPA-only amendment, and
     * the population that makes up the shortfall.
     *
     * @return list<ExcludedMemberDto>
     */
    public function uncollectableMembers(): array
    {
        return array_values(array_filter(
            $this->excludedMembers,
            static fn(ExcludedMemberDto $member): bool => $member->reason->isShortfall(),
        ));
    }

    /**
     * What the settlement records as owed but the file does not collect.
     *
     * Zero for a clean export. Non-zero means the books and the bank file have
     * diverged, and by exactly this much.
     */
    public function shortfallAmountCents(): int
    {
        return array_sum(array_map(
            static fn(ExcludedMemberDto $member): int => $member->amountCents,
            $this->uncollectableMembers(),
        ));
    }

    /**
     * A flat, loggable summary — what goes into the export's audit entry so
     * the omission is on the permanent record rather than only in an HTTP
     * response the browser threw away after saving the file.
     *
     * Ids only, no names: this entry is filed under the settlement, so a
     * member's erasure cannot reach into it, and a name left here would
     * outlive the erasure ({@see ExcludedMemberDto::toAuditArray()}, #115).
     * The response the treasurer acts on is unaffected — it carries ids too,
     * by the same ruling #141 §3 that shaped the headers.
     *
     * @return array<string, mixed>
     */
    public function toAuditSummary(): array
    {
        return [
            'collected_amount_cents' => $this->collectedAmountCents,
            'settlement_amount_cents' => $this->settlementAmountCents,
            'shortfall_amount_cents' => $this->shortfallAmountCents(),
            'excluded_members' => array_map(
                static fn(ExcludedMemberDto $member): array => $member->toAuditArray(),
                $this->excludedMembers,
            ),
        ];
    }
}
