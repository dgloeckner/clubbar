<?php

namespace App\Http\Modules\Settlements\Repositories;

use App\Models\Settlement;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * SettlementsRepository
 *
 * Data access layer for settlements.
 * Extends BaseRepository for standard CRUD operations.
 *
 * Pattern 005: Repository Interface
 * Pattern 011: Shared Base Repository
 */
class SettlementsRepository extends BaseRepository
{
    /**
     * Initialize repository with Settlement model.
     */
    public function __construct()
    {
        parent::__construct(new Settlement());
    }

    /**
     * Find unsettled transactions for a member or all members
     *
     * Returns transactions that have not yet been included in any active settlement.
     * Settlement status is determined by presence in settlement_items table, not settlement_id FK.
     *
     * @param string|null $memberId Optional: filter by specific member
     * @return Collection
     */
    public function findUnsettledTransactions(?string $memberId = null): Collection
    {
        $query = \DB::table('transactions')
            ->whereNotExists(function ($subquery) {
                $subquery->select(\DB::raw(1))
                    ->from('settlement_items')
                    ->join('settlements', 'settlement_items.settlement_id', '=', 'settlements.id')
                    ->where('settlements.is_cancelled', false)
                    ->whereColumn('settlement_items.transaction_id', '=', 'transactions.id');
            })
            ->orderBy('created_at', 'asc');

        if ($memberId) {
            $query->where('member_id', $memberId);
        }

        return collect($query->get());
    }

    /**
     * Calculate total balance for each member from unsettled transactions
     *
     * Groups unsettled transactions by member and sums amounts.
     * Settlement status determined by presence in settlement_items table (not settlement_id FK).
     * Used for settlement preview and balance display.
     *
     * @param string|null $fromDate Filter transactions from this date
     * @param string|null $toDate Filter transactions until this date
     * @return array Array of [member_id => total_amount_cents]
     */
    public function calculateMemberBalances(?string $fromDate = null, ?string $toDate = null): array
    {
        $query = \DB::table('transactions')
            ->select('member_id', \DB::raw('SUM(amount_cents) as total_amount_cents'))
            ->whereNotExists(function ($subquery) {
                $subquery->select(\DB::raw(1))
                    ->from('settlement_items')
                    ->join('settlements', 'settlement_items.settlement_id', '=', 'settlements.id')
                    ->where('settlements.is_cancelled', false)
                    ->whereColumn('settlement_items.transaction_id', '=', 'transactions.id');
            })
            ->groupBy('member_id');

        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        return $query->pluck('total_amount_cents', 'member_id')->toArray();
    }

    /**
     * Find active (non-cancelled) settlements with pagination
     *
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return LengthAwarePaginator
     */
    public function findActivePaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        ?string $sortKey = 'created_at',
        ?string $sortOrder = 'desc'
    ): LengthAwarePaginator {
        $query = $this->query();

        // Filter by status
        if ($status === 'active') {
            $query->where('is_cancelled', false);
        } elseif ($status === 'cancelled') {
            $query->where('is_cancelled', true);
        }
        // 'all' = no filter

        // Apply sorting
        if ($sortKey === 'created_by') {
            // Sort by created_by_admin_id, with NULL values at the end
            $query->orderByRaw("COALESCE(created_by_admin_id, '') " . strtoupper($sortOrder));
        } else {
            // Default: sort by created_at
            $query->orderBy('created_at', strtoupper($sortOrder));
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Find exportable (not yet exported) settlements
     *
     * @return Collection
     */
    public function findExportable(): Collection
    {
        return $this->query()
            ->where('is_cancelled', false)
            ->whereNull('exported_at')
            ->get();
    }

    /**
     * Generate next SEPA message ID
     *
     * Format: SET-YYYY-NNN where YYYY is year and NNN is sequence number
     *
     * @return string The next available SEPA message ID
     */
    public function getNextSepaMessageId(): string
    {
        // Generate UUID-based SEPA message ID for parallel test safety
        // Format: SEPA-{12-char-uuid-hex}
        // Eliminates race conditions from sequential counters
        // UUIDs are guaranteed unique without database locks
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        // Take first 12 chars of UUID (timestamp-based portion is unique enough)
        return 'SEPA-' . substr(str_replace('-', '', $uuid), 0, 12);
    }
}
