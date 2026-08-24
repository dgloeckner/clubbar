# ADR-0004: Immutable Storage of Purchase Transactions

**Status**: Accepted (corrected 2026-08-23: the settlement sketch below no longer declares `sepa_message_id`, dropped by migration 054 — see [ADR-0008](./0008-sepa-xml-export-format-selection.md))

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

The Club Bar system records financial transactions (purchases, corrections) across multiple terminals and a backend database. Transactions form the foundation of:

- **Member accounting**: Calculate outstanding balance (open amount owed)
- **Audit trails**: Full history for compliance and dispute resolution
- **Synchronization**: Offline terminal sync to backend without conflicts
- **Settlement reporting**: Generate accounting records and SEPA exports
- **GDPR compliance**: Retention of transaction records for 10 years (per tax law § 147 AO)

Key requirements and constraints:

1. **Audit & Compliance**: Must retain complete history; never lose data
2. **Offline-first sync**: Multiple terminals may create transactions offline; no real-time conflict resolution
3. **Idempotency**: Same transaction uploaded twice should not create duplicates
4. **Corrections needed**: Admin/system must handle user errors (wrong amount, wrong product)
5. **Conflict-free**: Distributed system; no complex merge logic needed
6. **Eventually consistent**: Terminal and backend may be temporarily out of sync
7. **Settlement workflow**: Admin marks outstanding transactions as "paid" (settled), generates SEPA exports for bank processing

### Problem Without Immutability

If transactions are mutable (UPDATE/DELETE allowed):

```sql
-- Member scans card, purchases Pils for €3.50
INSERT INTO transactions (id, member_id, product_id, amount_cents, created_at)
VALUES ('uuid1', 'member123', 'product456', 350, NOW());

-- Admin realizes wrong amount, updates
UPDATE transactions SET amount_cents = 380 WHERE id = 'uuid1';  -- PROBLEM!

-- Issues:
-- 1. Audit trail lost: what was the original amount?
-- 2. Sync conflict: terminal uploaded uuid1 with 350, backend changed to 380
-- 3. Compliance risk: tax records show different amounts over time
-- 4. Settlement confusion: which version was settled?
-- 5. No history: can't prove what actually happened
```

---

## Decision

**Purchase transactions are immutable and append-only. Once created, they are never modified or deleted. A booking is corrected by a *storno* — a reverse transaction that names the transaction it reverses and negates it exactly. Settlement marks transactions as "paid" without modifying them; SEPA exports enable external bank processing.**

> **Amended 2026-08-07.** The original decision allowed a free-amount `correction` in two shapes: linked to an original, or standalone. The standalone shape is **removed** — see [#158](https://github.com/dgloeckner/clubbar/issues/158) and [#170](https://github.com/dgloeckner/clubbar/issues/170), and [ADR-0028](./0028-legal-constraints-on-money-handling.md) §4 for the GoBD Rz. 64 requirement that made an optional link insufficient. Amended text is marked inline.
>
> **Amended 2026-08-09 — corrections include a `payout`, and the principle reaches the settlement layer.** Two additions, both recorded in [ADR-0032](./0032-settlement-lifecycle.md):
>
> - **`payout` is a third transaction type.** A member can end up in credit — a storno of an already-collected purchase leaves the club owing money back under § 812 BGB. For a member who stays, that credit carries forward and is drunk off. For a **departing** member it has to be paid, and the payment is recorded as a `payout` transaction that returns the balance to zero. It is reachable only through offboarding; there is no standalone payout surface and no free-amount input anywhere in the system.
> - **Settlement reversal follows the same append-only principle.** When the bank claws back a collection, or the club discovers an error in a run it already submitted, nothing is mutated and nothing is deleted. A `settlement_reversals` row is **appended** naming the settlement, the member, the reason and the bank's reference; the settlement's line items survive, with only their live claim released so the transactions become settleable again. A settlement's state is *derived* from those events, never stored — for the same reason a transaction is never edited: a stored summary is a second copy of a fact, and it drifts.
>
> The settlement schema quoted below predates this and is **superseded by [ADR-0032](./0032-settlement-lifecycle.md)**, which is authoritative for `settlements` and `settlement_items`. In particular: cancellation no longer deletes `settlement_items`, `submitted_at` now marks the point of no return, and `settlements.method` distinguishes a direct debit from a bank transfer or a write-off.

### Core Principles

1. **Append-only transactions**: Only INSERT allowed; no UPDATE or DELETE on transactions table
2. **Corrections via storno**: Error detected → create a storno naming the original (`related_transaction_id`, never NULL), with the amount derived as the exact negation. The amount is never supplied by the caller. A transaction can be stornoed at most once, and a storno cannot itself be stornoed.
3. **Immutable history**: Complete audit trail preserved; nothing lost or hidden
4. **UUID-based identity**: Each transaction has permanent, unique identifier
5. **Timestamp immutable**: created_at never changes; reflects actual time transaction occurred
6. **Settlement is separate**: Settlement marks transactions as "settled" via flag/status, doesn't create transaction records
7. **External bank processing**: SEPA exports used for actual bank transfers; system doesn't track bank confirmations

### Database Schema

#### Transactions Table (Immutable)

```sql
CREATE TABLE transactions (
  id BINARY(16) PRIMARY KEY,
  member_id BINARY(16) NOT NULL,
  product_id BINARY(16),                    -- NULL for stornos and payouts; a purchase always names a product
  amount_cents INT NOT NULL,                -- Positive: purchase, Negative: storno (derived, never typed)
  created_at DATETIME NOT NULL,             -- Immutable: actual transaction time
  notes VARCHAR(500),                       -- Reason (required for storno)
  transaction_type ENUM(                    -- AMENDED 2026-08-07
    'purchase',                             -- Terminal sale; always names a product (no admin-booked purchases — UC-A21 rejected)
    'storno',                               -- Full reversal of exactly one transaction; amount derived
    'payout'                                -- Money returned to a member in credit (offboarding only)
  ) NOT NULL,

  -- Audit fields (immutable)
  created_by_terminal_id BINARY(16),        -- Which terminal created this
  created_by_admin_id BINARY(16),           -- Which admin (if manual entry)
  related_transaction_id BINARY(16),        -- AMENDED: NOT NULL for 'storno' (GoBD Rz. 64); NULL otherwise
                                            -- UNIQUE: a transaction may be stornoed at most once

  -- Sync status (terminal-only; not relevant to backend)
  synced BOOLEAN DEFAULT FALSE,

  -- Constraints
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (related_transaction_id) REFERENCES transactions(id),
  INDEX idx_member_id (member_id),
  INDEX idx_created_at (created_at),
  INDEX idx_transaction_type (transaction_type)
) ENGINE=InnoDB;

-- IMPORTANT: No UPDATE or DELETE operations allowed on this table
-- Corrections are made via new rows (correction transactions), not modifications
```

#### Settlements Table (Settlement Workflow Tracking)

```sql
CREATE TABLE settlements (
  id BINARY(16) PRIMARY KEY,
  settlement_date DATE NOT NULL,            -- Date settlement was created
  execution_date DATE NOT NULL,             -- SEPA execution date (>= the server's today + 7 days, ADR-0009)
  created_by_admin_id BINARY(16) NOT NULL,  -- Which admin created settlement
  settlement_period_start DATE,             -- Optional: accounting period start
  settlement_period_end DATE,               -- Optional: accounting period end
  notes VARCHAR(500),                       -- Optional: description of settlement
  is_cancelled BOOLEAN DEFAULT FALSE,       -- If true, transactions are unsettled
  cancelled_at DATETIME,                    -- When settlement was cancelled
  cancelled_by_admin_id BINARY(16),         -- Who cancelled
  exported_at DATETIME,                     -- Last export timestamp (audit log entry per export)
  -- (no sepa_message_id: dropped by migration 054. The pain.008 MsgId is
  --  derived from this row's own id -- see ADR-0008, ID Generation Strategy)
  created_at DATETIME DEFAULT NOW(),
  updated_at DATETIME DEFAULT NOW() ON UPDATE NOW(),

  INDEX idx_is_cancelled (is_cancelled),
  INDEX idx_settlement_date (settlement_date),
  FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;

-- Tracks which transactions belong to which settlement
CREATE TABLE settlement_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  settlement_id BINARY(16) NOT NULL,
  transaction_id BINARY(16) NOT NULL,
  -- When settlement is created, these transactions are marked as "settled"
  -- When settlement is cancelled, they become unsettled again

  FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  UNIQUE KEY unique_settlement_transaction (settlement_id, transaction_id),
  INDEX idx_settlement_id (settlement_id),
  INDEX idx_transaction_id (transaction_id)
) ENGINE=InnoDB;
```

#### View: Outstanding Balance (Calculated)

```sql
-- Calculate member balance (open/unpaid amounts)
-- Excludes transactions marked as settled
CREATE VIEW member_outstanding_balance AS
SELECT
  m.id as member_id,
  m.first_name,
  m.last_name,
  SUM(CASE WHEN t.amount_cents > 0 THEN t.amount_cents ELSE 0 END) as total_purchases_cents,
  SUM(CASE WHEN t.amount_cents < 0 THEN t.amount_cents ELSE 0 END) as total_credits_cents,
  SUM(t.amount_cents) as balance_cents
FROM members m
LEFT JOIN transactions t ON m.id = t.member_id
LEFT JOIN settlement_items si ON t.id = si.transaction_id
WHERE si.transaction_id IS NULL  -- Exclude settled transactions
  AND m.is_active = TRUE
  AND (m.deleted_at IS NULL OR m.deleted_at > NOW())  -- Exclude anonymized members
GROUP BY m.id, m.first_name, m.last_name;
```

### Settlement Workflow Sequence

The settlement workflow marks transactions as settled without modifying them. Settlement is a pure accounting operation:

```mermaid
sequenceDiagram
    participant Admin as Admin User
    participant UI as Admin UI
    participant API as Backend API
    participant DB as Database

    Admin->>UI: Create Settlement (select execution date)
    UI->>API: POST /api/settlements {execution_date, period_start, period_end}
    API->>DB: Insert settlement + settlement_items (transactions marked settled)
    DB-->>API: settlement_id
    API-->>UI: Settlement created

    Admin->>UI: Export CSV/SEPA XML
    UI->>API: GET /api/settlements/{id}/export-csv
    API->>DB: Query settled transactions SUM per member
    API->>DB: Update exported_at timestamp
    API->>DB: Insert audit_log entry
    DB-->>API: Member balances
    API-->>Admin: Download CSV file

    Note over Admin,DB: CSV/XML used outside system for bank transfers
    Admin->>Admin: (Manual step) Upload to bank, process transfers

    opt Cancel Settlement
        Admin->>UI: Cancel settlement
        UI->>API: POST /api/settlements/{id}/cancel
        API->>DB: Set is_cancelled=true, cancelled_at, cancelled_by_admin_id
        API->>DB: (Transactions become unsettled - available for future settlement)
        API-->>UI: Settlement cancelled
    end
```

**Settlement Model:**
- Created immediately (no draft stage) - transactions marked as settled
- `is_cancelled`: If true, settlement is void; transactions become unsettled again
- `exported_at`: Timestamp of last export; audit log entry created per export

**Query Examples (Pseudocode):**

Outstanding balance (unpaid transactions):
```
SELECT member_id, SUM(amount_cents)
FROM transactions
WHERE id NOT IN (
  SELECT si.transaction_id
  FROM settlement_items si
  JOIN settlements s ON si.settlement_id = s.id
  WHERE s.is_cancelled = FALSE
)
GROUP BY member_id
```

Settled transactions (historical):
```
SELECT t.member_id, s.settlement_date, SUM(t.amount_cents)
FROM settlement_items si
JOIN transactions t ON si.transaction_id = t.id
JOIN settlements s ON si.settlement_id = s.id
WHERE s.is_cancelled = FALSE
GROUP BY t.member_id, s.settlement_date
```

**CSV Export Format:**
```
First Name,Last Name,IBAN,BIC,Amount EUR
"Max","Mustermann","DE89370400440532013000","COBADEFFXXX","47.50"
"Anna","Schmidt","DE89370400440532013001","COBADEFFXXX","82.30"
```

External bank processing (outside system): CSV imported into bank software; transfers processed; no confirmation loop in this system.

### Correcting a Transaction (Immutable Pattern)

Error correction requires creating new correction transaction (never modify original):

**Scenario A**: Member charged €5.00 instead of €3.50

**Approach:**
1. Storno the original: `transaction_type = 'storno'`, `related_transaction_id = original_id`, `notes = 'Wrong amount'`. `amount_cents = -500` is **derived**, not supplied.
2. Create a new purchase: `amount_cents = 350`, `transaction_type = 'purchase'`
3. Result: member balance = 500 − 500 + 350 = 350 cents (€3.50)
4. Audit trail preserved: all three transactions visible, linked via `related_transaction_id`

There is no partial correction. Reverse the whole booking, then book the right one.

**Scenario B**: ~~Manual balance adjustment (standalone correction)~~ — **REMOVED 2026-08-07**

A standalone adjustment with `related_transaction_id = NULL` is **no longer a supported operation**. Two reasons:

- **GoBD Rz. 64** requires a correction be traceable to the booking it corrects, and Rz. 73 rejects a free-text reason as sufficient at volume ([ADR-0028](./0028-legal-constraints-on-money-handling.md) §4). An *optional* foreign key cannot express a mandatory rule.
- Every real instance turned out to be something else ([#170](https://github.com/dgloeckner/clubbar/issues/170)): a drink that should be stornoed, a drink that was never booked, a charge that is a manual `purchase`, or a credit returned at offboarding as a `payout`.

The original example — *"Goodwill credit for service issue"* — is the case that does not survive. Beyond the audit-trail objection, crediting a member's tab as a gesture is **Mitgliederbegünstigung** and constrained by § 55 AO for a gemeinnütziger Verein. If the club ever needs it, it gets its own transaction type, as `payout` did; it must never return as a free-amount correction.

---

## Consequences

### Positive

✅ **Complete audit trail**: Every purchase and correction recorded; nothing hidden
✅ **Conflict-free sync**: No UPDATE/DELETE conflicts between terminals
✅ **Immutable source**: Transactions never modified; basis for settlement is reliable
✅ **Settlement flexible**: Can create multiple settlements; mark different subsets of transactions
✅ **Idempotent**: Retry the same transaction safely — the client-generated `id` is the key, and only a duplicate-key violation is treated as "already stored" ([ADR-0033](./0033-terminal-sync-contract.md) §5)
✅ **GDPR-compliant**: Transaction records contain no PII after member anonymization — only UUID linkage remains. Retained per [Art. 17(3)(b) GDPR](https://gdpr-info.eu/art-17-gdpr/) exception for legal obligations (§ 147 AO). Anonymized transaction data falls outside GDPR scope per [Recital 26](https://gdpr-info.eu/recitals/no-26/)
✅ **Dispute resolution**: Full evidence of purchases and corrections
✅ **Compliance-ready**: Complete history for audits
✅ **Corrections are always traceable**: every storno links to its original by construction, not by convention
✅ **Separate concerns**: Transactions immutable; settlement is accounting operation

### Negative

❌ **Storage overhead**: Corrections add rows (correction + new purchase); tables grow over time
❌ **Query complexity**: Balance calculation requires SUM with settlement_items JOIN
❌ **Admin workflow**: Corrections require understanding correction transaction concept
❌ **Export-dependent**: Settlement relies on manual external bank processing (no confirmation loop)
❌ **Manual CSV handling**: Error-prone if CSV manually edited or duplicated

### Mitigations

1. **Storage**: Database compression; archive old data per retention policy
2. **Query complexity**: Use indexed views and materialized balance tables
3. **Admin education**: Clear UI for correction workflow (label "reverse & correct")
4. **CSV safety**:
   - Validate CSV before export
   - Include checksums or transaction counts
   - Version filename (settlement_YYYYMMDD_v1.csv)
   - Keep audit log of exports
   - Require confirmation before finalization

---

## Alternatives Considered

### Alternative 1: Track Settlement Transactions

Create new "settlement" transaction type that records when payment is made.

```sql
INSERT INTO transactions (type='settlement', amount_cents=-balance, ...)
```

**Pros**: Settlement is explicit in transaction log

**Cons**:
- Creates noise (artificial transactions for accounting action)
- Doubles row count during settlement
- Confuses payment tracking (which transaction is "real"?)
- Harder to modify settlement if needed

**Rejected**: Settlement is administrative, not transactional. Separate table cleaner.

### Alternative 2: Direct Member Balance Table

Track member balance in separate denormalized table, update on settlement.

```sql
CREATE TABLE member_balance (
  member_id ...,
  outstanding_cents INT,
  last_settled_date DATE
);

-- On settlement:
UPDATE member_balance SET outstanding_cents = 0, last_settled_date = NOW();
```

**Pros**: Fast balance lookups

**Cons**:
- Loses transactional source (can't recalculate from transactions)
- Settlement undoable only with history
- Audit trail fragmented (balance and transactions separate)
- Difficult to verify correctness

**Rejected**: SUM query sufficient; transaction-sourced balance more reliable.

### Alternative 3: Bank Confirmation Tracking

Track actual bank payment confirmations in system.

```sql
CREATE TABLE bank_confirmations (
  settlement_id ...,
  member_id ...,
  bank_reference ...,
  status ('pending', 'completed', 'failed'),
  ...
);
```

**Pros**: Complete payment tracking in system

**Cons**:
- Requires integration with bank API/processes
- Not applicable to small orgs using manual bank processing
- Adds scope beyond offline-first design
- Many deployments won't have bank API access

**Rejected**: Out of scope; system design is offline-first. Bank processing external.

### Alternative 4: Soft Delete on Settlement

Mark transactions as "deleted" when settled (deleted_at field).

```sql
UPDATE transactions SET deleted_at = NOW() WHERE id IN (...);
```

**Pros**: Transactions "disappear" from UI after settlement

**Cons**:
- Not truly immutable (logically deleted)
- GDPR deletion must still purge row
- Query complexity (WHERE deleted_at IS NULL everywhere)
- Easy to forget WHERE clause; show wrong data

**Rejected**: Violates immutability principle.

---

## Implementation Notes

**Database Schema**: Transactions table must have NO UPDATE/DELETE triggers or procedures. Settlement workflow uses settlement_items table to link transactions to settlements (read-only join).

**API Endpoints:**
- `POST /api/sync/transactions`: Terminal batch upload; idempotent on the client-generated `id` — plain `INSERT`, duplicate-key caught, never `INSERT IGNORE` ([ADR-0033](./0033-terminal-sync-contract.md))
- `POST /api/admin/transactions/{id}/storno`: Reverse one booking in full; the amount is derived from the target, never supplied
- `POST /api/admin/settlements`: Create settlement (marks the named members' unsettled transactions as settled)
- `GET /api/admin/settlements/{id}/export-csv`: Generate CSV
- `POST /api/admin/settlements/{id}/cancel`: Undo a run that has not moved money; the line items survive, only their live claim is released ([ADR-0032](./0032-settlement-lifecycle.md))
- `POST /api/admin/settlements/{id}/reverse`: Record that money which did move has come back, per member

**Admin UI Settlement Workflow:**
- Date range selection
- Preview of outstanding transactions per member
- Checkboxes to select transactions
- Confirmation before finalize
- CSV/SEPA export download

---

## Related Decisions

- [ADR-0001: Monetary Values as Integer Cents](./0001-monetary-values-as-integer-cents.md) - Transaction amounts stored as integer cents
- [ADR-0002: Product Internationalization](./0002-product-internationalization.md) - Products in transactions
- [ADR-0003: GZIP Compression](./0003-gzip-compression-http.md) - Transaction sync payloads compressed
- [ADR-0012: Eventual Consistency and Frontend Caching](./0012-eventual-consistency-frontend-caching.md) - Terminal caches transactions locally; syncs periodically
- [ADR-0023: Terminal Balance State Management](./0023-terminal-balance-state-management.md) - Balance calculated from immutable transaction log
- [ADR-0024: Transaction History Retrieval in Terminal](./0024-transaction-history-retrieval-terminal.md) - Terminal retrieves transaction history on-demand from backend
- [ADR-0028: Legal Constraints on Money Handling](./0028-legal-constraints-on-money-handling.md) - GoBD Rz. 64 makes the storno→original link a legal requirement, not a convenience
- [ADR-0032: Settlement Lifecycle](./0032-settlement-lifecycle.md) - **Authoritative for the settlement schema and workflow**; extends append-only to the settlement layer
- [ADR-0033: Terminal Sync Contract](./0033-terminal-sync-contract.md) - How the immutable rows get here, and the field authority that keeps a terminal from forging a storno

---

## References

- **GDPR**:
  - [Art. 5(1)(e)](https://gdpr-info.eu/art-5-gdpr/) — Storage limitation: identifiable data kept no longer than necessary for its purpose
  - [Art. 16 Right to Rectification](https://gdpr-info.eu/art-16-gdpr/) — Right to correct inaccurate data
  - [Art. 17 Right to Erasure](https://gdpr-info.eu/art-17-gdpr/) — Right to delete data
  - [Art. 17(3)(b)](https://gdpr-info.eu/art-17-gdpr/) — Exception: erasure not required where retention is a legal obligation (§ 147 AO)
  - [Recital 26](https://gdpr-info.eu/recitals/no-26/) — Truly anonymous data (no reasonable means to re-identify) falls outside GDPR scope entirely

- **German Tax & Commercial Law**:
  - [§ 147 AO](https://www.gesetze-im-internet.de/ao_1977/__147.html) — Retention of business records: 8 years for booking vouchers (Buchungsbelege), 10 years for annual financial statements
  - [§ 257 HGB](https://www.gesetze-im-internet.de/hgb/__257.html) — Commercial retention obligations (same periods as § 147 AO)

- **Accounting Standards**:
  - [GAAP: General Accepted Accounting Principles](https://www.investopedia.com/terms/g/gaap.asp) — Requires complete transaction history

- **Software Patterns**:
  - [Event Sourcing](https://www.martinfowler.com/eaaDev/EventSourcing.html) — Immutable event logs
  - [CQRS](https://www.martinfowler.com/bliki/CQRS.html) — Command Query Responsibility Segregation

---

## Post-Implementation Monitoring

- Monitor transaction table growth
- Verify no UPDATE/DELETE operations (query logs)
- Track correction frequency (correction rate)
- Measure settlement workflow time
- Test SEPA export accuracy
- Verify audit trail completeness
- Compliance verification: can reconstruct full history
- User feedback: settlement workflow clarity
