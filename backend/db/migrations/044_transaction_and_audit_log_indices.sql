-- =============================================================================
-- 044_transaction_and_audit_log_indices.sql — indices for the queries that scan
-- the whole table as the journal grows
-- =============================================================================
-- Measured against a 600k-row transactions table / 600k-row audit_log table
-- (see plans/2026-08-18-sql-performance.md for the full before/after numbers):
--
--   Dashboard::sumRevenueSince / findDailyRevenue / findRecentTransactions,
--   ReportsRepository::summary / hourlyDistribution, and
--   TransactionsRepository::listPaginated all filter or sort on `occurred_at`,
--   several of them also equality-filtering on `transaction_type`, and none of
--   that is covered by an index: `idx_transactions_member_occurred` is only
--   useful once a specific member is already known. Every one of those queries
--   was a full table scan (`type: ALL`) — 700ms-2.9s each at this volume —
--   and `listPaginated` in particular is the admin transactions journal's first
--   page load.
--
--   AuditLogRepository::hasEntrySince (the terminal-anomaly / credential-expiry
--   dedup check, ADR-0041) filters on (entity_id, action, created_at) together
--   and has no index covering all three; MariaDB falls back to an index_merge
--   intersection of two single-column indices, which works but is strictly
--   more expensive than one direct lookup.
--
-- One index considered and rejected: `(transaction_type, occurred_at)`, to
-- cover the revenue-shaped queries that equality-filter `transaction_type`
-- before ranging on `occurred_at` (Dashboard's revenue sums, Reports'
-- summary/grouped totals). Measured on the same 600k-row table, it won for a
-- narrow window (~100ms vs ~200ms full scan for the dashboard's 30-day sum)
-- but *lost* for a wide one (~900ms vs ~350ms full scan for a 90-day report):
-- `transaction_type` has only three values and is 97% `purchase`, so once the
-- date range covers a large enough slice of that value, MariaDB's `ref` access
-- on the equality prefix plus per-row bookmark lookups into the clustered
-- index cost more than just reading the table in PK order — and the optimizer
-- kept choosing it anyway even after `ANALYZE TABLE` refreshed statistics and
-- regardless of which column led the index. Dropping `transaction_type` from
-- the index and leaving `occurred_at` on its own is what let the optimizer
-- choose correctly in both cases: a real range scan for the narrow window, and
-- its own full-table-scan fallback for the wide one.
ALTER TABLE transactions
    ADD INDEX idx_transactions_occurred_at (occurred_at);

-- `(entity_id, action, created_at)`: the exact predicate `hasEntrySince()`
-- filters on, so it becomes a single index range scan rather than an
-- index_merge intersection of `idx_audit_entity_id` and `idx_audit_action`.
ALTER TABLE audit_log
    ADD INDEX idx_audit_entity_action_created (entity_id, action, created_at);
