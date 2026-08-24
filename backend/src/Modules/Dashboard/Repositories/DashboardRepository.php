<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Repositories;

use PDO;

/**
 * The queries behind the dashboard and the monthly statistics page.
 *
 * These aggregates span several modules' tables (transactions, products,
 * members, terminals, mandates), which is why they live in their own
 * repository rather than being pushed into any one module's: none of them owns
 * the question. What this class must not do is answer the question — it
 * returns rows, and DashboardService decides what they mean (Pattern 005).
 */
class DashboardRepository
{
    /**
     * What counts as revenue (#116).
     *
     * Revenue is purchases — stornos and payouts are excluded, exactly as
     * `ReportsService` has always defined it. Before, the revenue sums here took
     * every transaction type while `countPurchasesBetween()` beside them counted
     * purchases alone, so a storno moved one figure and not the other and the
     * Dashboard disagreed with the Reports page for the same period, with
     * nothing on either screen to say which was authoritative.
     *
     * Every revenue query below carries this, so the totals and the counts
     * always describe the same set of rows. Two consequences worth knowing: a
     * stornoed purchase still counts, because a storno is a row of its own type
     * rather than a retraction of the original; and what a member still owes is
     * a different question, answered by the unsettled balance, which
     * deliberately sums every type.
     */
    private const REVENUE_FILTER = "transaction_type = 'purchase'";

    public function __construct(private PDO $db) {}

    /**
     * Revenue booked on or after `$date`, in cents.
     */
    public function sumRevenueSince(string $date): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM transactions
              WHERE occurred_at >= ? AND ' . self::REVENUE_FILTER
        );
        $stmt->execute([$date]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The newest transactions, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentTransactions(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.id, t.member_id, CONCAT(m.first_name, ' ', m.last_name) as member_name,
                    t.transaction_type as type, t.amount_cents,
                    p.names as product_names, t.occurred_at as timestamp,
                    te.name as terminal_name
             FROM transactions t
             LEFT JOIN members m ON t.member_id = m.id
             LEFT JOIN products p ON t.product_id = p.id
             LEFT JOIN terminals te ON t.created_by_terminal_id = te.id
             ORDER BY t.occurred_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Members with no mandate in force.
     *
     * Banking data moved to the append-only `mandates` record (#164), so
     * "missing SEPA data" is now "no mandate row points at this member".
     */
    public function countMembersWithoutMandate(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM members m
              LEFT JOIN mandates md ON md.active_member_id = m.id
              WHERE md.id IS NULL AND m.deleted_at IS NULL'
        )->fetchColumn();
    }

    /**
     * Revenue booked within the inclusive date range, in cents.
     */
    public function sumRevenueBetween(string $startDate, string $endDate): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM transactions
              WHERE occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 DAY)
                AND ' . self::REVENUE_FILTER
        );
        $stmt->execute([$startDate, $endDate]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Purchases booked within the inclusive date range.
     */
    public function countPurchasesBetween(string $startDate, string $endDate): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM transactions
              WHERE occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 DAY)
                AND transaction_type = 'purchase'"
        );
        $stmt->execute([$startDate, $endDate]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Revenue and transaction count per calendar day, ascending.
     *
     * Same filter as the totals above, so the daily rows sum to
     * `sumRevenueBetween()` and their counts sum to `countPurchasesBetween()`.
     *
     * @return list<array<string, mixed>>
     */
    public function findDailyRevenue(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT DATE(occurred_at) as date,
                    COALESCE(SUM(amount_cents), 0) as revenue_cents,
                    COUNT(*) as transaction_count
             FROM transactions
             WHERE occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 DAY)
               AND ' . self::REVENUE_FILTER . '
             GROUP BY DATE(occurred_at)
             ORDER BY date'
        );
        $stmt->execute([$startDate, $endDate]);

        return $stmt->fetchAll();
    }

    /**
     * Best-selling products in the range, by revenue.
     *
     * @return list<array<string, mixed>>
     */
    public function findTopProductsByRevenue(string $startDate, string $endDate, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.names, COUNT(*) as sold_count, SUM(t.amount_cents) as revenue_cents
             FROM transactions t
             JOIN products p ON t.product_id = p.id
             WHERE t.occurred_at >= :start AND t.occurred_at < DATE_ADD(:end, INTERVAL 1 DAY)
               AND t.transaction_type = 'purchase'
             GROUP BY p.id
             ORDER BY revenue_cents DESC
             LIMIT :limit"
        );
        $stmt->bindValue('start', $startDate);
        $stmt->bindValue('end', $endDate);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Best-selling products in the range, by units sold.
     *
     * @return list<array<string, mixed>>
     */
    public function findTopProductsBySoldCount(string $startDate, string $endDate, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.names, COUNT(*) as sold_count
             FROM transactions t
             JOIN products p ON t.product_id = p.id
             WHERE t.occurred_at >= :start AND t.occurred_at < DATE_ADD(:end, INTERVAL 1 DAY)
               AND t.transaction_type = 'purchase'
             GROUP BY p.id
             ORDER BY sold_count DESC
             LIMIT :limit"
        );
        $stmt->bindValue('start', $startDate);
        $stmt->bindValue('end', $endDate);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Members who spent the most in the range.
     *
     * @return list<array<string, mixed>>
     */
    public function findTopMembers(string $startDate, string $endDate, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, CONCAT(m.first_name, ' ', m.last_name) as name,
                    COUNT(*) as purchase_count, SUM(t.amount_cents) as revenue_cents
             FROM transactions t
             JOIN members m ON t.member_id = m.id
             WHERE t.occurred_at >= :start AND t.occurred_at < DATE_ADD(:end, INTERVAL 1 DAY)
               AND t.transaction_type = 'purchase'
             GROUP BY m.id
             ORDER BY revenue_cents DESC
             LIMIT :limit"
        );
        $stmt->bindValue('start', $startDate);
        $stmt->bindValue('end', $endDate);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
