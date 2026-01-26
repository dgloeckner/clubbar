<?php

namespace App\Http\Modules\Dashboard\DTOs;

/**
 * DashboardDto
 *
 * Main dashboard response containing all aggregated metrics and data.
 * Wraps multiple data sections:
 * - metrics: Business metrics (counts, balances, revenue)
 * - recent_transactions: Last 10 transactions
 * - terminal_status: All terminals with connectivity
 * - system_status: High-level system indicators
 * - alerts: Admin alerts requiring attention
 *
 * Read-only immutable DTO for complete dashboard response.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
readonly class DashboardDto
{
    /**
     * @param MetricsDto $metrics Business metrics
     * @param array<int, RecentTransactionDto> $recent_transactions Recent 10 transactions
     * @param array<int, TerminalStatusDto> $terminal_status All terminals
     * @param SystemStatusDto $system_status System indicators
     * @param AlertsDto $alerts Admin alerts
     */
    public function __construct(
        public MetricsDto $metrics,
        public array $recent_transactions,
        public array $terminal_status,
        public SystemStatusDto $system_status,
        public AlertsDto $alerts,
    ) {}

    /**
     * Convert to array for JSON response
     *
     * Recursively converts all nested DTOs to arrays.
     */
    public function toArray(): array
    {
        return [
            'metrics' => $this->metrics->toArray(),
            'recent_transactions' => array_map(
                fn (RecentTransactionDto $txn) => $txn->toArray(),
                $this->recent_transactions
            ),
            'terminal_status' => array_map(
                fn (TerminalStatusDto $terminal) => $terminal->toArray(),
                $this->terminal_status
            ),
            'system_status' => $this->system_status->toArray(),
            'alerts' => $this->alerts->toArray(),
        ];
    }
}
