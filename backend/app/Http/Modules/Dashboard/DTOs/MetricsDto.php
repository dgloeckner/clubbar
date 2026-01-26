<?php

namespace App\Http\Modules\Dashboard\DTOs;

/**
 * MetricsDto
 *
 * Business metrics aggregated from across the system.
 * All currency amounts are in cents (e.g., 1500 = €15.00).
 *
 * Read-only immutable DTO for dashboard metrics section.
 */
readonly class MetricsDto
{
    public function __construct(
        public int $active_members,
        public int $inactive_members,
        public int $outstanding_balance_cents,
        public int $todays_revenue_cents,
        public int $terminal_count,
        public int $active_terminals,
        public int $settled_members,
        public int $sepa_issue_count,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'active_members' => $this->active_members,
            'inactive_members' => $this->inactive_members,
            'outstanding_balance_cents' => $this->outstanding_balance_cents,
            'todays_revenue_cents' => $this->todays_revenue_cents,
            'terminal_count' => $this->terminal_count,
            'active_terminals' => $this->active_terminals,
            'settled_members' => $this->settled_members,
            'sepa_issue_count' => $this->sepa_issue_count,
        ];
    }
}
