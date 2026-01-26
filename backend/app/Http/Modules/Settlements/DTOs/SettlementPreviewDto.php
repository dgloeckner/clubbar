<?php

namespace App\Http\Modules\Settlements\DTOs;

/**
 * SettlementPreviewDto - Settlement Preview Response
 *
 * Shows what a settlement would look like before it's created.
 * Includes eligible and ineligible members with their balances.
 *
 * Implements Pattern 003: Data Transfer Objects
 * Implements UC-A30: Create Settlement (preview step)
 */
final readonly class SettlementPreviewDto
{
    /**
     * @param array $eligibleMembers Members with IBAN + mandate (array of member data)
     * @param array $ineligibleMembers Members without SEPA eligibility
     * @param int $eligibleTotal Total amount from eligible members (cents)
     * @param int $ineligibleTotal Total amount from ineligible members (cents)
     * @param int $memberCount Number of eligible members
     * @param array $warnings List of warnings about ineligible members
     */
    public function __construct(
        public array $eligibleMembers,
        public array $ineligibleMembers,
        public int $eligibleTotal,
        public int $ineligibleTotal,
        public int $memberCount,
        public array $warnings = [],
    ) {}

    /**
     * Convert to array for JSON serialization
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'eligible_members' => $this->eligibleMembers,
            'ineligible_members' => $this->ineligibleMembers,
            'eligible_total_cents' => $this->eligibleTotal,
            'eligible_total_eur' => round($this->eligibleTotal / 100, 2),
            'ineligible_total_cents' => $this->ineligibleTotal,
            'ineligible_total_eur' => round($this->ineligibleTotal / 100, 2),
            'member_count' => $this->memberCount,
            'warnings' => $this->warnings,
        ];
    }
}
