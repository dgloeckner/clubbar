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
     * Returns transactions that have not yet been included in any settlement.
     *
     * @param string|null $memberId Optional: filter by specific member
     * @return Collection
     */
    public function findUnsettledTransactions(?string $memberId = null): Collection
    {
        $query = $this->model->whereHas('items')
            ->where('settlement_id', null)
            ->orderBy('created_at', 'asc');

        if ($memberId) {
            $query->where('member_id', $memberId);
        }

        return $query->get();
    }

    /**
     * Calculate total balance for each member from unsettled transactions
     *
     * Groups unsettled transactions by member and sums amounts.
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
            ->whereNull('settlement_id')
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
     * Mark transactions as settled by assigning them to a settlement
     *
     * Updates settlement_id on all specified transactions.
     *
     * @param string $settlementId The settlement to assign to
     * @param array $transactionIds Array of transaction UUIDs to mark as settled
     * @return int Number of rows updated
     */
    public function markTransactionsAsSettled(string $settlementId, array $transactionIds): int
    {
        return \DB::table('transactions')
            ->whereIn('id', $transactionIds)
            ->update(['settlement_id' => $settlementId]);
    }

    /**
     * Unmark transactions as settled (set settlement_id to NULL)
     *
     * Used when cancelling a settlement to release transactions back to unsettled state.
     *
     * @param array $transactionIds Array of transaction UUIDs to unmark
     * @return int Number of rows updated
     */
    public function unmarkTransactionsAsSettled(array $transactionIds): int
    {
        return \DB::table('transactions')
            ->whereIn('id', $transactionIds)
            ->update(['settlement_id' => null]);
    }

    /**
     * Find active (non-cancelled) settlements with pagination
     *
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return LengthAwarePaginator
     */
    public function findActivePaginated(int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()
            ->where('is_cancelled', false)
            ->orderBy('settlement_date', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Find settlements by type with pagination
     *
     * @param string $type Settlement type (sepa or manual)
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return LengthAwarePaginator
     */
    public function findByTypePaginated(string $type, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()
            ->where('settlement_type', $type)
            ->where('is_cancelled', false)
            ->orderBy('settlement_date', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
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
        $year = now()->year;
        $count = $this->query()
            ->whereYear('created_at', $year)
            ->count();

        return sprintf('SET-%d-%03d', $year, $count + 1);
    }
}
