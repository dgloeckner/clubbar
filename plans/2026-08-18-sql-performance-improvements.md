# SQL Performance: Indices for the Queries That Scan the Whole Table

**Goal:** Find the queries whose cost grows with the size of the journal rather than with the size of a page, using real `EXPLAIN`/`ANALYZE` plans against a database populated at a decent volume, and add indices (or fix queries that defeat existing ones) where the evidence supports it.

**Related:** [ADR-0004](../adr/0004-immutable-transaction-storage.md) (append-only transactions — the table this mostly concerns only grows), [ADR-0041](../adr/0041-terminal-credential-anomaly-detection.md) (the audit-log dedup check this touches)

**Status:** Implemented (2026-08-18). Migration applied and verified against a seeded 600k-row `transactions` table.

| Task | Status | Evidence |
|------|--------|----------|
| 1. Seed a decent-volume dataset (not the small dev/E2E fixtures) | `[x]` | 5,000 members, 80 products, 600,000 transactions over ~2 years (581,983 purchase / 18,017 payout; ~49k left unsettled), 550,567 settlement_items across 20 settlements, 600,000 audit_log rows, 60,000 mail_outbox rows, 30,000 sessions |
| 2. Baseline `ANALYZE`/`EXPLAIN` on the hot queries | `[x]` | See "Findings" below — `type: ALL` full scans confirmed on 4 repository methods |
| 3. Add `transactions(occurred_at)` | `[x]` | Median wall-clock, same warm cache, 5 runs each: dashboard 30-day revenue sum 417ms → 105ms; admin journal first page (type filter) 467ms → 0.23ms; admin journal first page (unsettled filter) 2,700ms → 0.51ms |
| 4. Add `audit_log(entity_id, action, created_at)` | `[x]` | `hasEntrySince()` moved from an `index_merge` intersection of two single-column indices to one direct range scan on the composite |
| 5. Fix `AuditLogRepository::listWithFilters()` wrapping `created_at` in `DATE()` | `[x]` | Same semantics (pinned by a new boundary test), 594,252 rows examined → 44,896 (a 30-day window on the 600k-row table); ~578ms → ~9-13ms |
| 6. Try `transactions(transaction_type, occurred_at)` — **rejected** | `[x]` | See "What didn't work" below |
| 7. Investigate `sessions` table pruning — **no fix shipped** | `[x]` | See "The sessions table is dead code" below — there is nothing to prune |
| 8. New regression test | `[x]` | `AuditLogRepositoryTest::test_listWithFilters_date_range_includes_both_boundary_instants` |
| 9. Full backend suite green | `[x]` | `docker compose exec -w /app backend ./vendor/bin/phpunit` |

---

## Method

`db/seed.sql` and `db/reset_test_data.sql` are sized for E2E tests (a handful of rows), which tells nothing about a query's cost at the volume an actual club accumulates over a couple of years of daily sales. A throwaway bulk-insert script (`seq_1_to_N` + `MEMORY`-engine lookup tables for random-but-indexed member/product picks) populated the numbers in the table above directly against the dev stack's MariaDB, in well under two minutes.

Every plan below is `ANALYZE SELECT ...` / `ANALYZE FORMAT=JSON SELECT ...` (MariaDB's `EXPLAIN ANALYZE` equivalent) against that data — not `EXPLAIN` alone, which only estimates. Timings are medians of 5 runs, before and after, in the same session with the same warm buffer pool, so the comparison is apples-to-apples even though the absolute numbers benefit from caching a production server won't always have.

## Findings

Four repository methods came back `type: ALL` (a full table scan of 600,000 rows) before this change, because no index led with `occurred_at` and none covered `transaction_type` at all:

- `DashboardRepository::sumRevenueSince/sumRevenueBetween/countPurchasesBetween/findDailyRevenue/findRecentTransactions` — every dashboard load
- `ReportsRepository::summary/fetchGrouped/hourlyDistribution/terminalSummary` — every report
- `TransactionsRepository::listPaginated` — the admin transactions journal's default view
- `AuditLogRepository::hasEntrySince` — used an `index_merge` intersection of `idx_audit_action` + `idx_audit_entity_id` rather than a full scan, which already worked, just not as directly as one composite index

`idx_transactions_member_occurred (member_id, occurred_at)` only helps once a specific member is already known — none of the above queries have one.

`AuditLogRepository::listWithFilters()` additionally wrapped `created_at` in `DATE()` for both bounds, which makes the column non-sargable: `idx_audit_created_action (created_at, action)` already existed and still went unused, because MariaDB cannot range-seek on a value hidden behind a function call. This is a pure query-shape bug, not a missing-index one — same semantics, plain range comparison, index usable again.

## What didn't work

The obvious next index for the revenue-shaped queries — `(transaction_type, occurred_at)`, so a `WHERE transaction_type = 'purchase' AND occurred_at >= ?` could stay inside one index — was built and measured, not just written. It won for a narrow window (dashboard's 30-day sum, ~100ms vs ~200ms full scan) and **lost** for a wide one (a 90-day report, ~900ms vs ~350ms full scan, repeatable across `ANALYZE TABLE` refreshes and either column order).

The cause: `transaction_type` has three values and is 97% `purchase`. Once the date range covers a large enough slice of that value, MariaDB's `ref` access on the equality prefix plus a bookmark lookup into the clustered index per matching row costs more than reading the table in primary-key order — and the optimizer kept choosing the composite index anyway, even when it measured slower. Dropping `transaction_type` from the index left `occurred_at` doing a genuine range seek on its own, and — this is the part that made it the right call over just living with the composite — the optimizer then chose *correctly* in both cases: a real range scan for the narrow window, and its own full-scan fallback for the wide one. The full before/after evidence, including the rejected composite's numbers, is in the migration's own comment (`db/migrations/044_transaction_and_audit_log_indices.sql`).

`sessions` and `mail_outbox` were the other two tables flagged as likely to accumulate — `sessions` because nothing was pruning it (see below), `mail_outbox` because "mail queuing" sounds unbounded. `mail_outbox` turned out already well-designed: `idx_mail_outbox_claim (status, next_attempt_at, claimed_at)` exists and covers the drain loop, and `NotificationsService::pruneDelivered()` already deletes `sent` rows past their retention window on every cron tick (`DrainService::run()`). No change needed there.

## The sessions table is dead code

The initial read on `sessions` was "it needs pruning": `SessionRepository::deleteExpired(int $maxAgeSeconds)` already existed, already had the right index (`idx_sessions_last_activity`), and had no caller anywhere in the codebase, so the natural fix looked like wiring it into `cron.php` the way `mail_outbox` pruning already rides that tick.

Following the trail further changed the diagnosis. `AdminSessionAuth` — the middleware every authenticated admin request goes through — validates the session entirely through PHP's native `$_SESSION` superglobal (`session_status()`, `session_start()`, `SessionTimeout::hasExpired($_SESSION)`); it never touches the `sessions` table. `RuntimeHardening::apply()` confirms why: it points `session.save_path` at an app-private directory and explicitly sets `session.gc_probability`/`session.gc_divisor` so PHP's own file-based session garbage collection actually runs. Grepping the whole backend for `FROM sessions` / `INTO sessions` / `UPDATE sessions` turns up exactly one file — `SessionRepository.php` itself — and grepping for `getSessionRepository()` (its only entry point) turned up nothing at all before this audit. Nothing ever wrote a row to this table in the first place.

So "the table only ever grew" would have been the wrong story: in production it never grows, because nothing populates it. `SessionRepository`, the `sessions` table, and its index are unused schema and dead code, left over from before the app settled on native PHP sessions for admin auth. Wiring a prune job into `cron.php` for a table nothing writes to would have been solving a problem that doesn't exist, so no code change ships here — this is a finding to hand to the maintainer, not a performance fix. Removing the table itself is a schema change CLAUDE.md requires explicit confirmation for, so it's out of scope for this PR.

## Changes

| File | Change |
|------|--------|
| `backend/db/migrations/044_transaction_and_audit_log_indices.sql` (+ `rollback/044_...down.sql`) | `ADD INDEX idx_transactions_occurred_at (occurred_at)`, `ADD INDEX idx_audit_entity_action_created (entity_id, action, created_at)` |
| `backend/src/Modules/AuditLog/Repositories/AuditLogRepository.php` | `listWithFilters()`: `DATE(al.created_at) >= / <= ?` → plain `al.created_at >= ? ` / `al.created_at <= ?` with the upper bound widened via `UnsettledTransactions::endOfDay()` (already used the same way in `TransactionsRepository`) |
| `backend/tests/Feature/Modules/AuditLog/Repositories/AuditLogRepositoryTest.php` | New boundary test for `date_from`/`date_to` |

## Test Commands

```bash
# Backend suite (inside the container — host PHP has no bcmath)
docker compose exec -w /app backend ./vendor/bin/phpunit

# Just the touched area
docker compose exec -w /app backend ./vendor/bin/phpunit --filter AuditLogRepositoryTest
```
