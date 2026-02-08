<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transactions\Repositories;

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
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, created_at) VALUES (?, ?, ?, ?, ?, ?)'
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
            'INSERT INTO settlement_items (settlement_id, transaction_id, member_id, amount_cents) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$settlementId, $transactionId, $memberId, 350]);

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
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, created_at) VALUES (?, ?, ?, ?, ?, ?)'
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
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, created_at) VALUES (?, ?, ?, ?, ?, ?)'
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

        // Create a correction transaction (no product)
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, null, -350, 'correction', 'Refund for incorrect charge', '2026-02-06 16:45:00']);

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
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, created_at) VALUES (?, ?, ?, ?, ?, ?)'
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
        $displayOrder = rand(1000, 9999); // Random to avoid conflicts

        $stmt = $this->db->prepare(
            'INSERT INTO categories (id, names, display_order, is_active) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$categoryId, $names, $displayOrder, 1]);

        return $categoryId;
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
}
