<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transactions\Repositories;

use App\Modules\Transactions\Exceptions\TransactionAlreadyStornoedException;
use App\Modules\Transactions\Exceptions\TransactionNotStorableException;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use Tests\Feature\DatabaseTestCase;

class TransactionsRepositoryTest extends DatabaseTestCase
{
    private TransactionsRepository $transactionsRepository;
    private array $testMemberIds = [];
    private array $testProductIds = [];
    private array $testCategoryIds = [];
    private array $testTransactionIds = [];
    private array $testSettlementIds = [];
    private array $testAdminUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionsRepository = new TransactionsRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up in reverse FK order
        $this->cleanupTestData('settlement_items', []);
        $this->cleanupTestData('settlements', $this->testSettlementIds);
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        $this->cleanupTestData('members', $this->testMemberIds);
        $this->cleanupTestData('admin_users', $this->testAdminUserIds);
        parent::tearDown();
    }

    public function test_findByMemberId_returns_settlement_data_for_settled_transactions(): void
    {
        // Arrange: Create test data
        $memberId = $this->createTestMember('SettlementTest', 'User');
        $adminId = $this->createTestAdminUser('test-admin@example.com');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Beer', 'beer', 350);

        // Create a transaction
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, 350, 'purchase', '2026-02-01 10:00:00']);

        // Create a settlement and link the transaction
        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, '2026-02-01', '2026-02-05', 350, 1, $adminId]);

        // Link transaction to settlement
        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $transactionId, $transactionId, $memberId, 350]);

        // Act: Fetch transactions for member
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 50, 0);

        // Assert: Transaction includes settlement data
        $this->assertCount(1, $transactions, 'Should return exactly one transaction');

        $transaction = $transactions[0];
        $this->assertEquals($transactionId, $transaction['id']);
        $this->assertEquals($settlementId, $transaction['settlement_id'], 'Should include settlement_id');
        $this->assertEquals('2026-02-01', $transaction['settlement_date'], 'Should include settlement_date');
    }

    public function test_findByMemberId_returns_null_settlement_for_unsettled_transactions(): void
    {
        // Arrange: Create test data
        $memberId = $this->createTestMember('UnsettledTest', 'User');
        $categoryId = $this->createTestCategory('Food');
        $productId = $this->createTestProduct($categoryId, 'Pizza', 'pizza', 850);

        // Create an unsettled transaction
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, 850, 'purchase', '2026-02-07 14:30:00']);

        // Act: Fetch transactions for member
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 50, 0);

        // Assert: Transaction has null settlement fields
        $this->assertCount(1, $transactions);

        $transaction = $transactions[0];
        $this->assertEquals($transactionId, $transaction['id']);
        $this->assertNull($transaction['settlement_id'], 'settlement_id should be null for unsettled transactions');
        $this->assertNull($transaction['settlement_date'], 'settlement_date should be null for unsettled transactions');
    }

    public function test_findByMemberId_returns_product_icon_for_purchases(): void
    {
        // Arrange: Create test data
        $memberId = $this->createTestMember('IconTest', 'User');
        $categoryId = $this->createTestCategory('Beverages');
        $productId = $this->createTestProduct($categoryId, 'Coffee', 'coffee', 250);

        // Create a purchase transaction
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, 250, 'purchase', '2026-02-07 09:15:00']);

        // Act: Fetch transactions for member
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 50, 0);

        // Assert: Transaction includes product icon
        $this->assertCount(1, $transactions);

        $transaction = $transactions[0];
        $this->assertEquals($transactionId, $transaction['id']);
        $this->assertEquals('coffee', $transaction['product_icon'], 'Should include product icon_name');
    }

    public function test_findByMemberId_returns_null_product_icon_for_corrections(): void
    {
        // Arrange: Create test data
        $memberId = $this->createTestMember('CorrectionTest', 'User');

        // Create a storno transaction (no product), referencing an unrelated purchase
        // as required by the chk_transactions_storno_is_linked constraint.
        $anchorPurchaseId = $this->createAnchorPurchaseTransaction();

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, notes, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, null, -350, 'storno', 'Refund for incorrect charge', $anchorPurchaseId, '2026-02-06 16:45:00']);

        // Act: Fetch transactions for member
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 50, 0);

        // Assert: Transaction has null product_icon
        $this->assertCount(1, $transactions);

        $transaction = $transactions[0];
        $this->assertEquals($transactionId, $transaction['id']);
        $this->assertNull($transaction['product_icon'], 'product_icon should be null for corrections');
        $this->assertEquals('Refund for incorrect charge', $transaction['notes']);
    }

    public function test_findByMemberId_ignores_cancelled_settlements(): void
    {
        // Arrange: Create test data
        $memberId = $this->createTestMember('CancelledTest', 'User');
        $adminId = $this->createTestAdminUser('cancelled-admin@example.com');
        $categoryId = $this->createTestCategory('Snacks');
        $productId = $this->createTestProduct($categoryId, 'Chips', 'chips', 200);

        // Create a transaction
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, 200, 'purchase', '2026-01-15 12:00:00']);

        // Create a CANCELLED settlement
        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id, is_cancelled) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, '2026-01-15', '2026-01-20', 200, 1, $adminId, 1]);

        // Link transaction to cancelled settlement
        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $transactionId, $memberId, 200]);

        // Act: Fetch transactions for member
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 50, 0);

        // Assert: Cancelled settlement should be ignored (settlement_id should be null)
        $this->assertCount(1, $transactions);

        $transaction = $transactions[0];
        $this->assertEquals($transactionId, $transaction['id']);
        $this->assertNull($transaction['settlement_id'], 'settlement_id should be null when settlement is cancelled');
        $this->assertNull($transaction['settlement_date'], 'settlement_date should be null when settlement is cancelled');
    }

    /**
     * A replayed sync batch must be absorbed, not doubled: the same client
     * UUID arriving twice is the terminal retrying, and ADR-0004 makes that
     * safe by construction. The second call reports "already stored" (null)
     * and leaves the single row untouched.
     */
    public function test_insertTransaction_returns_null_when_the_id_is_already_stored(): void
    {
        $memberId = $this->createTestMember('IdempotentInsert', 'User');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Beer', 'beer', 350);

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $row = [
            'id' => $transactionId,
            'member_id' => $memberId,
            'product_id' => $productId,
            'amount_cents' => 350,
            'created_at' => '2026-08-07 10:00:00',
        ];

        $first = $this->transactionsRepository->insertTransaction($row);
        $second = $this->transactionsRepository->insertTransaction($row);

        $this->assertIsArray($first, 'The first insert stores the row and returns it');
        $this->assertNull($second, 'A replay of the same id is absorbed, not stored again');

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM transactions WHERE id = ?');
        $stmt->execute([$transactionId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Exactly one row exists after the replay');
    }

    /**
     * Issue #82 — a row the database refuses is a *lost sale*, not a duplicate.
     * `INSERT IGNORE` downgraded every ignorable error to a warning, so a
     * dangling `member_id` looked identical to a replay: zero rows affected.
     * The caller was then told the sale had been accepted and the terminal
     * dropped it from its offline queue. Only errno 1062 may be swallowed;
     * everything else must reach the caller.
     */
    public function test_insertTransaction_raises_a_row_the_database_refuses(): void
    {
        $unknownMemberId = $this->generateUuid(); // No such member — foreign key violation

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        $this->expectException(TransactionNotStorableException::class);

        $this->transactionsRepository->insertTransaction([
            'id' => $transactionId,
            'member_id' => $unknownMemberId,
            'product_id' => null,
            'amount_cents' => 350,
            'created_at' => '2026-08-07 10:00:00',
        ]);
    }

    /**
     * Terminals send the sale time as ISO 8601 with a `Z` — a value the
     * DATETIME column cannot take. `INSERT IGNORE` coerced it and raised a
     * truncation warning nobody read; under a plain INSERT it would refuse a
     * perfectly ordinary sale, so the conversion is now explicit. The stored
     * instant is the one the terminal claimed (ruling #144), offset applied.
     */
    public function test_insertTransaction_stores_an_iso_8601_sale_time_as_the_utc_instant(): void
    {
        $memberId = $this->createTestMember('IsoOccurredAt', 'User');

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        $this->transactionsRepository->insertTransaction([
            'id' => $transactionId,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => 350,
            'created_at' => '2026-08-07T16:23:15.780+02:00',
        ]);

        $stmt = $this->db->prepare('SELECT occurred_at FROM transactions WHERE id = ?');
        $stmt->execute([$transactionId]);

        $this->assertSame('2026-08-07 14:23:15', $stmt->fetchColumn());
    }

    /**
     * `STRICT_TRANS_TABLES` reports a value outside `transaction_type`'s ENUM
     * as a *truncation* (SQLSTATE 01000), not an integrity violation — the one
     * refusal class that does not look like one. It is also the exact case
     * #82 names as reachable from a terminal today, so it gets its own test.
     */
    public function test_insertTransaction_raises_a_transaction_type_outside_the_enum(): void
    {
        $memberId = $this->createTestMember('BadEnumInsert', 'User');

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        $this->expectException(TransactionNotStorableException::class);

        $this->transactionsRepository->insertTransaction([
            'id' => $transactionId,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => 350,
            'transaction_type' => 'not_a_real_type',
            'created_at' => '2026-08-07 10:00:00',
        ]);
    }

    /**
     * findStornoFor() is the clean "already stornoed" lookup used both to
     * answer that question and to show the linkage in the journal.
     */
    public function test_findStornoFor_returns_null_when_transaction_has_no_storno(): void
    {
        $purchaseId = $this->createAnchorPurchaseTransaction();

        $result = $this->transactionsRepository->findStornoFor($purchaseId);

        $this->assertNull($result);
    }

    public function test_findStornoFor_returns_the_storno_row(): void
    {
        $memberId = $this->createTestMember('StornoLookup', 'User');

        $purchaseId = $this->generateUuid();
        $this->testTransactionIds[] = $purchaseId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $memberId, null, 350, 'purchase', '2026-03-01 10:00:00']);

        $stornoId = $this->generateUuid();
        $this->testTransactionIds[] = $stornoId;
        $stornoStmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stornoStmt->execute([$stornoId, $memberId, null, -350, 'storno', $purchaseId, '2026-03-01 11:00:00']);

        $result = $this->transactionsRepository->findStornoFor($purchaseId);

        $this->assertNotNull($result);
        $this->assertSame($stornoId, $result['id']);
        $this->assertSame('storno', $result['transaction_type']);
        $this->assertSame($purchaseId, $result['related_transaction_id']);
    }

    public function test_findStornoFor_returns_null_for_unknown_transaction_id(): void
    {
        $result = $this->transactionsRepository->findStornoFor($this->generateUuid());

        $this->assertNull($result);
    }

    public function test_insertStorno_returns_the_stored_row(): void
    {
        $memberId = $this->createTestMember('InsertStornoOk', 'User');

        $purchaseId = $this->generateUuid();
        $this->testTransactionIds[] = $purchaseId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $memberId, null, 350, 'purchase', '2026-03-02 10:00:00']);

        $stornoId = $this->generateUuid();
        $this->testTransactionIds[] = $stornoId;

        $result = $this->transactionsRepository->insertStorno([
            'id' => $stornoId,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => -350,
            'transaction_type' => 'storno',
            'related_transaction_id' => $purchaseId,
            'created_at' => '2026-03-02 11:00:00',
        ]);

        $this->assertIsArray($result, 'insertStorno should return the stored row');
        $this->assertSame($stornoId, $result['id']);
        $this->assertSame('storno', $result['transaction_type']);
        $this->assertSame($purchaseId, $result['related_transaction_id']);
    }

    /**
     * The unique index on `related_transaction_id` is the true arbiter of
     * "stornoable at most once" (#169) — `insertTransaction()` absorbs the
     * duplicate key as a batch replay (returns null), and `insertStorno()`
     * reads that null as "already stornoed" rather than a silent no-op.
     */
    public function test_insertStorno_throws_when_a_storno_already_exists(): void
    {
        $memberId = $this->createTestMember('DoubleStorno', 'User');

        $purchaseId = $this->generateUuid();
        $this->testTransactionIds[] = $purchaseId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $memberId, null, 350, 'purchase', '2026-03-03 10:00:00']);

        $firstStornoId = $this->generateUuid();
        $this->testTransactionIds[] = $firstStornoId;
        $this->transactionsRepository->insertStorno([
            'id' => $firstStornoId,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => -350,
            'transaction_type' => 'storno',
            'related_transaction_id' => $purchaseId,
            'created_at' => '2026-03-03 11:00:00',
        ]);

        $secondStornoId = $this->generateUuid();
        $this->testTransactionIds[] = $secondStornoId;

        $this->expectException(TransactionAlreadyStornoedException::class);

        $this->transactionsRepository->insertStorno([
            'id' => $secondStornoId,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => -350,
            'transaction_type' => 'storno',
            'related_transaction_id' => $purchaseId,
            'created_at' => '2026-03-03 12:00:00',
        ]);
    }

    /**
     * A storno without a `related_transaction_id` violates
     * `chk_transactions_storno_is_linked` — a row the database refuses for a
     * reason other than "already stornoed", which `insertStorno()` must let
     * propagate as `TransactionNotStorableException` rather than mask as a
     * duplicate.
     */
    public function test_insertStorno_rethrows_a_row_the_database_refuses_for_another_reason(): void
    {
        $memberId = $this->createTestMember('UnlinkedStorno', 'User');

        $stornoId = $this->generateUuid();
        $this->testTransactionIds[] = $stornoId;

        $this->expectException(TransactionNotStorableException::class);

        $this->transactionsRepository->insertStorno([
            'id' => $stornoId,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => -350,
            'transaction_type' => 'storno',
            'related_transaction_id' => null,
            'created_at' => '2026-03-04 10:00:00',
        ]);
    }

    // Helper methods to create test data

    private function createTestMember(string $firstName, string $lastName): string
    {
        $memberId = $this->generateUuid();
        $this->testMemberIds[] = $memberId;

        $stmt = $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $firstName, $lastName, strtolower($firstName) . '@example.com', 'de', 1]);

        return $memberId;
    }

    private function createTestAdminUser(string $email): string
    {
        $adminId = $this->generateUuid();
        $this->testAdminUserIds[] = $adminId;

        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$adminId, $email, password_hash('test123', PASSWORD_BCRYPT), 1]);

        return $adminId;
    }

    private function createTestCategory(string $name): string
    {
        $categoryId = $this->generateUuid();
        $this->testCategoryIds[] = $categoryId;

        $names = json_encode(['de' => $name, 'en' => $name]);

        $stmt = $this->db->prepare(
            'INSERT INTO categories (id, names, is_active) VALUES (?, ?, ?)'
        );
        $stmt->execute([$categoryId, $names, 1]);

        return $categoryId;
    }

    /**
     * Creates a throwaway member and purchase transaction purely to satisfy
     * chk_transactions_storno_is_linked / uq_transactions_related_transaction
     * for a storno fixture that has no other purchase in scope to point at.
     */
    private function createAnchorPurchaseTransaction(): string
    {
        $anchorMemberId = $this->createTestMember('StornoAnchor', $this->generateUuid());

        $anchorTransactionId = $this->generateUuid();
        $this->testTransactionIds[] = $anchorTransactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$anchorTransactionId, $anchorMemberId, null, 100, 'purchase', date('Y-m-d H:i:s')]);

        return $anchorTransactionId;
    }

    private function createTestProduct(string $categoryId, string $name, string $iconName, int $priceCents): string
    {
        $productId = $this->generateUuid();
        $this->testProductIds[] = $productId;

        $names = json_encode(['de' => $name, 'en' => $name]);

        $stmt = $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, icon_name, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$productId, $categoryId, $names, $priceCents, $iconName, 1]);

        return $productId;
    }

    public function test_insertTransaction_stores_dispenser_metadata(): void
    {
        // Arrange: Create test data
        $memberId = $this->createTestMember('DispenserTest', 'User');
        $categoryId = $this->createTestCategory('Tokens');
        $productId = $this->createTestProduct($categoryId, 'Token', 'token', 100);

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        $transactionData = [
            'id' => $transactionId,
            'member_id' => $memberId,
            'product_id' => $productId,
            'amount_cents' => 300,
            'transaction_type' => 'purchase',
            'dispenser_tx_id' => 'a3f8c012',
            'dispenser_requested' => 3,
            'dispenser_actual' => 2,
            'created_at' => '2026-02-14 10:00:00',
        ];

        // Act: Insert transaction with dispenser metadata
        $result = $this->transactionsRepository->insertTransaction($transactionData);

        // Assert: Transaction is stored with dispenser fields
        $this->assertNotNull($result, 'insertTransaction should return transaction data');
        $this->assertEquals($transactionId, $result['id']);
        $this->assertEquals('a3f8c012', $result['dispenser_tx_id'], 'Should store dispenser_tx_id');
        $this->assertEquals(3, (int) $result['dispenser_requested'], 'Should store dispenser_requested');
        $this->assertEquals(2, (int) $result['dispenser_actual'], 'Should store dispenser_actual');
    }

    public function test_insertTransaction_accepts_null_dispenser_metadata(): void
    {
        // Arrange: Create test data
        $memberId = $this->createTestMember('NoDispenserTest', 'User');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Beer', 'beer', 350);

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        $transactionData = [
            'id' => $transactionId,
            'member_id' => $memberId,
            'product_id' => $productId,
            'amount_cents' => 350,
            'transaction_type' => 'purchase',
            'created_at' => '2026-02-14 11:00:00',
        ];

        // Act: Insert transaction without dispenser metadata
        $result = $this->transactionsRepository->insertTransaction($transactionData);

        // Assert: Transaction is stored with null dispenser fields
        $this->assertNotNull($result, 'insertTransaction should return transaction data');
        $this->assertEquals($transactionId, $result['id']);
        $this->assertNull($result['dispenser_tx_id'], 'dispenser_tx_id should be null when not provided');
        $this->assertNull($result['dispenser_requested'], 'dispenser_requested should be null when not provided');
        $this->assertNull($result['dispenser_actual'], 'dispenser_actual should be null when not provided');
    }

    // ---------------------------------------------------------------
    // findByMemberId: type + since filters
    // ---------------------------------------------------------------

    public function test_findByMemberId_filters_by_type(): void
    {
        $memberId = $this->createTestMember('TypeFilter', 'User');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Cola', 'cola', 250);

        $purchaseId = $this->generateUuid();
        $this->testTransactionIds[] = $purchaseId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $memberId, $productId, 250, 'purchase', '2026-02-10 10:00:00']);

        $correctionId = $this->generateUuid();
        $this->testTransactionIds[] = $correctionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, notes, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$correctionId, $memberId, null, -100, 'storno', 'fix', $purchaseId, '2026-02-10 11:00:00']);

        $result = $this->transactionsRepository->findByMemberId($memberId, 50, 0, 'storno');

        $this->assertCount(1, $result);
        $this->assertEquals($correctionId, $result[0]['id']);
        $this->assertEquals('storno', $result[0]['transaction_type']);
    }

    public function test_findByMemberId_filters_by_since(): void
    {
        $memberId = $this->createTestMember('SinceFilter', 'User');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Water', 'water', 150);

        $oldId = $this->generateUuid();
        $this->testTransactionIds[] = $oldId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        // MySQL DATETIME has second precision; separate rows by >= 1 second using explicit timestamps.
        $stmt->execute([$oldId, $memberId, $productId, 150, 'purchase', '2026-02-10 08:00:00']);

        $newId = $this->generateUuid();
        $this->testTransactionIds[] = $newId;
        $stmt->execute([$newId, $memberId, $productId, 150, 'purchase', '2026-02-10 08:00:05']);

        $result = $this->transactionsRepository->findByMemberId($memberId, 50, 0, null, '2026-02-10 08:00:02');

        $this->assertCount(1, $result);
        $this->assertEquals($newId, $result[0]['id']);
    }

    public function test_findByMemberId_returns_empty_for_no_match(): void
    {
        $memberId = $this->createTestMember('NoMatch', 'User');

        $result = $this->transactionsRepository->findByMemberId($memberId, 50, 0);

        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // listPaginated
    // ---------------------------------------------------------------

    public function test_listPaginated_filters_by_member_id_and_includes_settlement_date(): void
    {
        $memberId = $this->createTestMember('ListMember', 'Alpha');
        $adminId = $this->createTestAdminUser('list-admin@example.com');
        $categoryId = $this->createTestCategory('Snacks');
        $productId = $this->createTestProduct($categoryId, 'Chips', 'chips', 200);

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, 200, 'purchase', '2026-02-11 09:00:00']);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, '2026-02-11', '2026-02-15', 200, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $transactionId, $transactionId, $memberId, 200]);

        $result = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId]);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertEquals($transactionId, $item['id']);
        $this->assertEquals('2026-02-11', $item['settlement_date'], 'Correlated subquery should attach settlement_date');
        $this->assertEquals('purchase', $item['type'], 'type key should mirror transaction_type');
        $this->assertNull($item['description']);
        $this->assertEquals('ListMember Alpha', $item['member_name']);
    }

    public function test_listPaginated_settlement_date_null_when_settlement_cancelled(): void
    {
        $memberId = $this->createTestMember('CancelList', 'Beta');
        $adminId = $this->createTestAdminUser('cancel-list-admin@example.com');
        $categoryId = $this->createTestCategory('Snacks');
        $productId = $this->createTestProduct($categoryId, 'Nuts', 'nuts', 300);

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, 300, 'purchase', '2026-02-12 09:00:00']);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id, is_cancelled) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, '2026-02-12', '2026-02-16', 300, 1, $adminId, 1]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $transactionId, $memberId, 300]);

        $result = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId]);

        $this->assertEquals(1, $result['total']);
        $this->assertNull($result['items'][0]['settlement_date'], 'Cancelled settlements must not be attached');
    }

    public function test_listPaginated_filters_by_type(): void
    {
        $memberId = $this->createTestMember('TypeList', 'Gamma');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Juice', 'juice', 220);

        $purchaseId = $this->generateUuid();
        $this->testTransactionIds[] = $purchaseId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $memberId, $productId, 220, 'purchase', '2026-02-13 10:00:00']);

        $correctionId = $this->generateUuid();
        $this->testTransactionIds[] = $correctionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$correctionId, $memberId, null, -50, 'storno', $purchaseId, '2026-02-13 11:00:00']);

        $result = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId, 'type' => 'storno']);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals($correctionId, $result['items'][0]['id']);
    }

    public function test_listPaginated_type_all_returns_every_type(): void
    {
        $memberId = $this->createTestMember('TypeAll', 'Delta');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Tea', 'tea', 180);

        $purchaseId = $this->generateUuid();
        $this->testTransactionIds[] = $purchaseId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $memberId, $productId, 180, 'purchase', '2026-02-14 10:00:00']);

        $correctionId = $this->generateUuid();
        $this->testTransactionIds[] = $correctionId;
        $correctionStmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $correctionStmt->execute([$correctionId, $memberId, null, -20, 'storno', $purchaseId, '2026-02-14 11:00:00']);

        $result = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId, 'type' => 'all']);

        $this->assertEquals(2, $result['total']);
    }

    public function test_listPaginated_filters_by_date_range(): void
    {
        $memberId = $this->createTestMember('DateRange', 'Epsilon');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Soda', 'soda', 190);

        $insideId = $this->generateUuid();
        $this->testTransactionIds[] = $insideId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$insideId, $memberId, $productId, 190, 'purchase', '2026-03-05 12:00:00']);

        $beforeId = $this->generateUuid();
        $this->testTransactionIds[] = $beforeId;
        $stmt->execute([$beforeId, $memberId, $productId, 190, 'purchase', '2026-03-01 12:00:00']);

        $afterId = $this->generateUuid();
        $this->testTransactionIds[] = $afterId;
        $stmt->execute([$afterId, $memberId, $productId, 190, 'purchase', '2026-03-10 12:00:00']);

        $result = $this->transactionsRepository->listPaginated(50, 0, [
            'member_id' => $memberId,
            'date_from' => '2026-03-03',
            'date_to' => '2026-03-07',
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals($insideId, $result['items'][0]['id']);
    }

    public function test_listPaginated_search_matches_member_name_notes_and_product(): void
    {
        $memberId = $this->createTestMember('Zamboni', 'Uniquesurname');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Uniqueproductname', 'drink', 210);

        $byNameId = $this->generateUuid();
        $this->testTransactionIds[] = $byNameId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$byNameId, $memberId, null, 210, 'purchase', '2026-03-06 10:00:00']);

        $byProductId = $this->generateUuid();
        $this->testTransactionIds[] = $byProductId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$byProductId, $memberId, $productId, 210, 'purchase', '2026-03-06 10:05:00']);

        $byNotesId = $this->generateUuid();
        $this->testTransactionIds[] = $byNotesId;
        $otherMemberId = $this->createTestMember('Other', 'Person');
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, notes, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$byNotesId, $otherMemberId, null, -10, 'storno', 'Uniquenotestoken adjustment', $byProductId, '2026-03-06 10:10:00']);

        $resultByName = $this->transactionsRepository->listPaginated(50, 0, ['search' => 'Uniquesurname']);
        $foundIds = array_column($resultByName['items'], 'id');
        $this->assertContains($byNameId, $foundIds);
        $this->assertContains($byProductId, $foundIds, 'Member-based search should return all of that member transactions');

        $resultByProduct = $this->transactionsRepository->listPaginated(50, 0, ['search' => 'uniqueproductname']);
        $this->assertContains($byProductId, array_column($resultByProduct['items'], 'id'), 'Case-insensitive product search should match');

        $resultByNotes = $this->transactionsRepository->listPaginated(50, 0, ['search' => 'Uniquenotestoken']);
        $foundNoteIds = array_column($resultByNotes['items'], 'id');
        $this->assertContains($byNotesId, $foundNoteIds);
        $this->assertNotContains($byNameId, $foundNoteIds);
    }

    public function test_listPaginated_settlement_status_filters(): void
    {
        $memberId = $this->createTestMember('SettleStatus', 'Zeta');
        $adminId = $this->createTestAdminUser('settle-status-admin@example.com');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Rum', 'rum', 500);

        $settledTxId = $this->generateUuid();
        $this->testTransactionIds[] = $settledTxId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settledTxId, $memberId, $productId, 500, 'purchase', '2026-03-07 10:00:00']);

        $unsettledTxId = $this->generateUuid();
        $this->testTransactionIds[] = $unsettledTxId;
        $stmt->execute([$unsettledTxId, $memberId, $productId, 500, 'purchase', '2026-03-07 11:00:00']);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, '2026-03-07', '2026-03-10', 500, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $settledTxId, $settledTxId, $memberId, 500]);

        $unsettledResult = $this->transactionsRepository->listPaginated(50, 0, [
            'member_id' => $memberId,
            'settlement_status' => 'unsettled',
        ]);
        $this->assertEquals(1, $unsettledResult['total']);
        $this->assertEquals($unsettledTxId, $unsettledResult['items'][0]['id']);

        $settledResult = $this->transactionsRepository->listPaginated(50, 0, [
            'member_id' => $memberId,
            'settlement_status' => 'settled',
        ]);
        $this->assertEquals(1, $settledResult['total']);
        $this->assertEquals($settledTxId, $settledResult['items'][0]['id']);
    }

    public function test_listPaginated_reports_a_resettled_transaction_once_under_its_live_settlement(): void
    {
        // A cancelled settlement keeps its items (#142 §3), so a transaction
        // that was settled, released and settled again now carries *two*
        // settlement_items rows. "Settled" must mean the live claim: one
        // journal row, showing the live settlement's date, and the transaction
        // must not also appear under the unsettled filter.
        $memberId = $this->createTestMember('Resettled', 'Omega');
        $adminId = $this->createTestAdminUser('resettled-admin@example.com');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Gin', 'gin', 700);

        $txId = $this->generateUuid();
        $this->testTransactionIds[] = $txId;
        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$txId, $memberId, $productId, 700, 'purchase', '2026-03-07 10:00:00']);

        $insertSettlement = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id, is_cancelled) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertItem = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );

        // The cancelled run: row retained, claim released.
        $cancelledId = $this->generateUuid();
        $this->testSettlementIds[] = $cancelledId;
        $insertSettlement->execute([$cancelledId, '2026-03-07', '2026-03-10', 700, 1, $adminId, 1]);
        $insertItem->execute([$cancelledId, $txId, null, $memberId, 700]);

        // The live run that collected it in the end.
        $liveId = $this->generateUuid();
        $this->testSettlementIds[] = $liveId;
        $insertSettlement->execute([$liveId, '2026-03-14', '2026-03-17', 700, 1, $adminId, 0]);
        $insertItem->execute([$liveId, $txId, $txId, $memberId, 700]);

        $all = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId]);
        $this->assertEquals(1, $all['total'], 'A transaction with a released and a live claim is still one journal row');
        $this->assertCount(1, $all['items']);
        $this->assertEquals('2026-03-14', $all['items'][0]['settlement_date'], 'The journal shows the live settlement');

        $settled = $this->transactionsRepository->listPaginated(50, 0, [
            'member_id' => $memberId,
            'settlement_status' => 'settled',
        ]);
        $this->assertEquals(1, $settled['total']);

        $unsettled = $this->transactionsRepository->listPaginated(50, 0, [
            'member_id' => $memberId,
            'settlement_status' => 'unsettled',
        ]);
        $this->assertEquals(0, $unsettled['total'], 'A live claim means settled');
    }

    public function test_findUnsettledByIds_ignores_a_released_claim(): void
    {
        // The #81 double-debit itself, at the level the settlement run sees it:
        // the released transaction must come back as collectable again, and the
        // one still claimed by a live settlement must not.
        $memberId = $this->createTestMember('Released', 'Sigma');
        $adminId = $this->createTestAdminUser('released-admin@example.com');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Ale', 'ale', 300);

        $insertTx = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $releasedTxId = $this->generateUuid();
        $this->testTransactionIds[] = $releasedTxId;
        $insertTx->execute([$releasedTxId, $memberId, $productId, 300, 'purchase', '2026-03-07 10:00:00']);

        $claimedTxId = $this->generateUuid();
        $this->testTransactionIds[] = $claimedTxId;
        $insertTx->execute([$claimedTxId, $memberId, $productId, 300, 'purchase', '2026-03-07 11:00:00']);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$settlementId, '2026-03-07', '2026-03-10', 600, 1, $adminId]);

        $insertItem = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $insertItem->execute([$settlementId, $releasedTxId, null, $memberId, 300]);
        $insertItem->execute([$settlementId, $claimedTxId, $claimedTxId, $memberId, 300]);

        $unsettled = array_column(
            $this->transactionsRepository->findUnsettledByIds([$releasedTxId, $claimedTxId]),
            'id'
        );

        $this->assertContains($releasedTxId, $unsettled);
        $this->assertNotContains($claimedTxId, $unsettled);
    }

    public function test_listPaginated_sort_by_member_name_maps_to_last_name(): void
    {
        $memberA = $this->createTestMember('SortA', 'Aaronson');
        $memberB = $this->createTestMember('SortB', 'Zimmerman');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Milk', 'milk', 100);

        $txA = $this->generateUuid();
        $this->testTransactionIds[] = $txA;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$txA, $memberA, $productId, 100, 'purchase', '2026-03-08 09:00:00']);

        $txB = $this->generateUuid();
        $this->testTransactionIds[] = $txB;
        $stmt->execute([$txB, $memberB, $productId, 100, 'purchase', '2026-03-08 09:01:00']);

        $result = $this->transactionsRepository->listPaginated(50, 0, [
            'search' => 'Sort',
        ], 'member', 'asc');

        $ids = array_column($result['items'], 'id');
        $posA = array_search($txA, $ids, true);
        $posB = array_search($txB, $ids, true);
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA, 'sort=member should order by last_name ascending (Aaronson before Zimmerman)');
    }

    public function test_listPaginated_sort_by_amount_descending(): void
    {
        $memberId = $this->createTestMember('AmountSort', 'User');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Wine', 'wine', 700);

        $lowId = $this->generateUuid();
        $this->testTransactionIds[] = $lowId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$lowId, $memberId, $productId, 100, 'purchase', '2026-03-09 09:00:00']);

        $highId = $this->generateUuid();
        $this->testTransactionIds[] = $highId;
        $stmt->execute([$highId, $memberId, $productId, 700, 'purchase', '2026-03-09 09:01:00']);

        $result = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId], 'amount', 'desc');

        $this->assertEquals($highId, $result['items'][0]['id']);
        $this->assertEquals($lowId, $result['items'][1]['id']);
    }

    public function test_listPaginated_unknown_sort_key_falls_back_to_created_at(): void
    {
        $memberId = $this->createTestMember('FallbackSort', 'User');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Cider', 'cider', 260);

        $firstId = $this->generateUuid();
        $this->testTransactionIds[] = $firstId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$firstId, $memberId, $productId, 260, 'purchase', '2026-03-11 09:00:00']);

        $secondId = $this->generateUuid();
        $this->testTransactionIds[] = $secondId;
        $stmt->execute([$secondId, $memberId, $productId, 260, 'purchase', '2026-03-11 09:05:00']);

        $result = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId], 'not_a_real_sort_key', 'asc');

        $this->assertEquals($firstId, $result['items'][0]['id']);
        $this->assertEquals($secondId, $result['items'][1]['id']);
    }

    public function test_listPaginated_paginates_with_limit_and_offset(): void
    {
        $memberId = $this->createTestMember('PageMember', 'User');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Espresso', 'coffee', 150);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $id = $this->generateUuid();
            $this->testTransactionIds[] = $id;
            $ids[] = $id;
            $stmt = $this->db->prepare(
                'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $memberId, $productId, 150, 'purchase', sprintf('2026-03-12 09:0%d:00', $i)]);
        }

        // Sorted ascending by created_at so ordering of $ids is predictable.
        $page1 = $this->transactionsRepository->listPaginated(2, 0, ['member_id' => $memberId], 'created_at', 'asc');
        $page2 = $this->transactionsRepository->listPaginated(2, 2, ['member_id' => $memberId], 'created_at', 'asc');

        $this->assertEquals(3, $page1['total']);
        $this->assertCount(2, $page1['items']);
        $this->assertCount(1, $page2['items']);
        $this->assertEquals($ids[0], $page1['items'][0]['id']);
        $this->assertEquals($ids[1], $page1['items'][1]['id']);
        $this->assertEquals($ids[2], $page2['items'][0]['id']);
    }

    public function test_listPaginated_returns_empty_for_no_match(): void
    {
        $memberId = $this->createTestMember('EmptyMatch', 'User');

        $result = $this->transactionsRepository->listPaginated(50, 0, ['member_id' => $memberId]);

        $this->assertEquals(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    // ---------------------------------------------------------------
    // findUnsettledByMemberIds (#161 §2 — the whole-position sweep)
    // ---------------------------------------------------------------

    public function test_findUnsettledByMemberIds_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], $this->transactionsRepository->findUnsettledByMemberIds([]));
    }

    public function test_findUnsettledByMemberIds_returns_the_members_whole_unsettled_position(): void
    {
        $memberId = $this->createTestMember('Sweep', 'Member');
        $otherMemberId = $this->createTestMember('SweepOther', 'Member');
        $adminId = $this->createTestAdminUser('sweep-admin@example.com');
        $categoryId = $this->createTestCategory('SweepCategory');
        $productId = $this->createTestProduct($categoryId, 'SweepProduct', 'mug', 500);

        $insert = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        // Already collected in January — must not be swept up a second time.
        $settledId = $this->generateUuid();
        $this->testTransactionIds[] = $settledId;
        $insert->execute([$settledId, $memberId, $productId, 2000, 'purchase', null, '2026-01-10 10:00:00']);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$settlementId, '2026-01-31', '2026-02-07', 2000, 1, $adminId]);
        $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        )->execute([$settlementId, $settledId, $settledId, $memberId, 2000]);

        // The credit from reversing it, plus a February drink: both unsettled,
        // and both outside any February-only window.
        $stornoId = $this->generateUuid();
        $this->testTransactionIds[] = $stornoId;
        $insert->execute([$stornoId, $memberId, $productId, -2000, 'storno', $settledId, '2026-01-11 10:00:00']);

        $februaryId = $this->generateUuid();
        $this->testTransactionIds[] = $februaryId;
        $insert->execute([$februaryId, $memberId, $productId, 500, 'purchase', null, '2026-02-10 10:00:00']);

        // Another member's unsettled row must not leak into the sweep.
        $otherId = $this->generateUuid();
        $this->testTransactionIds[] = $otherId;
        $insert->execute([$otherId, $otherMemberId, $productId, 700, 'purchase', null, '2026-02-10 10:00:00']);

        $rows = $this->transactionsRepository->findUnsettledByMemberIds([$memberId]);

        $ids = array_column($rows, 'id');
        sort($ids);
        $expected = [$stornoId, $februaryId];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertSame(-1500, array_sum(array_map(static fn(array $r): int => (int) $r['amount_cents'], $rows)));
    }

    // ---------------------------------------------------------------
    // findUnsettledByIds
    // ---------------------------------------------------------------

    public function test_findUnsettledByIds_returns_empty_array_for_empty_input(): void
    {
        $result = $this->transactionsRepository->findUnsettledByIds([]);

        $this->assertSame([], $result);
    }

    public function test_findUnsettledByIds_excludes_settled_transactions(): void
    {
        $memberId = $this->createTestMember('Unsettled', 'Ids');
        $adminId = $this->createTestAdminUser('unsettled-ids-admin@example.com');
        $categoryId = $this->createTestCategory('Drinks');
        $productId = $this->createTestProduct($categoryId, 'Vodka', 'vodka', 600);

        $unsettledId = $this->generateUuid();
        $this->testTransactionIds[] = $unsettledId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$unsettledId, $memberId, $productId, 600, 'purchase', '2026-03-13 09:00:00']);

        $settledId = $this->generateUuid();
        $this->testTransactionIds[] = $settledId;
        $stmt->execute([$settledId, $memberId, $productId, 600, 'purchase', '2026-03-13 09:01:00']);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, '2026-03-13', '2026-03-16', 600, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $settledId, $settledId, $memberId, 600]);

        $result = $this->transactionsRepository->findUnsettledByIds([$unsettledId, $settledId]);
        $foundIds = array_column($result, 'id');

        $this->assertContains($unsettledId, $foundIds);
        $this->assertNotContains($settledId, $foundIds);
    }

    // ---------------------------------------------------------------
    // countRecentTransactions / sumUnsettledAmountCents
    // (no filter parameters exist, so we verify via before/after delta on our own inserts)
    // ---------------------------------------------------------------

    public function test_countRecentTransactions_counts_transaction_within_window(): void
    {
        $before = $this->transactionsRepository->countRecentTransactions(30);

        $memberId = $this->createTestMember('RecentCount', 'User');
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, null, 150, 'purchase', date('Y-m-d H:i:s')]);

        $after = $this->transactionsRepository->countRecentTransactions(30);

        $this->assertEquals($before + 1, $after);
    }

    public function test_countRecentTransactions_excludes_old_transaction(): void
    {
        $before = $this->transactionsRepository->countRecentTransactions(30);

        $memberId = $this->createTestMember('OldRecentCount', 'User');
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        // Well outside the 30-day window.
        $stmt->execute([$transactionId, $memberId, null, 150, 'purchase', '2020-01-01 00:00:00']);

        $after = $this->transactionsRepository->countRecentTransactions(30);

        $this->assertEquals($before, $after, 'Old transaction must not be counted as recent');
    }

    public function test_sumUnsettledAmountCents_includes_only_unsettled(): void
    {
        $before = $this->transactionsRepository->sumUnsettledAmountCents();

        $memberId = $this->createTestMember('SumUnsettled', 'User');
        $adminId = $this->createTestAdminUser('sum-unsettled-admin@example.com');

        $unsettledId = $this->generateUuid();
        $this->testTransactionIds[] = $unsettledId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$unsettledId, $memberId, null, 400, 'purchase', date('Y-m-d H:i:s')]);

        $settledId = $this->generateUuid();
        $this->testTransactionIds[] = $settledId;
        $stmt->execute([$settledId, $memberId, null, 900, 'purchase', date('Y-m-d H:i:s')]);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, date('Y-m-d'), date('Y-m-d'), 900, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $settledId, $settledId, $memberId, 900]);

        $after = $this->transactionsRepository->sumUnsettledAmountCents();

        $this->assertEquals($before + 400, $after, 'Only the unsettled transaction amount should be added');
    }

    // ---------------------------------------------------------------
    // getUnsettledMemberBalanceCents / hasMemberInActiveSettlement
    // ---------------------------------------------------------------

    public function test_getUnsettledMemberBalanceCents_nets_stornos_against_purchases(): void
    {
        $memberId = $this->createTestMember('Balance', 'User');

        $tx1 = $this->generateUuid();
        $this->testTransactionIds[] = $tx1;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$tx1, $memberId, null, 500, 'purchase', date('Y-m-d H:i:s')]);

        $tx2 = $this->generateUuid();
        $this->testTransactionIds[] = $tx2;
        $tx2Stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $tx2Stmt->execute([$tx2, $memberId, null, -150, 'storno', $tx1, date('Y-m-d H:i:s')]);

        $balance = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);

        $this->assertEquals(350, $balance);
    }

    public function test_getUnsettledMemberBalanceCents_returns_zero_for_member_without_transactions(): void
    {
        $memberId = $this->createTestMember('NoTx', 'User');

        $balance = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);

        $this->assertEquals(0, $balance);
    }

    public function test_getUnsettledMemberBalanceCents_excludes_settled_transactions(): void
    {
        $memberId = $this->createTestMember('UnsettledBalance', 'User');
        $adminId = $this->createTestAdminUser('unsettled-balance-admin@example.com');

        $unsettledId = $this->generateUuid();
        $this->testTransactionIds[] = $unsettledId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$unsettledId, $memberId, null, 300, 'purchase', date('Y-m-d H:i:s')]);

        $settledId = $this->generateUuid();
        $this->testTransactionIds[] = $settledId;
        $stmt->execute([$settledId, $memberId, null, 800, 'purchase', date('Y-m-d H:i:s')]);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, date('Y-m-d'), date('Y-m-d'), 800, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $settledId, $settledId, $memberId, 800]);

        $balance = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);

        $this->assertEquals(300, $balance);
    }

    public function test_hasMemberInActiveSettlement_true_when_in_active_settlement(): void
    {
        $memberId = $this->createTestMember('ActiveSettlement', 'User');
        $adminId = $this->createTestAdminUser('active-settlement-admin@example.com');

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, null, 500, 'purchase', date('Y-m-d H:i:s')]);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, date('Y-m-d'), date('Y-m-d'), 500, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $transactionId, $transactionId, $memberId, 500]);

        $this->assertTrue($this->transactionsRepository->hasMemberInActiveSettlement($memberId));
    }

    public function test_hasMemberInActiveSettlement_false_when_settlement_cancelled(): void
    {
        $memberId = $this->createTestMember('CancelledSettlementCheck', 'User');
        $adminId = $this->createTestAdminUser('cancelled-settlement-check-admin@example.com');

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, null, 500, 'purchase', date('Y-m-d H:i:s')]);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id, is_cancelled) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, date('Y-m-d'), date('Y-m-d'), 500, 1, $adminId, 1]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $transactionId, $memberId, 500]);

        $this->assertFalse($this->transactionsRepository->hasMemberInActiveSettlement($memberId));
    }

    public function test_hasMemberInActiveSettlement_false_when_never_settled(): void
    {
        $memberId = $this->createTestMember('NeverSettled', 'User');

        $this->assertFalse($this->transactionsRepository->hasMemberInActiveSettlement($memberId));
    }

    // ---------------------------------------------------------------
    // summarizeUnsettledByFilters / findAllUnsettledByFilters
    // ---------------------------------------------------------------

    public function test_summarizeUnsettledByFilters_scoped_by_member_id(): void
    {
        $memberId = $this->createTestMember('SummaryFilter', 'User');
        $adminId = $this->createTestAdminUser('summary-filter-admin@example.com');

        $unsettledId = $this->generateUuid();
        $this->testTransactionIds[] = $unsettledId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$unsettledId, $memberId, null, 250, 'purchase', date('Y-m-d H:i:s')]);

        $settledId = $this->generateUuid();
        $this->testTransactionIds[] = $settledId;
        $stmt->execute([$settledId, $memberId, null, 950, 'purchase', date('Y-m-d H:i:s')]);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, date('Y-m-d'), date('Y-m-d'), 950, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $settledId, $settledId, $memberId, 950]);

        $summary = $this->transactionsRepository->summarizeUnsettledByFilters(['member_id' => $memberId]);

        $this->assertEquals(1, $summary['transaction_count']);
        $this->assertEquals(1, $summary['member_count']);
        $this->assertEquals(250, $summary['total_amount_cents']);
    }

    public function test_summarizeUnsettledByFilters_with_search_and_date_range(): void
    {
        $memberId = $this->createTestMember('SummarySearch', 'Distinctivename');

        $matchId = $this->generateUuid();
        $this->testTransactionIds[] = $matchId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$matchId, $memberId, null, 300, 'purchase', '2026-04-05 10:00:00']);

        $outOfRangeId = $this->generateUuid();
        $this->testTransactionIds[] = $outOfRangeId;
        $stmt->execute([$outOfRangeId, $memberId, null, 300, 'purchase', '2026-04-20 10:00:00']);

        $summary = $this->transactionsRepository->summarizeUnsettledByFilters([
            'search' => 'Distinctivename',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-10',
        ]);

        $this->assertEquals(1, $summary['transaction_count']);
        $this->assertEquals(300, $summary['total_amount_cents']);
    }

    public function test_summarizeUnsettledByFilters_returns_zeroes_for_no_match(): void
    {
        $memberId = $this->createTestMember('NoUnsettled', 'User');

        $summary = $this->transactionsRepository->summarizeUnsettledByFilters(['member_id' => $memberId]);

        $this->assertEquals(0, $summary['transaction_count']);
        $this->assertEquals(0, $summary['member_count']);
        $this->assertEquals(0, $summary['total_amount_cents']);
    }

    public function test_findAllUnsettledByFilters_returns_ids_ordered_by_created_at_asc(): void
    {
        $memberId = $this->createTestMember('AllUnsettled', 'User');

        $earlierId = $this->generateUuid();
        $this->testTransactionIds[] = $earlierId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$earlierId, $memberId, null, 100, 'purchase', '2026-04-06 08:00:00']);

        $laterId = $this->generateUuid();
        $this->testTransactionIds[] = $laterId;
        $stmt->execute([$laterId, $memberId, null, 100, 'purchase', '2026-04-06 09:00:00']);

        $ids = $this->transactionsRepository->findAllUnsettledByFilters(['member_id' => $memberId]);

        $this->assertEquals([$earlierId, $laterId], $ids);
    }

    public function test_findAllUnsettledByFilters_excludes_settled_transactions(): void
    {
        $memberId = $this->createTestMember('AllUnsettledExclude', 'User');
        $adminId = $this->createTestAdminUser('all-unsettled-exclude-admin@example.com');

        $unsettledId = $this->generateUuid();
        $this->testTransactionIds[] = $unsettledId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$unsettledId, $memberId, null, 100, 'purchase', date('Y-m-d H:i:s')]);

        $settledId = $this->generateUuid();
        $this->testTransactionIds[] = $settledId;
        $stmt->execute([$settledId, $memberId, null, 100, 'purchase', date('Y-m-d H:i:s')]);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, date('Y-m-d'), date('Y-m-d'), 100, 1, $adminId]);

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $settledId, $settledId, $memberId, 100]);

        $ids = $this->transactionsRepository->findAllUnsettledByFilters(['member_id' => $memberId]);

        $this->assertEquals([$unsettledId], $ids);
    }

    public function test_findAllUnsettledByFilters_returns_empty_for_no_match(): void
    {
        $memberId = $this->createTestMember('AllUnsettledEmpty', 'User');

        $ids = $this->transactionsRepository->findAllUnsettledByFilters(['member_id' => $memberId]);

        $this->assertSame([], $ids);
    }
}
