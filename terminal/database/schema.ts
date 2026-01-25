/**
 * Terminal SQLite Database Schema
 *
 * Defines the SQLite schema for the Terminal app running on Electron/offline.
 * This is the source of truth for terminal database structure.
 *
 * Implements:
 * - ADR-0023: Terminal Balance State Management (members_balance table)
 * - ADR-0024: Transaction History Retrieval (transitive, no cache)
 * - ADR-0012: Eventual Consistency and Frontend Caching
 *
 * Tables:
 * - members_cache: Cached member data from backend sync
 * - members_balance: Current balance per member (updated atomically on sync)
 * - transactions: Local transaction queue (purchase records)
 * - sync_state: Metadata about last sync (timestamps, transaction counts)
 * - categories_cache: Cached category data
 * - products_cache: Cached product data
 *
 * Philosophy:
 * - Immutable: Never update/delete transactions (append-only)
 * - Eventual consistency: Cache updates on sync from backend
 * - Offline-first: All core features (purchase, balance) work without network
 * - Atomic: Balance updates synchronized with transaction status changes
 */

/**
 * Initialize SQLite database and create all tables
 *
 * Call this on first app launch or after schema changes.
 *
 * @param db SQLite database connection (from sql.js or better-sqlite3)
 * @returns Promise<void>
 */
export async function initializeDatabase(db: any): Promise<void> {
  // Enable foreign keys
  db.run('PRAGMA foreign_keys = ON');

  // Create members_cache table
  db.run(`
    CREATE TABLE IF NOT EXISTS members_cache (
      id BINARY(16) PRIMARY KEY,
      card_uid VARCHAR(20),
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      email VARCHAR(255),
      preferred_language VARCHAR(10) DEFAULT 'de',
      is_active BOOLEAN DEFAULT 1,
      synced_at DATETIME,

      UNIQUE(card_uid),
      INDEX idx_card_uid (card_uid),
      INDEX idx_synced_at (synced_at)
    )
  `);

  // Create members_balance table
  // Implements ADR-0023: Terminal Balance State Management
  // Balance is updated atomically when transactions are marked synced
  db.run(`
    CREATE TABLE IF NOT EXISTS members_balance (
      member_id BINARY(16) PRIMARY KEY,
      balance_cents INTEGER NOT NULL DEFAULT 0,
      last_updated_at DATETIME NOT NULL,

      FOREIGN KEY (member_id) REFERENCES members_cache(id),
      INDEX idx_last_updated (last_updated_at)
    )
  `);

  // Create transactions table (local queue)
  // Implements ADR-0004: Immutable Transaction Storage
  // Transactions are append-only; corrections via reverse transactions
  db.run(`
    CREATE TABLE IF NOT EXISTS transactions (
      id BINARY(16) PRIMARY KEY,
      member_id BINARY(16) NOT NULL,
      product_id BINARY(16),
      amount_cents INTEGER NOT NULL,
      transaction_type VARCHAR(20) DEFAULT 'purchase',
      notes VARCHAR(500),
      related_transaction_id BINARY(16),
      synced BOOLEAN DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

      FOREIGN KEY (member_id) REFERENCES members_cache(id),
      INDEX idx_member_id (member_id),
      INDEX idx_synced (synced),
      INDEX idx_created_at (created_at),
      INDEX idx_member_synced (member_id, synced)
    )
  `);

  // Create categories_cache table
  db.run(`
    CREATE TABLE IF NOT EXISTS categories_cache (
      id BINARY(16) PRIMARY KEY,
      names JSON NOT NULL,
      display_order INTEGER,
      is_active BOOLEAN DEFAULT 1,
      synced_at DATETIME,

      INDEX idx_is_active (is_active),
      INDEX idx_display_order (display_order)
    )
  `);

  // Create products_cache table
  db.run(`
    CREATE TABLE IF NOT EXISTS products_cache (
      id BINARY(16) PRIMARY KEY,
      category_id BINARY(16),
      names JSON NOT NULL,
      descriptions JSON,
      price_cents INTEGER NOT NULL,
      is_active BOOLEAN DEFAULT 1,
      synced_at DATETIME,

      FOREIGN KEY (category_id) REFERENCES categories_cache(id),
      INDEX idx_category_id (category_id),
      INDEX idx_is_active (is_active),
      INDEX idx_category_active (category_id, is_active)
    )
  `);

  // Create sync_state table
  // Tracks last successful sync for delta sync on next connection
  db.run(`
    CREATE TABLE IF NOT EXISTS sync_state (
      id INTEGER PRIMARY KEY CHECK (id = 1),
      last_sync_at DATETIME,
      last_members_sync DATETIME,
      last_categories_sync DATETIME,
      last_products_sync DATETIME,
      last_transaction_count INTEGER DEFAULT 0,

      CHECK (id = 1)
    )
  `);

  // Initialize sync_state with single row
  db.run(`
    INSERT OR IGNORE INTO sync_state (id, last_sync_at)
    VALUES (1, NULL)
  `);
}

/**
 * Table Schemas for Reference
 *
 * ============================================================================
 * TABLE: members_cache
 * PURPOSE: Cached member data from backend sync
 * ============================================================================
 * id              | BINARY(16) | Primary key (UUID from backend)
 * card_uid        | VARCHAR(20)| RFID/NFC card identifier
 * first_name      | VARCHAR(100)| Member first name (nullable for GDPR)
 * last_name       | VARCHAR(100)| Member last name (nullable for GDPR)
 * email           | VARCHAR(255)| Contact email
 * preferred_language| VARCHAR(10)| ISO 639-1 code (de, en, fr, etc)
 * is_active       | BOOLEAN    | Active/blocked flag
 * synced_at       | DATETIME   | When this record was last synced from backend
 *
 * ============================================================================
 * TABLE: members_balance
 * PURPOSE: Current balance per member (Milestone 2.A)
 * ============================================================================
 * member_id       | BINARY(16) | Foreign key to members_cache
 * balance_cents   | INTEGER    | Current balance in cents (€X.XX)
 * last_updated_at | DATETIME   | When balance was last updated from sync
 *
 * UPDATE STRATEGY:
 * - Updated ATOMICALLY when POST /sync/transactions completes
 * - Backend returns member_balances in sync response
 * - Terminal applies balance + transaction status update in single DB transaction
 * - If sync fails, previous balance remains (graceful degradation)
 * - Balance is sum of all unsettled transactions (never calculated locally)
 *
 * ============================================================================
 * TABLE: transactions
 * PURPOSE: Local transaction queue for offline operation
 * ============================================================================
 * id              | BINARY(16) | Primary key (UUID generated on terminal)
 * member_id       | BINARY(16) | Which member made this purchase
 * product_id      | BINARY(16) | Which product (nullable for corrections)
 * amount_cents    | INTEGER    | Amount in cents (positive=charge, negative=credit)
 * transaction_type| VARCHAR(20)| 'purchase' or 'correction'
 * notes           | VARCHAR(500)| Description (for corrections)
 * related_transaction_id| BINARY(16)| Original transaction (for corrections)
 * synced          | BOOLEAN    | Whether synced to backend (0=pending, 1=synced)
 * created_at      | DATETIME   | Transaction timestamp (immutable)
 *
 * ATOMICITY:
 * - synced flag updated in same transaction as members_balance update
 * - BEGIN/COMMIT/ROLLBACK ensures all-or-nothing
 * - Network failure = ROLLBACK = no partial updates
 *
 * ============================================================================
 * TABLE: categories_cache
 * PURPOSE: Cached category data for product filtering
 * ============================================================================
 * id              | BINARY(16) | Primary key (UUID from backend)
 * names           | JSON       | {"de": "Getränke", "en": "Beverages"}
 * display_order   | INTEGER    | Sort position for UI
 * is_active       | BOOLEAN    | Category available
 * synced_at       | DATETIME   | Last sync timestamp
 *
 * ============================================================================
 * TABLE: products_cache
 * PURPOSE: Cached product catalog for offline purchasing
 * ============================================================================
 * id              | BINARY(16) | Primary key (UUID from backend)
 * category_id     | BINARY(16) | Category this product belongs to
 * names           | JSON       | {"de": "Pils", "en": "Pilsner"}
 * descriptions    | JSON       | {"de": "Beschreibung", "en": "Description"}
 * price_cents     | INTEGER    | Price in cents (e.g., 350 = €3.50)
 * is_active       | BOOLEAN    | Available for sale
 * synced_at       | DATETIME   | Last sync timestamp
 *
 * TERMINAL DISPLAY:
 * - Terminal selects product name from names[member.preferred_language]
 * - Falls back to first available language if preference not found
 *
 * ============================================================================
 * TABLE: sync_state
 * PURPOSE: Track sync timestamps for delta sync on reconnection
 * ============================================================================
 * id              | INTEGER    | Always 1 (singleton)
 * last_sync_at    | DATETIME   | When entire sync completed
 * last_members_sync| DATETIME   | When members were last synced
 * last_categories_sync| DATETIME| When categories were last synced
 * last_products_sync| DATETIME  | When products were last synced
 * last_transaction_count| INTEGER| How many transactions were synced
 */

/**
 * TypeScript Interfaces for Database Operations
 */

export interface Member {
  id: string; // UUID
  card_uid: string | null;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  preferred_language: string;
  is_active: boolean;
  synced_at: string | null;
}

export interface MemberBalance {
  member_id: string; // UUID
  balance_cents: number;
  last_updated_at: string;
}

export interface Transaction {
  id: string; // UUID
  member_id: string;
  product_id: string | null;
  amount_cents: number;
  transaction_type: 'purchase' | 'correction';
  notes: string | null;
  related_transaction_id: string | null;
  synced: boolean;
  created_at: string;
}

export interface Category {
  id: string;
  names: { [lang: string]: string };
  display_order: number | null;
  is_active: boolean;
  synced_at: string | null;
}

export interface Product {
  id: string;
  category_id: string | null;
  names: { [lang: string]: string };
  descriptions: { [lang: string]: string } | null;
  price_cents: number;
  is_active: boolean;
  synced_at: string | null;
}

export interface SyncState {
  id: 1;
  last_sync_at: string | null;
  last_members_sync: string | null;
  last_categories_sync: string | null;
  last_products_sync: string | null;
  last_transaction_count: number;
}
