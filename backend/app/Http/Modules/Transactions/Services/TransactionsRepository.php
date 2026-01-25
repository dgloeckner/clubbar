<?php

namespace App\Http\Modules\Transactions\Services;

/**
 * TransactionsRepository
 *
 * Data access abstraction for transaction operations.
 * Currently a placeholder; database queries are in TransactionsService.
 *
 * Implements Pattern 005: Repository Interface
 * Implements Pattern 011: Shared Base Repository (to be extended)
 * Implements ADR-0004: Immutable Transaction Storage
 *
 * Note: Transactions are append-only per ADR-0004.
 * This repository has NO update() or delete() methods.
 *
 * TODO: In Phase 2.B, extract all DB queries from TransactionsService into this repository.
 *
 * Planned Methods:
 * - batchInsert(array): array — Insert batch of transactions (idempotent)
 * - findByMemberId(string, int, int, ?int): array — Query transactions by member
 * - insertTransaction(array): array — Create single transaction
 * - query(): Builder — Get query builder for custom filtering
 */
final class TransactionsRepository
{
    // Placeholder - implementation in Phase 2.B
}
