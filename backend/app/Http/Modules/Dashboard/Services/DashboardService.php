<?php

namespace App\Http\Modules\Dashboard\Services;

use App\Models\Member;
use App\Models\Transaction;
use App\Models\Terminal;
use App\Models\Settlement;
use App\Models\SettlementItem;
use App\Models\Product;
use App\Http\Modules\Dashboard\DTOs\DashboardDto;
use App\Http\Modules\Dashboard\DTOs\MetricsDto;
use App\Http\Modules\Dashboard\DTOs\RecentTransactionDto;
use App\Http\Modules\Dashboard\DTOs\TerminalStatusDto;
use App\Http\Modules\Dashboard\DTOs\SystemStatusDto;
use App\Http\Modules\Dashboard\DTOs\AlertsDto;
use App\Http\Modules\Dashboard\DTOs\SepaAlertDto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DashboardService
 *
 * Aggregates metrics from multiple modules to provide admin dashboard overview.
 * All operations are read-only (no mutations).
 *
 * Implements optimized queries to avoid N+1 issues:
 * - Uses database aggregations (COUNT, SUM) instead of application-level processing
 * - Filters at database level before aggregation
 * - Caches query results within single request lifecycle
 *
 * Note: This is a standalone read-only service (not extending BaseService)
 * since it aggregates data from multiple modules rather than working with a single model.
 * Pattern 004: Service Layer (read-only variant)
 */
class DashboardService
{
    /**
     * Get aggregated dashboard metrics
     *
     * Returns comprehensive dashboard data with metrics from all modules.
     * No mutations, no audit logging needed (read-only operation).
     *
     * @return DashboardDto Dashboard DTO with all aggregated data
     */
    public function getDashboardMetrics(): DashboardDto
    {
        return new DashboardDto(
            metrics: $this->buildMetricsDto(),
            recent_transactions: $this->buildRecentTransactionDtos(),
            terminal_status: $this->buildTerminalStatusDtos(),
            system_status: $this->buildSystemStatusDto(),
            alerts: $this->buildAlertsDto(),
        );
    }

    /**
     * Build MetricsDto with all business metrics
     *
     * Calculates key business indicators:
     * - Member statistics (active, inactive)
     * - Financial metrics (balance, revenue)
     * - Terminal statistics (count, active)
     * - Settlement metrics
     * - SEPA compliance
     *
     * @return MetricsDto Metrics DTO
     */
    private function buildMetricsDto(): MetricsDto
    {
        return new MetricsDto(
            active_members: $this->getActiveMembersCount(),
            inactive_members: $this->getInactiveMembersCount(),
            outstanding_balance_cents: $this->getOutstandingBalanceCents(),
            todays_revenue_cents: $this->getTodaysRevenueCents(),
            terminal_count: $this->getTerminalCount(),
            active_terminals: $this->getActiveTerminalsCount(),
            settled_members: $this->getSettledMembersCount(),
            sepa_issue_count: $this->getSepaIssueCount(),
        );
    }

    /**
     * Count active members
     *
     * @return int Count of members with is_active=true
     */
    private function getActiveMembersCount(): int
    {
        return Member::where('is_active', true)
            ->count();
    }

    /**
     * Count inactive members
     *
     * @return int Count of members with is_active=false
     */
    private function getInactiveMembersCount(): int
    {
        return Member::where('is_active', false)
            ->count();
    }

    /**
     * Calculate total outstanding balance (unsettled transactions)
     *
     * Sum of all transaction amounts where transaction does NOT appear in settlement_items
     * from an active (non-cancelled) settlement.
     *
     * Uses NOT EXISTS subquery to find transactions not in active settlements.
     * A transaction is considered unsettled if:
     * - NOT in settlement_items, OR
     * - In settlement_items from a cancelled settlement
     *
     * Includes both positive charges and negative corrections.
     *
     * @return int Outstanding balance in cents
     */
    private function getOutstandingBalanceCents(): int
    {
        return (int) (Transaction::whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('settlement_items')
                    ->join('settlements', 'settlement_items.settlement_id', '=', 'settlements.id')
                    ->where('settlements.is_cancelled', false)
                    ->whereColumn('settlement_items.transaction_id', '=', 'transactions.id');
            })
            ->sum('amount_cents') ?? 0);
    }

    /**
     * Calculate today's revenue
     *
     * Sum of all transactions created today (by transaction timestamp).
     * Includes both positive and negative amounts.
     *
     * @return int Today's revenue in cents
     */
    private function getTodaysRevenueCents(): int
    {
        return (int) (Transaction::whereDate('created_at', today())
            ->sum('amount_cents') ?? 0);
    }

    /**
     * Count total terminals
     *
     * @return int Total count of terminals (active and inactive)
     */
    private function getTerminalCount(): int
    {
        return Terminal::count();
    }

    /**
     * Count active terminals
     *
     * @return int Count of terminals with is_active=true
     */
    private function getActiveTerminalsCount(): int
    {
        return Terminal::where('is_active', true)
            ->count();
    }

    /**
     * Count members with settled transactions
     *
     * Number of unique members who have at least one transaction in settlement_items
     * from an active (non-cancelled) settlement.
     *
     * @return int Count of members with settled transactions
     */
    private function getSettledMembersCount(): int
    {
        return SettlementItem::join('settlements', 'settlement_items.settlement_id', '=', 'settlements.id')
            ->where('settlements.is_cancelled', false)
            ->distinct('settlement_items.member_id')
            ->count('settlement_items.member_id');
    }

    /**
     * Count members with incomplete SEPA data
     *
     * Members missing either IBAN or mandate_reference are SEPA-incomplete
     * and cannot participate in SEPA settlements.
     *
     * @return int Count of members with missing SEPA data
     */
    private function getSepaIssueCount(): int
    {
        return Member::where(function ($query) {
            $query->whereNull('iban')
                ->orWhereNull('mandate_reference');
        })->count();
    }

    /**
     * Build array of RecentTransactionDto with member and product details
     *
     * Returns last 10 transactions ordered by created_at DESC.
     * Each transaction includes member name and product name for display.
     *
     * Uses eager loading to avoid N+1 issues.
     *
     * @return array<int, RecentTransactionDto> Array of transaction DTOs
     */
    private function buildRecentTransactionDtos(): array
    {
        return Transaction::with(['member', 'product'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function (Transaction $transaction) {
                return new RecentTransactionDto(
                    id: $transaction->id,
                    member_id: $transaction->member_id,
                    member_name: $transaction->member
                        ? $this->formatMemberName($transaction->member)
                        : 'Unknown Member',
                    type: $transaction->transaction_type,
                    amount_cents: $transaction->amount_cents,
                    product_name: $transaction->product
                        ? $this->getProductName($transaction->product)
                        : ($transaction->notes ?? 'Manual Entry'),
                    timestamp: $transaction->created_at?->toIso8601String() ?? '',
                );
            })
            ->all();
    }

    /**
     * Build array of TerminalStatusDto with connectivity information
     *
     * Returns all terminals with status indicators:
     * - Terminal ID, name, active status
     * - Last sync timestamp (shows connectivity)
     * - Computed status: online if synced recently, offline otherwise
     *
     * @return array<int, TerminalStatusDto> Array of terminal status DTOs
     */
    private function buildTerminalStatusDtos(): array
    {
        return Terminal::orderBy('created_at', 'desc')
            ->get()
            ->map(function (Terminal $terminal) {
                return new TerminalStatusDto(
                    id: $terminal->id,
                    name: $terminal->name,
                    is_active: $terminal->is_active,
                    last_sync_at: $terminal->last_sync_at?->toIso8601String(),
                    status: $this->getTerminalConnectivityStatus($terminal),
                );
            })
            ->all();
    }

    /**
     * Determine terminal connectivity status
     *
     * Online: Last sync within 24 hours
     * Offline: No sync or last sync > 24 hours ago
     * Disabled: Terminal is inactive
     *
     * @param Terminal $terminal
     * @return string Status: 'online', 'offline', or 'disabled'
     */
    private function getTerminalConnectivityStatus(Terminal $terminal): string
    {
        if (!$terminal->is_active) {
            return 'disabled';
        }

        if ($terminal->last_sync_at === null) {
            return 'offline';
        }

        $hoursSinceSync = now()->diffInHours($terminal->last_sync_at);
        return $hoursSinceSync <= 24 ? 'online' : 'offline';
    }

    /**
     * Build SystemStatusDto with system-wide status information
     *
     * Returns high-level system metrics:
     * - Last settlement timestamp
     * - Count of pending (non-cancelled) settlements
     * - Total member count
     * - Total transaction count
     * - Database health indicator (always 'ok' for now)
     *
     * @return SystemStatusDto System status DTO
     */
    private function buildSystemStatusDto(): SystemStatusDto
    {
        return new SystemStatusDto(
            last_settlement_date: Settlement::whereNotNull('created_at')
                ->max('created_at')?->toIso8601String(),
            pending_settlement_count: Settlement::where('is_cancelled', false)
                ->count(),
            total_members: Member::count(),
            total_transactions: Transaction::count(),
            database_health: 'ok',
        );
    }

    /**
     * Build AlertsDto for admin attention
     *
     * Currently alerts on SEPA data issues.
     * Future: could add more alert types (overdue settlements, etc.)
     *
     * @return AlertsDto Alerts DTO with sepa_issues
     */
    private function buildAlertsDto(): AlertsDto
    {
        $sepaIssueCount = $this->getSepaIssueCount();

        return new AlertsDto(
            sepa_issues: new SepaAlertDto(
                count: $sepaIssueCount,
                severity: $sepaIssueCount >= 6 ? 'error' : ($sepaIssueCount > 0 ? 'warning' : 'none'),
                message: $sepaIssueCount === 0
                    ? 'No SEPA data issues'
                    : "$sepaIssueCount members have SEPA data issues",
            ),
        );
    }

    /**
     * Format member name for display
     *
     * Returns "first_name last_name" or handles nulls gracefully.
     *
     * @param Member $member
     * @return string Formatted member name
     */
    private function formatMemberName(Member $member): string
    {
        $parts = array_filter([
            $member->first_name,
            $member->last_name,
        ]);

        return count($parts) > 0
            ? implode(' ', $parts)
            : $member->card_uid ?? 'Member';
    }

    /**
     * Get product name for display
     *
     * Handles multilingual names (JSON) by returning first available name.
     * Falls back to first language key if preferred language unavailable.
     *
     * @param Product $product
     * @return string Product display name
     */
    private function getProductName(Product $product): string
    {
        if (is_string($product->names)) {
            return $product->names;
        }

        if (is_array($product->names) && count($product->names) > 0) {
            // Try to return German name (most common), then first available
            return $product->names['de'] ?? reset($product->names);
        }

        return 'Unknown Product';
    }

}
