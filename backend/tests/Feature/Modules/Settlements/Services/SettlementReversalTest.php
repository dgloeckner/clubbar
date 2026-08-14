<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Settlements\Services;

use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\Security\IbanSealedBox;
use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Enums\SettlementStatus;
use App\Modules\Settlements\Repositories\CollectionHoldRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * The reversal contract from ruling #148, exercised against a real database
 * (#196).
 *
 * These nine tests are the ruling's test contract, in its order. They run here
 * rather than as unit tests because every one of them turns on something only
 * the database can answer: that a nulled `active_transaction_id` really does
 * return a transaction to the unsettled pool while its row survives, that the
 * `UNIQUE (settlement_id, member_id)` constraint really does refuse a second
 * reversal, and that a rolled-back transaction really does leave neither half
 * of the write behind.
 */
class SettlementReversalTest extends DatabaseTestCase
{
    private SettlementsRepository $settlementsRepository;
    private SettlementReversalsRepository $reversalsRepository;
    private CollectionHoldRepository $collectionHoldRepository;
    private MembersRepository $membersRepository;
    private SettlementReversalService $service;
    private SettlementsService $settlementsService;
    private CollectionHoldService $collectionHoldService;

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
        $this->reversalsRepository = new SettlementReversalsRepository($this->db, $this->logger);
        $this->collectionHoldRepository = new CollectionHoldRepository($this->db, $this->logger);
        $this->membersRepository = new MembersRepository($this->db, $this->logger, new IbanSealedBox('0000000000000000000000000000000000000000000000000000000000000002', 'test'), new EncryptionKeysRepository($this->db, $this->logger));

        $auditService = new AuditService(new AuditLogRepository($this->db, $this->logger));

        $this->service = new SettlementReversalService(
            $this->settlementsRepository,
            $this->reversalsRepository,
            $this->collectionHoldRepository,
            $auditService,
            $this->db,
        );

        $this->settlementsService = new SettlementsService(
            $this->settlementsRepository,
            $this->membersRepository,
            new TransactionsRepository($this->db, $this->logger),
            $auditService,
            $this->db,
            $this->reversalsRepository,
            new NotificationsService(
                new MailOutboxRepository($this->db, $this->logger),
                $this->membersRepository,
                $auditService,
            ),
        );

        $this->collectionHoldService = new CollectionHoldService(
            $this->collectionHoldRepository,
            $this->membersRepository,
            $auditService,
        );

        $this->adminId = $this->createTestAdminUser('reversal-' . substr($this->generateUuid(), 0, 8) . '@example.com');
    }

    protected function tearDown(): void
    {
        if (!empty($this->testSettlementIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testSettlementIds), '?'));
            $this->db->prepare("DELETE FROM settlement_reversals WHERE settlement_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
            $this->db->prepare("DELETE FROM settlement_items WHERE settlement_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
        }
        if (!empty($this->testMemberIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testMemberIds), '?'));
            $this->db->prepare("DELETE FROM audit_log WHERE entity_id IN ({$placeholders})")
                ->execute($this->testMemberIds);
        }
        if (!empty($this->testSettlementIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testSettlementIds), '?'));
            $this->db->prepare("DELETE FROM audit_log WHERE entity_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
        }

        $this->cleanupTestData('settlements', $this->testSettlementIds);
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        $this->cleanupTestData('mandates', []);
        if (!empty($this->testMemberIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testMemberIds), '?'));
            $this->db->prepare("DELETE FROM mandates WHERE member_id IN ({$placeholders})")
                ->execute($this->testMemberIds);
        }
        $this->cleanupTestData('members', $this->testMemberIds);
        $this->cleanupTestData('admin_users', $this->testAdminUserIds);

        parent::tearDown();
    }

    // ── 1. A bank return frees only that member's items ────────────────

    public function test_a_bank_return_frees_only_that_members_items(): void
    {
        [$settlementId, $alice, $bob] = $this->settlementCovering(['Alice' => 1500, 'Bob' => 2500]);

        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-1', null, $this->adminId);

        $this->assertTrue($this->isUnsettled($alice), 'the reversed member\'s transaction returns to the unsettled pool');
        $this->assertFalse($this->isUnsettled($bob), 'the other member stays settled');
    }

    // ── 2. The member is held, and the next run leaves them out ────────

    public function test_a_bank_return_holds_the_member_and_the_next_run_skips_them_with_a_reason(): void
    {
        [$settlementId, $alice, $bob] = $this->settlementCovering(['Alice' => 1500, 'Bob' => 2500]);

        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-2', null, $this->adminId);

        $member = $this->membersRepository->findById($alice);
        $this->assertSame(1, (int) $member['collection_hold']);
        $this->assertNotNull($member['held_at']);
        $this->assertSame($this->adminId, $member['held_by_admin_id']);

        $preview = $this->settlementsService->previewSettlement()->toArray();

        $held = $this->pluckMember($preview['held_members'], $alice);
        $this->assertNotNull($held, 'the held member appears in the hold bucket, never as a silent omission');
        $this->assertStringContainsString('returned by the bank', (string) $held['collection_hold_reason']);
        $this->assertNull($this->pluckMember($preview['eligible_members'], $alice), 'and not among the collectable');

        $this->assertNotEmpty(array_filter(
            $preview['warnings'],
            static fn(string $w): bool => str_contains($w, 'collection hold'),
        ));

        // Bob was never reversed, so he is still settled and not a participant.
        $this->assertNull($this->pluckMember($preview['held_members'], $bob));
    }

    // ── 3. Clearing the hold lets the next run collect them ────────────

    public function test_clearing_the_hold_returns_the_member_to_the_next_run(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);
        $this->giveMandate($alice);

        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-3', null, $this->adminId);
        $this->collectionHoldService->clearHold($alice, $this->adminId);

        $member = $this->membersRepository->findById($alice);
        $this->assertSame(0, (int) $member['collection_hold']);
        $this->assertNotNull($member['cleared_at']);
        $this->assertSame($this->adminId, $member['cleared_by_admin_id']);

        $preview = $this->settlementsService->previewSettlement()->toArray();
        $this->assertNull($this->pluckMember($preview['held_members'], $alice));
        $this->assertNotNull(
            $this->pluckMember($preview['eligible_members'], $alice),
            'a cleared member is collectable again',
        );
    }

    public function test_clearing_a_hold_that_is_not_there_is_refused(): void
    {
        $memberId = $this->createTestMember('Never', 'Held');

        $this->expectException(BusinessRuleException::class);
        $this->collectionHoldService->clearHold($memberId, $this->adminId);
    }

    public function test_the_hold_listing_names_every_held_member_and_what_they_owe(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);

        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-L', null, $this->adminId);

        $listed = $this->pluckMember(
            array_map(static fn($dto) => $dto->toArray(), $this->collectionHoldService->listHeld()['items']),
            $alice,
        );

        $this->assertNotNull($listed);
        $this->assertSame(1500, $listed['balance_cents'], 'the freed items are what the member owes again');
    }

    // ── 4. A club error applies no hold ────────────────────────────────

    public function test_a_club_error_frees_the_items_without_holding_the_member(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);
        $this->giveMandate($alice);

        $this->service->reverse($settlementId, [$alice], ReversalReason::CLUB_ERROR, null, 'Wrong amount', $this->adminId);

        $this->assertSame(0, (int) $this->membersRepository->findById($alice)['collection_hold']);
        $this->assertTrue($this->isUnsettled($alice));

        $preview = $this->settlementsService->previewSettlement()->toArray();
        $this->assertNotNull(
            $this->pluckMember($preview['eligible_members'], $alice),
            're-collection is the entire point of recording a club error',
        );
    }

    // ── 5. Derived status: partly, then fully reversed ─────────────────

    public function test_the_status_is_derived_from_the_reversal_rows_alone(): void
    {
        [$settlementId, $alice, $bob] = $this->settlementCovering(['Alice' => 1500, 'Bob' => 2500]);

        $this->assertSame(
            SettlementStatus::SUBMITTED,
            $this->settlementsService->getSettlement($settlementId)->status,
        );

        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-5', null, $this->adminId);
        $this->assertSame(
            SettlementStatus::PARTLY_REVERSED,
            $this->settlementsService->getSettlement($settlementId)->status,
        );

        $this->service->reverse($settlementId, [$bob], ReversalReason::CLUB_ERROR, null, null, $this->adminId);
        $this->assertSame(
            SettlementStatus::FULLY_REVERSED,
            $this->settlementsService->getSettlement($settlementId)->status,
        );

        // No status column exists to disagree with the derivation.
        $columns = $this->db->query("SHOW COLUMNS FROM settlements LIKE 'status'")->fetchAll();
        $this->assertSame([], $columns, 'status is derived, never materialised (ruling #148 §6)');
    }

    public function test_omitting_the_member_list_reverses_every_member_at_once(): void
    {
        [$settlementId, $alice, $bob] = $this->settlementCovering(['Alice' => 1500, 'Bob' => 2500]);

        $reversals = $this->service->reverse($settlementId, null, ReversalReason::BANK_RETURN, 'RET-ALL', null, $this->adminId);

        $this->assertCount(2, $reversals);
        $this->assertSame(
            SettlementStatus::FULLY_REVERSED,
            $this->settlementsService->getSettlement($settlementId)->status,
        );
        $this->assertTrue($this->isUnsettled($alice));
        $this->assertTrue($this->isUnsettled($bob));
    }

    // ── 6. Reversed items keep their rows ──────────────────────────────

    public function test_a_reversed_settlement_still_exports_what_it_originally_contained(): void
    {
        [$settlementId, $alice, $bob] = $this->settlementCovering(['Alice' => 1500, 'Bob' => 2500]);

        $this->service->reverse($settlementId, [$alice, $bob], ReversalReason::BANK_RETURN, 'RET-6', null, $this->adminId);

        $csv = $this->settlementsService->getCsvData($settlementId);
        $this->assertCount(2, $csv, 'both members are still in the settlement\'s own export');
        $this->assertSame(4000, array_sum(array_column($csv, 'amount_cents')));

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertNull($item['active_transaction_id'], 'the claim is released, the history is not');
            $this->assertNotNull($item['transaction_id']);
        }
    }

    // ── 7. The same member cannot be reversed twice ────────────────────

    public function test_reversing_the_same_member_twice_is_refused(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);

        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-7', null, $this->adminId);

        $this->expectException(BusinessRuleException::class);
        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-7b', null, $this->adminId);
    }

    public function test_the_unique_constraint_is_what_actually_guarantees_it(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);

        $this->reversalsRepository->create([
            'settlement_id' => $settlementId,
            'member_id' => $alice,
            'reason' => ReversalReason::BANK_RETURN->value,
            'amount_cents' => 1500,
            'created_by_admin_id' => $this->adminId,
        ]);

        // Straight past the service's check, at the table itself.
        $this->expectException(\PDOException::class);
        $this->reversalsRepository->create([
            'settlement_id' => $settlementId,
            'member_id' => $alice,
            'reason' => ReversalReason::CLUB_ERROR->value,
            'amount_cents' => 1500,
            'created_by_admin_id' => $this->adminId,
        ]);
    }

    // ── 8. A reversal is atomic ────────────────────────────────────────

    public function test_a_failed_reversal_leaves_neither_the_freed_items_nor_the_event(): void
    {
        [$settlementId, $alice, $bob] = $this->settlementCovering(['Alice' => 1500, 'Bob' => 2500]);

        // Bob already has a reversal, placed behind the service's back. The
        // run below therefore frees Alice's items, writes her event, and only
        // then loses to the unique constraint on Bob.
        $this->reversalsRepository->create([
            'settlement_id' => $settlementId,
            'member_id' => $bob,
            'reason' => ReversalReason::CLUB_ERROR->value,
            'amount_cents' => 2500,
            'created_by_admin_id' => $this->adminId,
        ]);
        $this->settlementsRepository->releaseMemberClaims($settlementId, [$bob]);

        try {
            // Not via the service's public reverse(), which would refuse this
            // at the pre-check — the point is what happens when the constraint
            // fires mid-write.
            $this->db->beginTransaction();
            $this->settlementsRepository->releaseMemberClaims($settlementId, [$alice]);
            $this->reversalsRepository->create([
                'settlement_id' => $settlementId,
                'member_id' => $alice,
                'reason' => ReversalReason::BANK_RETURN->value,
                'amount_cents' => 1500,
                'created_by_admin_id' => $this->adminId,
            ]);
            $this->reversalsRepository->create([
                'settlement_id' => $settlementId,
                'member_id' => $bob,
                'reason' => ReversalReason::BANK_RETURN->value,
                'amount_cents' => 2500,
                'created_by_admin_id' => $this->adminId,
            ]);
            $this->db->commit();
            $this->fail('the duplicate reversal should have violated the unique constraint');
        } catch (\PDOException) {
            $this->db->rollBack();
        }

        $this->assertFalse($this->isUnsettled($alice), 'Alice\'s items are still claimed — the release rolled back');
        $this->assertSame(
            [$bob],
            $this->reversalsRepository->findReversedMemberIds($settlementId),
            'and no event was written for her either',
        );
    }

    public function test_the_service_reverses_the_items_and_the_events_together(): void
    {
        [$settlementId, $alice, $bob] = $this->settlementCovering(['Alice' => 1500, 'Bob' => 2500]);

        $reversals = $this->service->reverse($settlementId, [$alice, $bob], ReversalReason::BANK_RETURN, 'RET-8', null, $this->adminId);

        $this->assertCount(2, $reversals);
        $this->assertSame(1500, $reversals[0]->amountCents, 'the amount is derived from the items, not typed in');
        $this->assertSame(2500, $reversals[1]->amountCents);
        $this->assertTrue($this->isUnsettled($alice));
        $this->assertTrue($this->isUnsettled($bob));
        $this->assertCount(2, $this->reversalsRepository->findReversedMemberIds($settlementId));
    }

    public function test_the_reversal_is_written_to_the_audit_log(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);

        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-AUD', null, $this->adminId);

        $stmt = $this->db->prepare('SELECT action FROM audit_log WHERE entity_id = ? OR entity_id = ?');
        $stmt->execute([$settlementId, $alice]);
        $actions = array_column($stmt->fetchAll(), 'action');

        $this->assertContains('settlement_reverse', $actions);
        $this->assertContains('collection_hold_placed', $actions);
    }

    // ── 9. A settlement that never moved money is not reversed ─────────

    public function test_reversal_is_refused_on_a_settlement_that_was_never_submitted(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500], submitted: false);

        $this->expectExceptionMessage('Cancel it instead');
        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, null, null, $this->adminId);
    }

    public function test_reversal_is_refused_on_a_cancelled_settlement(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);
        $this->db->prepare('UPDATE settlements SET is_cancelled = 1 WHERE id = ?')->execute([$settlementId]);

        $this->expectExceptionMessage('never collected anything');
        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, null, null, $this->adminId);
    }

    public function test_a_member_who_is_not_part_of_the_settlement_is_refused(): void
    {
        [$settlementId] = $this->settlementCovering(['Alice' => 1500]);
        $stranger = $this->createTestMember('Not', 'Involved');

        $this->expectException(ValidationException::class);
        $this->service->reverse($settlementId, [$stranger], ReversalReason::BANK_RETURN, null, null, $this->adminId);
    }

    // ── The hold blocks a sweep, but never every way out ───────────────

    public function test_a_held_member_can_still_be_written_off_or_recorded_as_a_transfer(): void
    {
        [$settlementId, $alice] = $this->settlementCovering(['Alice' => 1500]);
        $this->giveMandate($alice);
        $this->service->reverse($settlementId, [$alice], ReversalReason::BANK_RETURN, 'RET-WO', null, $this->adminId);

        $freed = $this->unsettledTransactionIdsFor($alice);

        // A direct debit refuses to touch a held member...
        try {
            $this->settlementsService->createSettlement(
                $freed,
                date('Y-m-d', strtotime('+14 days')),
                null,
                null,
                SettlementMethod::DIRECT_DEBIT,
                null,
                $this->adminId,
            );
            $this->fail('a direct debit must not collect from a held member');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('held_member_ids', $e->getErrors());
        }

        // ...but a write-off, which collects nothing, is exactly how the debt
        // is resolved, so the hold must not stand in its way.
        $writeOff = $this->settlementsService->createSettlement(
            $freed,
            date('Y-m-d', strtotime('+14 days')),
            null,
            null,
            SettlementMethod::WRITE_OFF,
            null,
            $this->adminId,
        );
        $this->testSettlementIds[] = $writeOff->id;

        $this->assertFalse($this->isUnsettled($alice));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * A submitted direct-debit settlement covering one member per entry.
     *
     * @param array<string, int> $membersByAmount First name => amount in cents.
     * @return list<string> The settlement id, then one member id per entry, in order.
     */
    private function settlementCovering(array $membersByAmount, bool $submitted = true): array
    {
        $categoryId = $this->createTestCategory('Reversal ' . substr($this->generateUuid(), 0, 8));
        $productId = $this->createTestProduct($categoryId, 'Beer', 'beer', 250);

        $settlementId = $this->createSettlementRow(
            totalAmountCents: array_sum($membersByAmount),
            memberCount: count($membersByAmount),
            submitted: $submitted,
        );

        $memberIds = [];
        foreach ($membersByAmount as $firstName => $amountCents) {
            $memberId = $this->createTestMember((string) $firstName, 'Reversal');
            $transactionId = $this->createTestTransaction($memberId, $productId, $amountCents);

            $this->settlementsRepository->createItem([
                'settlement_id' => $settlementId,
                'transaction_id' => $transactionId,
                'member_id' => $memberId,
                'amount_cents' => $amountCents,
            ]);

            $memberIds[] = $memberId;
        }

        return array_merge([$settlementId], $memberIds);
    }

    /** Does this member have a transaction nobody's settlement currently claims? */
    private function isUnsettled(string $memberId): bool
    {
        return $this->unsettledTransactionIdsFor($memberId) !== [];
    }

    /** @return list<string> */
    private function unsettledTransactionIdsFor(string $memberId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id FROM transactions t
              WHERE t.member_id = ?
                AND NOT EXISTS (SELECT 1 FROM settlement_items si WHERE si.active_transaction_id = t.id)'
        );
        $stmt->execute([$memberId]);

        return array_column($stmt->fetchAll(), 'id');
    }

    /** @param list<array<string, mixed>> $bucket */
    private function pluckMember(array $bucket, string $memberId): ?array
    {
        foreach ($bucket as $entry) {
            if ($entry['member_id'] === $memberId) {
                return $entry;
            }
        }

        return null;
    }

    /** SEPA eligibility is an active mandate (#164); the preview needs one to call a member collectable. */
    private function giveMandate(string $memberId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban_ciphertext, iban_last4, iban_fingerprint, encryption_key_id, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->generateUuid(),
            $memberId,
            $memberId,
            strtoupper(str_replace('-', '', $this->generateUuid())),
            $this->sealIban('DE89370400440532013000'),
            '3000',
            $this->ibanFingerprint('DE89370400440532013000'),
            self::DEV_KEY_ID,
            '2024-01-01',
        ]);
    }

    private function createTestMember(string $firstName, string $lastName): string
    {
        $memberId = $this->generateUuid();
        $this->testMemberIds[] = $memberId;

        $stmt = $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $firstName,
            $lastName,
            strtolower($firstName) . '-' . substr($memberId, 0, 8) . '@example.com',
            'de',
            1,
        ]);

        return $memberId;
    }

    private function createTestAdminUser(string $email): string
    {
        $adminId = $this->generateUuid();
        $this->testAdminUserIds[] = $adminId;

        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$adminId, $email, password_hash('test123', PASSWORD_BCRYPT), 'Reversal Admin', 1]);

        return $adminId;
    }

    private function createTestCategory(string $name): string
    {
        $categoryId = $this->generateUuid();
        $this->testCategoryIds[] = $categoryId;

        $stmt = $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, ?)');
        $stmt->execute([$categoryId, json_encode(['de' => $name, 'en' => $name]), 1]);

        return $categoryId;
    }

    private function createTestProduct(string $categoryId, string $name, string $iconName, int $priceCents): string
    {
        $productId = $this->generateUuid();
        $this->testProductIds[] = $productId;

        $stmt = $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, icon_name, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$productId, $categoryId, json_encode(['de' => $name, 'en' => $name]), $priceCents, $iconName, 1]);

        return $productId;
    }

    private function createTestTransaction(string $memberId, string $productId, int $amountCents): string
    {
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, $amountCents, 'purchase', date('Y-m-d H:i:s')]);

        return $transactionId;
    }

    private function createSettlementRow(int $totalAmountCents, int $memberCount, bool $submitted): string
    {
        $id = $this->generateUuid();
        $this->testSettlementIds[] = $id;

        $stmt = $this->db->prepare(
            'INSERT INTO settlements
                (id, method, settlement_date, execution_date, total_amount_cents, member_count,
                 created_by_admin_id, is_cancelled, exported_at, submitted_at, submitted_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            $id,
            SettlementMethod::DIRECT_DEBIT->value,
            date('Y-m-d'),
            // Far enough out that CancellationGate's execution-date backstop
            // does not do the submitted flag's job for it.
            date('Y-m-d', strtotime('+14 days')),
            $totalAmountCents,
            $memberCount,
            $this->adminId,
            $submitted ? $now : null,
            $submitted ? $now : null,
            $submitted ? $this->adminId : null,
        ]);

        return $id;
    }
}
