<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Settlements\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Enums\SettlementStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * Recording that a settlement's file reached the bank (#377, ruling #142 §1).
 *
 * The guards themselves are pinned with mocks in
 * `Tests\Unit\...\SettlementsServiceTest`. What only a database can show is the
 * part that matters to a treasurer: that one click leaves *three* facts behind
 * — the timestamp, who clicked, and an audit entry naming the settlement — and
 * that the run's derived status and its cancellability move together with them.
 * A submission that stamps `submitted_at` and writes no audit entry is a run
 * nobody can later prove was sent.
 */
class SettlementSubmitTest extends DatabaseTestCase
{
    private SettlementsRepository $settlementsRepository;
    private SettlementsService $service;

    private array $testMemberIds = [];
    private array $testAdminUserIds = [];
    private array $testCategoryIds = [];
    private array $testProductIds = [];
    private array $testTransactionIds = [];
    private array $testSettlementIds = [];

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEncryptionKey();

        $this->settlementsRepository = new SettlementsRepository($this->db, $this->logger);

        $this->service = new SettlementsService(
            $this->settlementsRepository,
            new MembersRepository(
                $this->db,
                $this->logger,
                new IbanSealedBox(
                    '0000000000000000000000000000000000000000000000000000000000000002',
                    'test',
                ),
                new EncryptionKeysRepository($this->db, $this->logger),
            ),
            new TransactionsRepository($this->db, $this->logger),
            new AuditService(new AuditLogRepository($this->db, $this->logger)),
            $this->db,
            new SettlementReversalsRepository($this->db, $this->logger),
            new NotificationsService(
                new MailOutboxRepository($this->db, $this->logger),
                new MembersRepository(
                    $this->db,
                    $this->logger,
                    new IbanSealedBox(
                        '0000000000000000000000000000000000000000000000000000000000000002',
                        'test',
                    ),
                    new EncryptionKeysRepository($this->db, $this->logger),
                ),
                new AuditService(new AuditLogRepository($this->db, $this->logger)),
                new AdminUsersRepository($this->db, $this->logger),
            ),
        );

        $this->adminId = $this->createTestAdminUser('submit-' . substr($this->generateUuid(), 0, 8) . '@example.com');
    }

    protected function tearDown(): void
    {
        if (!empty($this->testSettlementIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testSettlementIds), '?'));
            $this->db->prepare("DELETE FROM settlement_items WHERE settlement_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
            $this->db->prepare("DELETE FROM audit_log WHERE entity_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
        }

        $this->cleanupTestData('settlements', $this->testSettlementIds);
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        $this->cleanupTestData('members', $this->testMemberIds);
        $this->cleanupTestData('admin_users', $this->testAdminUserIds);

        parent::tearDown();
    }

    // ── A treasurer can record that the file reached the bank ──────────

    public function test_recording_a_submission_stamps_the_run_and_leaves_an_audit_trail(): void
    {
        $settlementId = $this->settlementCovering(2500, exported: true);

        $this->assertTrue($this->service->markSubmitted($settlementId, $this->adminId));

        $row = $this->settlementsRepository->findById($settlementId);
        $this->assertNotNull($row['submitted_at'], 'the moment the file went to the bank is recorded');
        $this->assertSame($this->adminId, $row['submitted_by_admin_id'], 'and who says it did');

        // Not "an audit entry exists" but "this settlement's submission does":
        // the entry is the only later evidence the click ever happened.
        $entries = $this->auditEntriesFor($settlementId, 'settlement_submit');
        $this->assertCount(1, $entries);
        $this->assertSame($this->adminId, $entries[0]['admin_user_id']);
        $this->assertStringContainsString('submitted_at', (string) $entries[0]['new_values']);
    }

    public function test_a_submitted_run_reads_as_submitted_and_can_no_longer_be_cancelled(): void
    {
        $settlementId = $this->settlementCovering(2500, exported: true);

        $before = $this->service->getSettlement($settlementId);
        $this->assertSame(SettlementStatus::EXPORTED, $before->status);
        $this->assertTrue($before->isCancellable, 'generating a file is not sending it (ruling #142 §1)');

        $this->service->markSubmitted($settlementId, $this->adminId);

        $after = $this->service->getSettlement($settlementId);
        $this->assertSame(SettlementStatus::SUBMITTED, $after->status);
        $this->assertFalse($after->isCancellable, 'the money has moved; this is the point of no return');
        $this->assertTrue($after->isReversible, 'and reversal is what is left');
        $this->assertNotNull($after->cancellationBlockedReason);
    }

    // ── A run nobody has exported cannot be claimed to be at the bank ──

    public function test_a_run_with_no_exported_file_cannot_be_claimed_to_be_at_the_bank(): void
    {
        $settlementId = $this->settlementCovering(2500, exported: false);

        try {
            $this->service->markSubmitted($settlementId, $this->adminId);
            $this->fail('An unexported settlement was accepted as submitted');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Export the SEPA file', $e->getMessage());
        }

        $this->assertNull($this->settlementsRepository->findById($settlementId)['submitted_at']);
        $this->assertSame([], $this->auditEntriesFor($settlementId, 'settlement_submit'));
    }

    // ── The bank cannot receive the same run twice ─────────────────────

    public function test_the_same_run_cannot_be_submitted_twice(): void
    {
        $settlementId = $this->settlementCovering(2500, exported: true);
        $this->service->markSubmitted($settlementId, $this->adminId);
        $firstStamp = $this->settlementsRepository->findById($settlementId)['submitted_at'];

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/already been marked as submitted/');

        try {
            $this->service->markSubmitted($settlementId, $this->adminId);
        } finally {
            // A second attempt must not quietly move the timestamp: the date
            // the bank received the file is what a later dispute turns on.
            $this->assertSame($firstStamp, $this->settlementsRepository->findById($settlementId)['submitted_at']);
            $this->assertCount(1, $this->auditEntriesFor($settlementId, 'settlement_submit'));
        }
    }

    // ── A cancelled run cannot be sent to the bank ─────────────────────

    public function test_a_cancelled_run_cannot_be_sent_to_the_bank(): void
    {
        $settlementId = $this->settlementCovering(2500, exported: true);
        $this->db->prepare('UPDATE settlements SET is_cancelled = 1, cancelled_at = NOW() WHERE id = ?')
            ->execute([$settlementId]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/cancelled/');

        $this->service->markSubmitted($settlementId, $this->adminId);
    }

    // ── No bank is involved in a bank transfer or a write-off ──────────

    /**
     * A settlement collected outside the SEPA mandate has no pain.008 file and
     * no bank submission to record — #163's double collection is exactly what
     * happens when the two are conflated.
     */
    public function test_only_a_direct_debit_is_ever_submitted_to_a_bank(): void
    {
        foreach ([SettlementMethod::BANK_TRANSFER, SettlementMethod::WRITE_OFF] as $method) {
            $settlementId = $this->settlementCovering(2500, exported: true, method: $method);

            try {
                $this->service->markSubmitted($settlementId, $this->adminId);
                $this->fail("A {$method->value} settlement was accepted as submitted");
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString($method->label(), $e->getMessage());
            }

            $this->assertNull($this->settlementsRepository->findById($settlementId)['submitted_at']);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** One member, one purchase, one settlement claiming it. */
    private function settlementCovering(
        int $amountCents,
        bool $exported,
        SettlementMethod $method = SettlementMethod::DIRECT_DEBIT,
    ): string {
        $categoryId = $this->createTestCategory('Submit ' . substr($this->generateUuid(), 0, 8));
        $productId = $this->createTestProduct($categoryId, 'Beer', 'beer', 250);
        $memberId = $this->createTestMember('Submit', 'Tester');
        $transactionId = $this->createTestTransaction($memberId, $productId, $amountCents);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;

        $this->db->prepare(
            'INSERT INTO settlements
                (id, method, settlement_date, execution_date, total_amount_cents, member_count,
                 created_by_admin_id, is_cancelled, exported_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, 0, ?)'
        )->execute([
            $settlementId,
            $method->value,
            date('Y-m-d'),
            // Far enough out that CancellationGate's execution-date backstop
            // does not do the submitted flag's job for it.
            date('Y-m-d', strtotime('+14 days')),
            $amountCents,
            $this->adminId,
            $exported ? date('Y-m-d H:i:s') : null,
        ]);

        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => $amountCents,
        ]);

        return $settlementId;
    }

    private function createTestAdminUser(string $email): string
    {
        $adminId = $this->generateUuid();
        $this->testAdminUserIds[] = $adminId;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$adminId, $email, password_hash('test123', PASSWORD_BCRYPT), 'Submit Admin']);

        return $adminId;
    }

    private function createTestMember(string $firstName, string $lastName): string
    {
        $memberId = $this->generateUuid();
        $this->testMemberIds[] = $memberId;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([
            $memberId,
            $firstName,
            $lastName,
            strtolower($firstName) . '-' . substr($memberId, 0, 8) . '@example.com',
            'de',
        ]);

        return $memberId;
    }

    private function createTestCategory(string $name): string
    {
        $categoryId = $this->generateUuid();
        $this->testCategoryIds[] = $categoryId;

        $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, 1)')
            ->execute([$categoryId, json_encode(['de' => $name, 'en' => $name])]);

        return $categoryId;
    }

    private function createTestProduct(string $categoryId, string $name, string $iconName, int $priceCents): string
    {
        $productId = $this->generateUuid();
        $this->testProductIds[] = $productId;

        $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, icon_name, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$productId, $categoryId, json_encode(['de' => $name, 'en' => $name]), $priceCents, $iconName]);

        return $productId;
    }

    private function createTestTransaction(string $memberId, string $productId, int $amountCents): string
    {
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$transactionId, $memberId, $productId, $amountCents, 'purchase', date('Y-m-d H:i:s')]);

        return $transactionId;
    }

    /** @return list<array<string, mixed>> */
    private function auditEntriesFor(string $settlementId, string $action): array
    {
        $stmt = $this->db->prepare('SELECT * FROM audit_log WHERE entity_id = ? AND action = ?');
        $stmt->execute([$settlementId, $action]);

        return $stmt->fetchAll();
    }
}
