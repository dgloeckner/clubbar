<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class SettlementPreviewDto
{
    public function __construct(
        public array $eligibleMembers,
        public array $ineligibleMembers,
        public int $eligibleTotal,
        public int $ineligibleTotal,
        public int $memberCount,
        public array $warnings,
    ) {}

    public function toArray(): array
    {
        return [
            'eligible_members' => $this->eligibleMembers,
            'ineligible_members' => $this->ineligibleMembers,
            'eligible_total' => $this->eligibleTotal,
            'ineligible_total' => $this->ineligibleTotal,
            'member_count' => $this->memberCount,
            'warnings' => $this->warnings,
        ];
    }
}
