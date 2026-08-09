<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

final readonly class SettlementPreviewDto
{
    /**
     * Four buckets, not two (#161 §3, ruling #141; ruling #148 §4).
     *
     * Every member excluded from the run appears in exactly one of them,
     * because each needs a different remedy and folding them into one warning
     * list hides the distinction the treasurer has to act on:
     *
     * | bucket       | why excluded                  | remedy                    |
     * |--------------|-------------------------------|---------------------------|
     * | `ineligible` | no active SEPA mandate        | chase the bank details    |
     * | `credit`     | the club owes them money      | pay the member back        |
     * | `held`       | their last collection bounced | investigate, then clear it |
     *
     * The hold bucket is the one that must never be silent: a held member is
     * skipped run after run until somebody clears it.
     */
    public function __construct(
        public array $eligibleMembers,
        public array $ineligibleMembers,
        public int $eligibleTotal,
        public int $ineligibleTotal,
        public int $memberCount,
        public array $warnings,
        public array $creditMembers = [],
        public int $creditTotal = 0,
        public array $heldMembers = [],
        public int $heldTotal = 0,
        /**
         * How many transactions the run would actually contain — the whole
         * unsettled position of every eligible member, not the slice the
         * caller named. A caller that counted its own selection would
         * understate the run, which is the mismatch issue #128 is about.
         */
        public int $transactionCount = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'eligible_members' => $this->eligibleMembers,
            'ineligible_members' => $this->ineligibleMembers,
            'credit_members' => $this->creditMembers,
            'held_members' => $this->heldMembers,
            'eligible_total' => $this->eligibleTotal,
            'ineligible_total' => $this->ineligibleTotal,
            'credit_total' => $this->creditTotal,
            'held_total' => $this->heldTotal,
            'member_count' => $this->memberCount,
            'transaction_count' => $this->transactionCount,
            'warnings' => $this->warnings,
        ];
    }
}
