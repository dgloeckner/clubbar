/**
 * Terminal Sync Service
 *
 * Handles synchronization between offline terminal and backend.
 * Manages atomic updates of transactions and balance per ADR-0023.
 *
 * Implements:
 * - ADR-0023: Terminal Balance State Management
 * - ADR-0024: Transaction History Retrieval (online-only)
 * - ADR-0012: Eventual Consistency and Frontend Caching
 * - ADR-0004: Immutable Transaction Storage
 *
 * Flow:
 * 1. Collect unsynced transactions from SQLite
 * 2. POST to backend /api/sync/transactions with batch
 * 3. Backend returns: { accepted_ids, rejected, member_balances }
 * 4. ATOMICALLY:
 *    - Mark accepted transactions as synced
 *    - Update member_balance from response
 *    - Update sync_state timestamp
 * 5. If network fails: ROLLBACK (no partial state)
 * 6. If success: Balance now reflects backend state
 */

import { Database } from 'better-sqlite3';

/**
 * Response from POST /api/sync/transactions
 */
export interface SyncTransactionsResponse {
  accepted_ids: string[];
  rejected: {
    count: number;
    errors: Array<{
      transaction_id: string;
      error: string;
      message: string;
    }>;
  };
  member_balances: {
    [memberId: string]: number; // member_id -> balance_cents
  };
}

/**
 * Local transaction for upload
 */
export interface LocalTransaction {
  id: string;
  member_id: string;
  product_id: string | null;
  amount_cents: number;
  created_at: string;
}

/**
 * Sync result with summary
 */
export interface SyncResult {
  success: boolean;
  acceptedCount: number;
  rejectedCount: number;
  balancesUpdated: number;
  error?: string;
  timestamp: string;
}

/**
 * Terminal Sync Service
 *
 * Handles all synchronization operations with backend API.
 */
export class SyncService {
  constructor(private db: Database, private apiToken: string) {}

  /**
   * Perform full transaction sync cycle
   *
   * 1. Collect unsynced transactions
   * 2. POST to backend
   * 3. Atomically update sync state and balance
   * 4. Rollback on network error
   *
   * @param apiUrl Base URL of backend API (e.g., http://localhost:8080)
   * @returns SyncResult with success/failure details
   */
  async syncTransactions(apiUrl: string): Promise<SyncResult> {
    const startTime = new Date();

    try {
      // Step 1: Collect unsynced transactions
      const unsyncedTransactions = this.getUnsyncedTransactions();

      if (unsyncedTransactions.length === 0) {
        return {
          success: true,
          acceptedCount: 0,
          rejectedCount: 0,
          balancesUpdated: 0,
          timestamp: new Date().toISOString(),
        };
      }

      console.log(`[Sync] Found ${unsyncedTransactions.length} unsynced transactions`);

      // Step 2: POST to backend
      const response = await this.uploadTransactionBatch(
        apiUrl,
        unsyncedTransactions
      );

      console.log(
        `[Sync] Backend accepted ${response.accepted_ids.length}, rejected ${response.rejected.count}`
      );

      // Step 3: Atomically update sync state
      const balancesUpdated = this.atomicSyncUpdate(response);

      const result: SyncResult = {
        success: true,
        acceptedCount: response.accepted_ids.length,
        rejectedCount: response.rejected.count,
        balancesUpdated,
        timestamp: new Date().toISOString(),
      };

      console.log(
        `[Sync] Complete: ${result.acceptedCount} accepted, ${result.balancesUpdated} balances updated`
      );

      return result;
    } catch (error) {
      console.error(`[Sync] Error: ${error instanceof Error ? error.message : String(error)}`);

      return {
        success: false,
        acceptedCount: 0,
        rejectedCount: 0,
        balancesUpdated: 0,
        error: error instanceof Error ? error.message : 'Unknown error',
        timestamp: new Date().toISOString(),
      };
    }
  }

  /**
   * Get all unsynced transactions from local SQLite
   *
   * @private
   * @returns LocalTransaction[]
   */
  private getUnsyncedTransactions(): LocalTransaction[] {
    const stmt = this.db.prepare(`
      SELECT
        id,
        member_id,
        product_id,
        amount_cents,
        created_at
      FROM transactions
      WHERE synced = 0
      ORDER BY created_at ASC
      LIMIT 100
    `);

    return stmt.all() as LocalTransaction[];
  }

  /**
   * Upload transaction batch to backend
   *
   * POST /api/sync/transactions with Bearer token authentication.
   *
   * @private
   * @param apiUrl Backend API base URL
   * @param transactions Batch of transactions to upload
   * @returns SyncTransactionsResponse from backend
   * @throws Error if network fails or response invalid
   */
  private async uploadTransactionBatch(
    apiUrl: string,
    transactions: LocalTransaction[]
  ): Promise<SyncTransactionsResponse> {
    const url = new URL('/api/sync/transactions', apiUrl).toString();

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.apiToken}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ transactions }),
    });

    if (!response.ok) {
      throw new Error(
        `Backend returned ${response.status}: ${response.statusText}`
      );
    }

    const data = await response.json();

    // Validate response structure
    if (
      !Array.isArray(data.accepted_ids) ||
      !data.rejected ||
      typeof data.rejected.count !== 'number' ||
      typeof data.member_balances !== 'object'
    ) {
      throw new Error('Invalid sync response from backend');
    }

    return data as SyncTransactionsResponse;
  }

  /**
   * Atomically update local state after successful sync
   *
   * Executes in single SQLite transaction (BEGIN/COMMIT/ROLLBACK).
   * If any step fails, entire transaction is rolled back (no partial state).
   *
   * Steps:
   * 1. Mark accepted transactions as synced
   * 2. Update member_balance for each member in response
   * 3. Update sync_state timestamp
   *
   * @private
   * @param response Backend sync response
   * @returns Number of member balances updated
   * @throws Error if database transaction fails (causes ROLLBACK)
   */
  private atomicSyncUpdate(response: SyncTransactionsResponse): number {
    let balancesUpdated = 0;

    try {
      // Begin atomic transaction
      const updateStmt = this.db.prepare(`
        UPDATE transactions
        SET synced = 1
        WHERE id = ?
      `);

      const balanceStmt = this.db.prepare(`
        INSERT INTO members_balance (member_id, balance_cents, last_updated_at)
        VALUES (?, ?, ?)
        ON CONFLICT(member_id) DO UPDATE SET
          balance_cents = excluded.balance_cents,
          last_updated_at = excluded.last_updated_at
      `);

      const syncStateStmt = this.db.prepare(`
        UPDATE sync_state
        SET last_sync_at = ?, last_transaction_count = ?
        WHERE id = 1
      `);

      // Execute in single transaction
      const transaction = this.db.transaction(() => {
        const now = new Date().toISOString();

        // Step 1: Mark accepted transactions as synced
        for (const txId of response.accepted_ids) {
          updateStmt.run(txId);
        }

        // Step 2: Update member balances
        for (const [memberId, balance] of Object.entries(response.member_balances)) {
          balanceStmt.run(memberId, balance, now);
          balancesUpdated++;
        }

        // Step 3: Update sync state
        syncStateStmt.run(now, response.accepted_ids.length);
      });

      transaction();

      return balancesUpdated;
    } catch (error) {
      console.error(
        `[Sync] Atomic update failed: ${error instanceof Error ? error.message : String(error)}`
      );
      // Transaction automatically rolled back by better-sqlite3 on error
      throw error;
    }
  }

  /**
   * Get current balance for a member
   *
   * Works OFFLINE - reads from local SQLite cache.
   * Returns 0 if member has no balance record yet.
   *
   * @param memberId Member UUID
   * @returns Current balance in cents
   */
  getMemberBalance(memberId: string): number {
    const stmt = this.db.prepare(`
      SELECT balance_cents
      FROM members_balance
      WHERE member_id = ?
    `);

    const result = stmt.get(memberId) as { balance_cents: number } | undefined;
    return result?.balance_cents ?? 0;
  }

  /**
   * Get balance update timestamp for a member
   *
   * Used to display "Last updated X hours ago" message.
   * Warns user if balance is >24h stale.
   *
   * @param memberId Member UUID
   * @returns ISO 8601 timestamp or null if never synced
   */
  getBalanceLastUpdated(memberId: string): string | null {
    const stmt = this.db.prepare(`
      SELECT last_updated_at
      FROM members_balance
      WHERE member_id = ?
    `);

    const result = stmt.get(memberId) as { last_updated_at: string } | undefined;
    return result?.last_updated_at ?? null;
  }

  /**
   * Check if balance is stale (>24 hours old)
   *
   * @param memberId Member UUID
   * @returns true if balance hasn't been updated in 24+ hours
   */
  isBalanceStale(memberId: string): boolean {
    const lastUpdated = this.getBalanceLastUpdated(memberId);

    if (!lastUpdated) {
      return true; // Never synced
    }

    const lastUpdateTime = new Date(lastUpdated).getTime();
    const now = new Date().getTime();
    const hoursAgo = (now - lastUpdateTime) / (1000 * 60 * 60);

    return hoursAgo > 24;
  }

  /**
   * Count unsynced transactions for a member
   *
   * Used to show "N pending transactions" in UI.
   *
   * @param memberId Member UUID (optional - if provided, counts only for that member)
   * @returns Number of unsynced transactions
   */
  countUnsyncedTransactions(memberId?: string): number {
    let stmt;

    if (memberId) {
      stmt = this.db.prepare(`
        SELECT COUNT(*) as count
        FROM transactions
        WHERE member_id = ? AND synced = 0
      `);
      const result = stmt.get(memberId) as { count: number };
      return result.count;
    } else {
      stmt = this.db.prepare(`
        SELECT COUNT(*) as count
        FROM transactions
        WHERE synced = 0
      `);
      const result = stmt.get() as { count: number };
      return result.count;
    }
  }

  /**
   * Get last sync timestamp
   *
   * @returns ISO 8601 timestamp or null if never synced
   */
  getLastSyncTime(): string | null {
    const stmt = this.db.prepare(`
      SELECT last_sync_at
      FROM sync_state
      WHERE id = 1
    `);

    const result = stmt.get() as { last_sync_at: string | null } | undefined;
    return result?.last_sync_at ?? null;
  }
}

/**
 * Example Usage
 *
 * ```typescript
 * import Database from 'better-sqlite3';
 * import { SyncService } from './syncService';
 *
 * // Initialize
 * const db = new Database(':memory:');
 * const syncService = new SyncService(db, 'my-api-token');
 *
 * // Sync transactions
 * const result = await syncService.syncTransactions('http://localhost:8080');
 * console.log(`Synced: ${result.acceptedCount} accepted, ${result.balancesUpdated} balances updated`);
 *
 * // Get current balance (works offline)
 * const balance = syncService.getMemberBalance('123e4567-e89b-12d3-a456-426614174000');
 * console.log(`Balance: €${(balance / 100).toFixed(2)}`);
 *
 * // Check if stale
 * if (syncService.isBalanceStale(memberId)) {
 *   console.warn('Balance is >24h old, show warning to user');
 * }
 * ```
 */
