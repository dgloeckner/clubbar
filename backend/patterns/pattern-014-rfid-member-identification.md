# Pattern 014: RFID Member Identification (Not Authentication)

**Status**: Active

**Related ADR**: ADR-0015 (Authentication and Authorization Strategy)

**Purpose**: Implement RFID member identification for transaction purposes. **CRITICAL**: This is identification only, NOT authentication. Members do not log in or gain system access.

---

## Context

The Club Bar system uses RFID cards to identify which member is making a purchase at the POS terminal. This is fundamentally different from authentication:

| Aspect | RFID Identification | Authentication |
|--------|---------------------|----------------|
| **Purpose** | Link purchase to member account | Grant access to system |
| **Trust Model** | Low-trust; anyone with card can use it | High-trust; secret credential |
| **Security** | Physical possession of card | Knowledge of secret (password/token) |
| **Card UID** | Visible on card; not secret | Would be secret if this were auth |
| **Fraud Risk** | Stolen/borrowed card | Compromised password |
| **Authorization** | No; all members can purchase | Yes; only authorized users |

**Club Bar is a trusted environment** (member organization) where convenience and accountability matter more than security against malicious actors. Similar to a tab at a member bar.

---

## Pattern Definition

### What RFID Identification Is

RFID identification solves: **"Which member is making this purchase?"**

The answer links the transaction to the correct member account for billing purposes.

### What RFID Identification Is NOT

RFID identification does NOT:
- Authenticate the member (prove identity)
- Grant access to system (terminals are unattended kiosks)
- Require secrets (card UID is visible on card)
- Prevent fraud (stolen card = anyone can spend that member's balance)
- Replace audit trails (transaction shows which card was scanned)

---

## Database Schema

```sql
-- Members table
CREATE TABLE members (
    id VARCHAR(36) PRIMARY KEY COMMENT 'UUID',

    -- RFID Identification
    card_uid VARCHAR(255) NOT NULL UNIQUE COMMENT 'RFID card UID (visible on card, not secret)',

    -- Member Info
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email VARCHAR(255),

    -- Account Status
    is_active BOOLEAN DEFAULT TRUE COMMENT 'False = member cannot make purchases',

    -- SEPA Debit (for settlements)
    iban VARCHAR(34),
    mandate_reference VARCHAR(35),
    is_sepa_valid BOOLEAN DEFAULT FALSE,

    -- Localization
    preferred_language ENUM('de', 'en', 'fr', 'it') DEFAULT 'de',

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_card_uid (card_uid),
    INDEX idx_is_active (is_active),
);
```

### Why Card UID in Database

The card UID is stored as plaintext in the database because:
1. **Not a secret**: UID printed on the card itself
2. **Not a password**: No "proof of knowledge" or authentication
3. **Needed for matching**: Terminal scans card UID and needs to find member record
4. **Publicly knowable**: Anyone with card can see UID

**Analogy**: Like storing a member's name (public, not secret).

---

## Terminal: Scanning RFID Card

```php
// Terminal Application Flow (Flutter/Dart — shown as pseudo-code)
// This runs on the unattended POS terminal, NOT in backend

// When member scans card:
1. RFID reader provides card UID (e.g., "012A45FF")
2. Terminal looks up member by card_uid in offline database
3. If found: "Welcome, John Smith"
4. If not found: "Card not recognized"
5. Member selects products
6. Terminal creates transaction record (offline)
7. Transaction stored locally with card_uid + member_id
8. Later when connected: Transaction synced to backend

// Key: Terminal uses card UID to identify member for transaction linking
// No authentication happens; card UID is just an identifier
```

### Backend: Member Lookup by Card UID (PDO Repository)

```php
// src/Modules/Members/Repositories/MembersRepository.php
namespace App\Modules\Members\Repositories;

use PDO;

/**
 * Members repository with card UID lookup (PDO, raw SQL).
 *
 * Returns associative arrays, not model objects.
 *
 * Implements Pattern 014: RFID Member Identification
 */
class MembersRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Find member by RFID card UID.
     *
     * Used by:
     * - Terminal offline lookup (via sync API)
     * - Transaction processing (to link card scan to member)
     * - Reconciliation (to audit member spending)
     *
     * **Security Note**: Card UID is NOT secret. This lookup is
     * identification only, not authentication.
     *
     * @param string $cardUid RFID card UID (visible on card)
     * @return array|null Member row as associative array
     */
    public function findByCardUid(string $cardUid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM members WHERE card_uid = ? AND is_active = 1 AND deleted_at IS NULL'
        );
        $stmt->execute([$cardUid]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find members by multiple card UIDs.
     *
     * Used for batch transaction processing and reconciliation reports.
     *
     * @param array $cardUids
     * @return array List of member rows
     */
    public function findByCardUids(array $cardUids): array
    {
        $placeholders = implode(',', array_fill(0, count($cardUids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM members WHERE card_uid IN ({$placeholders}) AND is_active = 1 AND deleted_at IS NULL"
        );
        $stmt->execute($cardUids);
        return $stmt->fetchAll();
    }

    /**
     * Check if card UID is already used by another member.
     *
     * Used for validation: prevent duplicate card UIDs, detect card reassignment.
     *
     * @param string $cardUid
     * @param string|null $excludeMemberId Member to exclude (for updates)
     * @return bool
     */
    public function cardUidExists(string $cardUid, ?string $excludeMemberId = null): bool
    {
        if ($excludeMemberId) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM members WHERE card_uid = ? AND id != ? AND deleted_at IS NULL'
            );
            $stmt->execute([$cardUid, $excludeMemberId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM members WHERE card_uid = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$cardUid]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }
}
```

---

## Transaction Processing with Card UID

```php
// src/Modules/Members/Services/MembersService.php
namespace App\Modules\Members\Services;

use App\Modules\Members\Repositories\MembersRepository;

/**
 * Members service including card identification for transactions.
 *
 * Implements Pattern 014: RFID Member Identification
 */
final class MembersService
{
    /**
     * Identify member by card UID for transaction processing.
     *
     * Called when terminal uploads transaction with card_uid.
     *
     * **This is identification, not authentication.**
     *
     * Flow:
     * 1. Look up member by card UID
     * 2. Check member is active (not deleted/blocked)
     * 3. Return member for transaction linking
     * 4. If not found: Transaction is invalid
     *
     * @param string $cardUid RFID card UID from terminal
     * @return array Identified member row as associative array
     * @throws MemberNotFoundException Card not recognized or member inactive
     */
    public function identifyMemberByCard(string $cardUid): array
    {
        $member = $this->repository->findByCardUid($cardUid);

        if (!$member) {
            throw new MemberNotFoundException(
                "Card not recognized: {$cardUid}",
                'card_not_recognized'
            );
        }

        return $member;
    }

    /**
     * Validate card UID format and uniqueness.
     *
     * **Validation note**: Not a security check (UID is public),
     * but a data quality check.
     *
     * @param string $cardUid RFID card UID
     * @param string|null $excludeMemberId Member to exclude (for updates)
     * @return void
     * @throws ValidationException Invalid format or duplicate
     */
    public function validateCardUid(string $cardUid, ?string $excludeMemberId = null): void
    {
        // Check format (typically 8-12 hex chars, varies by reader)
        if (!preg_match('/^[A-F0-9]{8,12}$/i', $cardUid)) {
            throw new ValidationException([
                'card_uid' => 'Invalid card UID format',
            ]);
        }

        // Check uniqueness (no two members can have same card)
        if ($this->repository->cardUidExists($cardUid, $excludeMemberId)) {
            throw new ValidationException([
                'card_uid' => 'Card UID already assigned to another member',
            ]);
        }
    }
}
```

---

## Transaction Upload with Card Identification

```php
// src/Modules/Transactions/Services/TransactionsService.php
namespace App\Modules\Transactions\Services;

/**
 * Process uploaded transactions from terminals.
 *
 * Transactions include card_uid (identification), not authentication.
 *
 * Implements Pattern 014: RFID Member Identification
 */
final class TransactionsService
{
    public function __construct(
        private readonly MembersService $membersService,
        private readonly TransactionsRepository $transactionsRepository,
    ) {}

    /**
     * Process batch of transactions from terminal.
     *
     * Each transaction contains:
     * - card_uid: RFID card scanned by member (identification)
     * - products: Items member selected
     * - amount: Total (calculated/verified)
     *
     * Flow:
     * 1. Validate transaction structure
     * 2. Identify member by card_uid
     * 3. Check member is active
     * 4. Check member has sufficient balance (if prepaid)
     * 5. Record transaction (links to member_id)
     * 6. Return result
     *
     * @param array $transactions Batch of transaction objects
     * @return TransactionBatchResultDto Results (successes + failures)
     */
    public function processBatch(array $transactions): TransactionBatchResultDto
    {
        $results = [];
        $errors = [];

        foreach ($transactions as $transaction) {
            try {
                // 1. Validate structure
                if (!isset($transaction['card_uid'], $transaction['amount'])) {
                    throw new ValidationException('Missing card_uid or amount');
                }

                $cardUid = $transaction['card_uid'];
                $amount = $transaction['amount'];

                // 2. Identify member by card (this is identification, not auth)
                $member = $this->membersService->identifyMemberByCard($cardUid);

                // 3. Create transaction record
                $created = $this->transactionsRepository->create([
                    'member_id' => $member->id,      // ← Links transaction to member
                    'card_uid' => $cardUid,          // ← Identifies member (not secret)
                    'amount' => $amount,
                    'status' => 'completed',
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);

                $results[] = [
                    'transaction_id' => $created->id,
                    'status' => 'success',
                ];

            } catch (MemberNotFoundException $e) {
                // Card not recognized
                $errors[] = [
                    'card_uid' => $cardUid ?? 'unknown',
                    'error' => 'card_not_recognized',
                    'message' => $e->getMessage(),
                ];

            } catch (ValidationException $e) {
                $errors[] = [
                    'card_uid' => $cardUid ?? 'unknown',
                    'error' => 'validation_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return new TransactionBatchResultDto(
            successful: count($results),
            failed: count($errors),
            results: $results,
            errors: $errors,
        );
    }
}
```

---

## Input Validation for Card UID (Custom Validator)

```php
// Validation is done in the controller using the custom Validator class.
// No FormRequest; the project uses App\Shared\Validation\Validator (PDO-backed).

// In MembersAdminController::store():
$body = $request->getParsedBody();
$validator = new Validator($this->pdo);
$valid = $validator->validate($body, [
    'first_name' => ['required', 'string', 'max:100'],
    'last_name'  => ['required', 'string', 'max:100'],
    'email'      => ['required', 'email', 'max:255'],
    'card_uid'   => ['required', 'string', 'regex:/^[A-F0-9]{8,12}$/i', 'unique:members,card_uid'],
    'preferred_language' => ['nullable', 'in:de,en,fr,it'],
]);

if (!$valid) {
    $response->getBody()->write(json_encode(['errors' => $validator->errors()]));
    return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
}

// Normalize card_uid to uppercase
$cardUid = strtoupper($body['card_uid']);
```

---

## Reconciliation: Card UID Audit Trail

```php
// src/Modules/Transactions/Repositories/TransactionsRepository.php
namespace App\Modules\Transactions\Repositories;

use PDO;

/**
 * Transactions repository for auditing and reconciliation (PDO).
 *
 * Uses card_uid to trace member spending for:
 * - Settlement billing
 * - Fraud investigation
 * - Audit reports
 *
 * Implements Pattern 014: RFID Member Identification
 */
class TransactionsRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Get member spending for settlement period.
     *
     * Aggregates all transactions linked by member_id.
     *
     * @param string $memberId
     * @param string $startDate Y-m-d format
     * @param string $endDate Y-m-d format
     * @return array List of transaction rows
     */
    public function findByMemberAndDateRange(
        string $memberId,
        string $startDate,
        string $endDate,
    ): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM transactions
             WHERE member_id = ? AND created_at BETWEEN ? AND ?
             ORDER BY created_at'
        );
        $stmt->execute([$memberId, $startDate, $endDate]);
        return $stmt->fetchAll();
    }

    /**
     * Audit trail: All transactions from member's card.
     *
     * Traces spending history linked by card_uid.
     * Used for:
     * - Member requests their transaction history
     * - Investigating disputed charges
     * - GDPR access requests
     *
     * @param string $cardUid
     * @return array Transactions in reverse chronological order
     */
    public function findByCardUid(string $cardUid): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, m.first_name, m.last_name
             FROM transactions t
             LEFT JOIN members m ON t.member_id = m.id
             WHERE t.card_uid = ?
             ORDER BY t.created_at DESC'
        );
        $stmt->execute([$cardUid]);
        return $stmt->fetchAll();
    }
}
```

---

## GDPR Implications

RFID card UID and transaction history are subject to GDPR. Admins can:

1. **Export member data** (Article 15)
   - Include card UID (identification mechanism)
   - Include transaction history (links to card via card_uid)
   - Include spending history

2. **Anonymize member** (Article 17)
   - Remove personal data (name, email, IBAN)
   - **Retain** card_uid in transaction history (for accounting)
   - **Retain** transaction history (immutable)
   - Mark member as deleted/anonymous

3. **Delete member** (Right to be Forgotten)
   - Hard delete member record (if no transactions)
   - Soft delete (anonymize) if transactions exist

See: Pattern 014: RFID Member Identification in Members Module implementation.

---

## Consequences

### Positive

- **Simple identification**: Card UID directly maps to member account
- **Low-touch**: No authentication flow needed at unattended terminal
- **Convenience**: Members just scan card; no login required
- **Accountability**: Audit trail links transactions to members
- **GDPR-compatible**: Card UID retained for accounting; personal data anonymizable

### Negative

- **No security**: Stolen/borrowed card allows spending on that member's account
- **No fraud detection**: System assumes card possession = authorization
- **No revocation**: Can't revoke card UID remotely (must block member account)
- **Account takeover**: If card lost, member must contact admin to block

### Mitigations

1. **Active/inactive status**: Admin can deactivate member (blocks card purchases)
2. **Transaction limits**: Enforce max transaction per purchase (policy)
3. **Daily limits**: Max spending per day per member (policy)
4. **Audit trail**: Log all transactions for investigation
5. **Settlement review**: Admin reviews transactions before billing
6. **Member education**: Advise members to report lost cards immediately

---

## Key Security Distinction

**This is NOT authentication because:**

1. Card UID is **visible on the card** (not secret)
2. No "proof of knowledge" (password, PIN, biometric)
3. No system access granted (members don't log in)
4. Physical possession = authorization (like a tab)
5. **Revocation** is via member account, not card

**Compare to authentication:**

- Terminal API: Bearer token (secret, revocable, device-level) - Pattern 012
- Admin Panel: Email + password (secret, session-based) - Pattern 013
- Members: Card UID (public, identification-only) - Pattern 014

---

## Integration with ADR-0015

This pattern implements:
- ✅ **Principle 1**: Separation of Identification and Authentication
  - RFID identifies members (this pattern)
  - RFID does NOT authenticate
  - Authentication is separate (Patterns 012-013)

- ✅ **Principle 4**: No Member Authentication
  - Members never log in
  - Members never gain system access
  - Members identified only by card UID for billing

Complements:
- **Pattern 012**: Terminal API Token Authentication (device auth, not member)
- **Pattern 013**: Admin Session Authentication (admin auth, not member)
- **Pattern 015**: Authorization & Access Control
- **ADR-0015**: Full authentication strategy
- **ADR-0014**: RFID Scanning Integration

---

## Testing

### Unit Tests

```php
// tests/Unit/Services/MembersServiceTest.php
public function test_identifyMemberByCard_returns_member_for_valid_card()
{
    // Insert test member via PDO
    $this->insertTestMember('member-1', '12345678');
    $identified = $this->membersService->identifyMemberByCard('12345678');
    $this->assertEquals('member-1', $identified['id']);
}

public function test_identifyMemberByCard_throws_for_inactive_member()
{
    $this->insertTestMember('member-1', '12345678', isActive: false);
    $this->expectException(MemberNotFoundException::class);
    $this->membersService->identifyMemberByCard('12345678');
}

public function test_identifyMemberByCard_throws_for_unknown_card()
{
    $this->expectException(MemberNotFoundException::class);
    $this->membersService->identifyMemberByCard('UNKNOWN');
}

public function test_validateCardUid_passes_for_valid_format()
{
    $this->membersService->validateCardUid('12345678');  // No exception
}

public function test_validateCardUid_fails_for_invalid_format()
{
    $this->expectException(ValidationException::class);
    $this->membersService->validateCardUid('INVALID!@#');
}

public function test_validateCardUid_fails_for_duplicate()
{
    $this->insertTestMember('member-1', '12345678');
    $this->expectException(ValidationException::class);
    $this->membersService->validateCardUid('12345678');
}
```

### Integration Tests (Playwright)

```typescript
// tests/api/transaction-member-identification.spec.ts
test('POST /api/sync/transactions with valid card_uid succeeds', async () => {
    const response = await fetch('http://localhost:8080/api/sync/transactions', {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + VALID_TERMINAL_TOKEN,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            transactions: [
                {
                    id: '12345678-1234-1234-1234-123456789012',
                    card_uid: 'VALIDCARD1',
                    amount: 1000,
                    timestamp: Date.now()
                }
            ]
        })
    });
    expect(response.status).toBe(200);
    const result = await response.json();
    expect(result.successful).toBe(1);
});

test('POST /api/sync/transactions with unknown card_uid fails', async () => {
    const response = await fetch('http://localhost:8080/api/sync/transactions', {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + VALID_TERMINAL_TOKEN,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            transactions: [
                {
                    id: '12345678-1234-1234-1234-123456789012',
                    card_uid: 'UNKNOWNCARD',
                    amount: 1000,
                    timestamp: Date.now()
                }
            ]
        })
    });
    expect(response.status).toBe(200);
    const result = await response.json();
    expect(result.failed).toBe(1);
    expect(result.errors[0].error).toBe('card_not_recognized');
});
```

---

## See Also

- **ADR-0015**: Authentication and Authorization Strategy
- **ADR-0014**: RFID Scanning Integration
- **ADR-0013**: Audit Logging
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 013**: Admin Session Authentication
- **Pattern 015**: Authorization & Access Control
