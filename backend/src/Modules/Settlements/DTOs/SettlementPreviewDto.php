<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

final readonly class SettlementPreviewDto
{
    /**
     * Three buckets, not two (#161 §3, ruling #141).
     *
     * `ineligibleMembers` and `creditMembers` are both excluded from the run,
     * but they need opposite remedies — chase the member's bank details versus
     * pay the member back — so folding them into one warning list hides the
     * distinction the treasurer has to act on.
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
    ) {}

    public function toArray(): array
    {
        return [
            'eligible_members' => $this->eligibleMembers,
            'ineligible_members' => $this->ineligibleMembers,
            'credit_members' => $this->creditMembers,
            'eligible_total' => $this->eligibleTotal,
            'ineligible_total' => $this->ineligibleTotal,
            'credit_total' => $this->creditTotal,
            'member_count' => $this->memberCount,
            'warnings' => $this->warnings,
        ];
    }
}
