# ADR-0004: Immutable Storage of Purchase Transactions

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

The Member Bar system records financial transactions (purchases, corrections) across multiple terminals and a backend database. Transactions form the foundation of:

- **Member accounting**: Calculate outstanding balance (open amount owed)
- **Audit trails**: Full history for compliance and dispute resolution
- **Synchronization**: Offline terminal sync to backend without conflicts
- **Settlement reporting**: Generate accounting records and bank export CSVs
- **GDPR compliance**: Retention of transaction records for 10 years (per tax law § 147 AO)

Key requirements and constraints:

1. **Audit & Compliance**: Must retain complete history; never lose data
2. **Offline-first sync**: Multiple terminals may create transactions offline; no real-time conflict resolution
3. **Idempotency**: Same transaction uploaded twice should not create duplicates
4. **Corrections needed**: Admin/system must handle user errors (wrong amount, wrong product)
5. **Conflict-free**: Distributed system; no complex merge logic needed
6. **Eventually consistent**: Terminal and backend may be temporarily out of sync
7. **Settlement workflow**: Admin marks outstanding transactions as "paid" (settled), generates CSV for bank processing

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

**Purchase transactions are immutable and append-only. Once created, they are never modified or deleted. Corrections are made via reverse transactions (negative amounts). Settlement marks transactions as "paid" without modifying them; CSV exports enable external bank processing.**

### Core Principles

1. **Append-only transactions**: Only INSERT allowed; no UPDATE or DELETE on transactions table
2. **Corrections via reverse transactions**: Error detected → create new transaction with negative amount (never modify original)
3. **Immutable history**: Complete audit trail preserved; nothing lost or hidden
4. **UUID-based identity**: Each transaction has permanent, unique identifier
5. **Timestamp immutable**: created_at never changes; reflects actual time transaction occurred
6. **Settlement is separate**: Settlement marks transactions as "settled" via flag/status, doesn't create transaction records
7. **External bank processing**: CSV export used for actual bank transfers; system doesn't track bank confirmations

### Database Schema

#### Transactions Table (Immutable)

```sql
CREATE TABLE transactions (
  id BINARY(16) PRIMARY KEY,
  member_id BINARY(16) NOT NULL,
  product_id BINARY(16),                    -- NULL for manual entries/corrections
  amount_cents INT NOT NULL,                -- Positive: purchase, Negative: correction/refund
  created_at DATETIME NOT NULL,             -- Immutable: actual transaction time
  notes VARCHAR(500),                       -- Optional: reason for correction
  transaction_type ENUM(
    'purchase',                             -- Normal product purchase (positive amount)
    'manual_credit',                        -- Manual balance adjustment (positive)
    'manual_debit',                         -- Manual balance adjustment (negative)
    'reversal'                              -- Correction/reversal of previous transaction (negative)
  ) NOT NULL,

  -- Audit fields (immutable)
  created_by_terminal_id BINARY(16),        -- Which terminal created this
  created_by_admin_id BINARY(16),           -- Which admin (if manual entry)
  related_transaction_id BINARY(16),        -- If reversal: points to corrected transaction

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
-- Corrections are made via new rows (reversals), not modifications
```

#### Settlements Table (Settlement Workflow Tracking)

```sql
CREATE TABLE settlements (
  id BINARY(16) PRIMARY KEY,
  settlement_date DATE NOT NULL,            -- Date settlement was created
  created_by_admin_id BINARY(16) NOT NULL,  -- Which admin created settlement
  settlement_period_start DATE,             -- Optional: accounting period start
  settlement_period_end DATE,               -- Optional: accounting period end
  notes VARCHAR(500),                       -- Optional: description of settlement
  status ENUM(
    'draft',                                -- Settlement created but not finalized
    'finalized',                            -- Settlement finalized; CSV generated
    'exported',                             -- CSV exported for bank processing
    'cancelled'                             -- Settlement cancelled (undo)
  ) DEFAULT 'draft',
  created_at DATETIME DEFAULT NOW(),
  updated_at DATETIME DEFAULT NOW() ON UPDATE NOW(),

  INDEX idx_status (status),
  INDEX idx_settlement_date (settlement_date),
  FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;

-- Tracks which transactions belong to which settlement
CREATE TABLE settlement_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  settlement_id BINARY(16) NOT NULL,
  transaction_id BINARY(16) NOT NULL,
  -- When settlement is created/finalized, these transactions are marked as "settled"

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

### Settlement Workflow (Abrechnung)

The settlement workflow is a pure accounting operation. It does NOT create new transaction records. Instead:

1. **Admin initiates settlement**: Decides which transactions to mark as "settled" (typically: all outstanding as of a date)
2. **System calculates totals**: Sum of outstanding transactions per member
3. **Settlement is finalized**: Marks selected transactions as "settled" (via settlement_items table)
4. **CSV export generated**: One row per member with outstanding balance
5. **External bank processing**: Human/bank system uses CSV to create actual bank transfers (outside this system)

#### Step 1: Create Settlement (Admin Action)

```php
<?php
// Admin UI: "Create Settlement (Abrechnung)"
// POST /api/settlements

$settlement = [
    'id' => Uuid::v4(),
    'settlement_date' => date('Y-m-d'),
    'settlement_period_start' => '2025-01-01',
    'settlement_period_end' => '2025-01-31',
    'notes' => 'January 2025 settlement',
    'status' => 'draft',
    'created_by_admin_id' => $adminId
];

$db->insert('settlements', $settlement);

return json_encode(['settlement_id' => $settlement['id']]);
```

#### Step 2: Select Transactions to Settle

```php
<?php
// Admin selects transactions to include in settlement
// Typically: all outstanding transactions as of settlement_date

$transactions = $db->query("
    SELECT
        t.id as transaction_id,
        m.id as member_id,
        m.first_name,
        m.last_name,
        m.iban,
        m.bic,
        SUM(t.amount_cents) as outstanding_cents
    FROM transactions t
    JOIN members m ON t.member_id = m.id
    WHERE t.created_at <= ?
      AND t.id NOT IN (
        SELECT transaction_id FROM settlement_items
      )
      AND m.is_active = TRUE
    GROUP BY m.id
    HAVING outstanding_cents > 0
", [$settlementDate]);

return json_encode($transactions);
```

#### Step 3: Finalize Settlement

```php
<?php
// Admin clicks "Finalize Settlement"
// This marks selected transactions as settled (adds to settlement_items)

$settlementId = $_POST['settlement_id'];
$transactionIds = $_POST['transaction_ids'];  // Selected by admin

foreach ($transactionIds as $txId) {
    $db->insert('settlement_items', [
        'settlement_id' => $settlementId,
        'transaction_id' => $txId
    ]);
}

// Update settlement status
$db->update('settlements', ['status' => 'finalized'], ['id' => $settlementId]);

return json_encode(['status' => 'finalized', 'transaction_count' => count($transactionIds)]);
```

#### Step 4: Generate CSV Export for Bank Processing

```php
<?php
// Admin clicks "Export CSV for Bank Processing"
// GET /api/settlements/{id}/export-csv

$settlementId = $_GET['id'];

// Fetch outstanding balances for all settled transactions
$balances = $db->query("
    SELECT DISTINCT
        m.id as member_id,
        m.first_name,
        m.last_name,
        m.iban,
        m.bic,
        SUM(t.amount_cents) as outstanding_cents
    FROM settlement_items si
    JOIN transactions t ON si.transaction_id = t.id
    JOIN members m ON t.member_id = m.id
    WHERE si.settlement_id = ?
    GROUP BY m.id
    HAVING outstanding_cents > 0
", [$settlementId]);

// Generate CSV for bank import/SEPA processing
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="settlement_export_' . date('Y-m-d') . '.csv"');

echo "First Name,Last Name,IBAN,BIC,Amount EUR\n";
foreach ($balances as $row) {
    $amountEur = $row['outstanding_cents'] / 100;
    echo sprintf(
        "\"%s\",\"%s\",\"%s\",\"%s\",\"%.2f\"\n",
        $row['first_name'],
        $row['last_name'],
        $row['iban'],
        $row['bic'],
        $amountEur
    );
}

// Update settlement status: exported
$db->update('settlements', ['status' => 'exported'], ['id' => $settlementId]);
```

**CSV Output Example:**
```
First Name,Last Name,IBAN,BIC,Amount EUR
"Max","Mustermann","DE89370400440532013000","COBADEFFXXX","47.50"
"Anna","Schmidt","DE89370400440532013001","COBADEFFXXX","82.30"
"Klaus","Weber","DE89370400440532013002","COBADEFFXXX","155.75"
```

#### Step 5: External Bank Processing (Outside System)

The CSV is used outside this system to:
- Import into bank software (e.g., SEPA tool, accounting software)
- Create actual bank transfers to members
- Process accounting records
- Generate confirmations

**This system does NOT track bank confirmations or actual payment status.** The CSV export is the handoff point.

### Implementation: Querying Settled vs Outstanding

```php
<?php
// Get outstanding balance (unpaid transactions)
$outstanding = $db->query("
    SELECT
        t.member_id,
        SUM(t.amount_cents) as outstanding_cents
    FROM transactions t
    WHERE t.id NOT IN (SELECT transaction_id FROM settlement_items)
    GROUP BY t.member_id
");

// Get settled balance (paid transactions, for historical records)
$settled = $db->query("
    SELECT
        t.member_id,
        s.settlement_date,
        SUM(t.amount_cents) as settled_cents
    FROM settlement_items si
    JOIN transactions t ON si.transaction_id = t.id
    JOIN settlements s ON si.settlement_id = s.id
    WHERE s.status IN ('finalized', 'exported')
    GROUP BY t.member_id, s.settlement_date
");
```

### Correcting a Transaction (Immutable Pattern)

**Scenario**: Admin discovers member was charged €5.00 instead of €3.50

```php
<?php
// Step 1: Create reversal transaction (never modify original)
$reversal = [
    'id' => Uuid::v4(),
    'member_id' => $originalTransaction['member_id'],
    'product_id' => null,  // Correction, not a product
    'amount_cents' => -$originalTransaction['amount_cents'],  // Negative amount
    'created_at' => date('c'),
    'transaction_type' => 'reversal',
    'created_by_admin_id' => $adminId,
    'related_transaction_id' => $originalTransactionId,
    'notes' => 'Correction: charged €5.00 instead of €3.50'
];

$db->insert('transactions', $reversal);

// Step 2: Create corrected purchase transaction
$corrected = [
    'id' => Uuid::v4(),
    'member_id' => $originalTransaction['member_id'],
    'product_id' => $originalTransaction['product_id'],
    'amount_cents' => 350,  // Correct amount
    'created_at' => date('c'),
    'transaction_type' => 'purchase',
    'created_by_terminal_id' => null,
    'created_by_admin_id' => $adminId,
    'notes' => 'Correction: correct purchase amount'
];

$db->insert('transactions', $corrected);

// Result: Member balance = €5.00 (original) - €5.00 (reversal) + €3.50 (corrected) = €3.50
// History preserved: all three transactions visible in audit trail
```

---

## Consequences

### Positive

✅ **Complete audit trail**: Every purchase and correction recorded; nothing hidden
✅ **Conflict-free sync**: No UPDATE/DELETE conflicts between terminals
✅ **Immutable source**: Transactions never modified; basis for settlement is reliable
✅ **Settlement flexible**: Can create multiple settlements; mark different subsets of transactions
✅ **Idempotent**: Retry same transaction safely (INSERT IGNORE)
✅ **GDPR-compliant**: Retains transaction history for 10 years (tax law § 147 AO)
✅ **Dispute resolution**: Full evidence of purchases and corrections
✅ **Compliance-ready**: Complete history for audits
✅ **Easy corrections**: Reversals clearly link to originals (related_transaction_id)
✅ **Separate concerns**: Transactions immutable; settlement is accounting operation

### Negative

❌ **Storage overhead**: Corrections add rows (reversal + corrected); tables grow over time
❌ **Query complexity**: Balance calculation requires SUM with settlement_items JOIN
❌ **Admin workflow**: Corrections require understanding "reversal" concept
❌ **CSV-dependent**: Settlement relies on manual external bank processing (no confirmation loop)
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

## Implementation Checklist

### Database Schema

- [ ] Create transactions table (immutable, append-only)
- [ ] Create settlements table (settlement metadata)
- [ ] Create settlement_items table (links transactions to settlements)
- [ ] Create member_outstanding_balance view (for balance queries)
- [ ] Add indexes for performance (member_id, created_at, status)
- [ ] Add FOREIGN KEY constraints
- [ ] Add UNIQUE constraint on settlement_items (settlement_id, transaction_id)

### Backend API

**Transactions:**
- [ ] `POST /api/sync/transactions`: Batch upload, INSERT IGNORE (idempotent)
- [ ] `GET /api/members/{id}/transactions`: Full history with reversals
- [ ] `POST /api/members/{id}/transactions/reverse`: Admin reversal endpoint
- [ ] `GET /api/members/{id}/balance/outstanding`: Calculate unpaid balance

**Settlements:**
- [ ] `POST /api/settlements`: Create new settlement (draft status)
- [ ] `GET /api/settlements`: List all settlements
- [ ] `GET /api/settlements/{id}`: Settlement details
- [ ] `POST /api/settlements/{id}/add-transactions`: Add transactions to settlement
- [ ] `POST /api/settlements/{id}/finalize`: Finalize settlement
- [ ] `GET /api/settlements/{id}/export-csv`: Generate CSV for bank processing
- [ ] `POST /api/settlements/{id}/cancel`: Cancel settlement (undo)

### Admin UI

- [ ] Settlement creation workflow (date range, description)
- [ ] Select transactions to settle (checkboxes, filter/search)
- [ ] Preview outstanding amounts per member
- [ ] Finalize settlement (confirmation dialog)
- [ ] Download CSV export
- [ ] View settlement history
- [ ] View which transactions were settled in which settlement
- [ ] Cancel settlement (if not yet exported)

### Terminal

- [ ] Create transactions with UUID, timestamp
- [ ] Queue transactions locally
- [ ] Sync: POST batch to `/api/sync/transactions`
- [ ] Receive accepted IDs, mark as synced
- [ ] No settlement awareness needed (admin-only feature)

### Testing

- [ ] Create transaction, verify immutability (INSERT succeeds, UPDATE fails)
- [ ] Create reversal, verify balance calculation
- [ ] Linked reversals show related_transaction_id
- [ ] Settlement creation marks transactions correctly
- [ ] Multiple settlements don't interfere
- [ ] CSV export contains correct member names, IBANs, amounts
- [ ] CSV roundtrip: export → validate → reimport logic
- [ ] Offline sync: transactions created offline sync correctly after reconnection
- [ ] Cancel settlement: transactions unmarked, can be re-settled

### Documentation

- [ ] Update CLAUDE.md: settlement workflow explanation
- [ ] Terminal API spec: clarify immutability, no settlement types
- [ ] Admin API spec (new): settlement endpoints and CSV format
- [ ] User guide: settlement/Abrechnung workflow for admins
- [ ] CSV format specification (column order, encoding, validation)

---

## Related Decisions

- [ADR-0001: Monetary Values as Integer Cents](./0001-monetary-values-as-integer-cents.md) - Transaction amounts stored as integer cents
- [ADR-0002: Product Internationalization](./0002-product-internationalization.md) - Products in transactions
- [ADR-0003: GZIP Compression](./0003-gzip-compression-http.md) - Transaction sync payloads compressed

---

## References

- **Accounting Standards**:
  - German tax law [§ 147 Aufbewahrungspflicht](https://www.gesetze-im-internet.de/ao_1977/__147.html) - 10-year retention of transaction records
  - [GAAP: General Accepted Accounting Principles](https://www.investopedia.com/terms/g/gaap.asp) - requires complete transaction history

- **GDPR**:
  - [Art. 16 Right to Rectification](https://gdpr-info.eu/art-16-gdpr/) - right to correct inaccurate data
  - [Art. 17 Right to Erasure](https://gdpr-info.eu/art-17-gdpr/) - right to delete data (with exceptions for accounting)

- **Software Patterns**:
  - [Event Sourcing](https://www.martinfowler.com/eaaDev/EventSourcing.html) - immutable event logs
  - [CQRS](https://www.martinfowler.com/bliki/CQRS.html) - Command Query Responsibility Segregation

---

## Approval

- **Decided by**: Architecture Team
- **Rationale**: Audit compliance, sync safety, GDPR compliance, flexible settlement, conflict-free design
- **Implementation start**: Phase 1 (Database schema)
- **Review date**: 2025-04-23 (after first settlement cycle)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - Data/Compliance Officer: _________________ Date: _______
  - QA Lead: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] Monitor transaction table growth
- [ ] Verify no UPDATE/DELETE operations (query logs)
- [ ] Track reversal frequency (correction rate)
- [ ] Measure settlement workflow time
- [ ] Test CSV export accuracy
- [ ] Verify audit trail completeness
- [ ] Compliance verification: can reconstruct full history
- [ ] User feedback: settlement workflow clarity
