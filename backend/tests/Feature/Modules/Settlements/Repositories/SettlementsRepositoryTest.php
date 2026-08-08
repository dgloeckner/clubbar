<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Settlements\Repositories;

use App\Modules\Settlements\Repositories\SettlementsRepository;
use Tests\Feature\DatabaseTestCase;

class SettlementsRepositoryTest extends DatabaseTestCase
{
    private SettlementsRepository $settlementsRepository;
    private array $testMemberIds = [];
    private array $testAdminUserIds = [];
    private array $testCategoryIds = [];
    private array $testProductIds = [];
    private array $testTransactionIds = [];
    private array $testSettlementIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementsRepository = new SettlementsRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up in reverse FK order
        $this->cleanupTestData('settlement_items', []); // cascades on settlement delete, but be explicit for any manual inserts
        if (!empty($this->testSettlementIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testSettlementIds), '?'));
            $this->db->prepare("DELETE FROM settlement_items WHERE settlement_id IN ({$placeholders})")->execute($this->testSettlementIds);
        }
        $this->cleanupTestData('settlements', $this->testSettlementIds);
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        $this->cleanupTestData('members', $this->testMemberIds);
        $this->cleanupTestData('admin_users', $this->testAdminUserIds);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // findById
    // ------------------------------------------------------------------

    public function test_findById_returns_settlement_with_admin_display_name(): void
    {
        $adminId = $this->createTestAdminUser('findbyid-admin@example.com', 'Find Admin');
        $settlementId = $this->createSettlementRow($adminId, '2026-02-01', '2026-02-05', 1000, 2);

        $result = $this->settlementsRepository->findById($settlementId);

        $this->assertNotNull($result);
        $this->assertEquals($settlementId, $result['id']);
        $this->assertEquals('2026-02-01', $result['settlement_date']);
        $this->assertEquals(1000, (int) $result['total_amount_cents']);
        $this->assertEquals(2, (int) $result['member_count']);
        $this->assertEquals('Find Admin', $result['admin_display_name']);
    }

    public function test_findById_returns_null_for_nonexistent_id(): void
    {
        $result = $this->settlementsRepository->findById($this->generateUuid());

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // getLatest
    // ------------------------------------------------------------------

    public function test_getLatest_excludes_cancelled_settlements(): void
    {
        $adminId = $this->createTestAdminUser('latest-admin@example.com');

        // Cancelled settlement, created "later" than the active one below
        // (TIMESTAMP columns max out at 2038-01-19, so use the latest safe year.)
        $cancelledId = $this->createSettlementRow($adminId, '2026-03-01', '2026-03-05', 500, 1, isCancelled: true);
        $this->setSettlementCreatedAt($cancelledId, '2038-01-01 00:00:00');

        // Active settlement with an older created_at, but should still be the "latest" active one
        $activeId = $this->createSettlementRow($adminId, '2026-03-02', '2026-03-06', 700, 1);
        $this->setSettlementCreatedAt($activeId, '2037-01-01 00:00:00');

        $latest = $this->settlementsRepository->getLatest();

        $this->assertNotNull($latest);
        $this->assertEquals($activeId, $latest['id'], 'getLatest must skip cancelled settlements even if created later');
    }

    // ------------------------------------------------------------------
    // findItemsBySettlementId
    // ------------------------------------------------------------------

    public function test_findItemsBySettlementId_returns_items_with_joined_data(): void
    {
        $adminId = $this->createTestAdminUser('items-admin@example.com');
        $memberId = $this->createTestMember('Items', 'Member');
        $categoryId = $this->createTestCategory('ItemsCategory');
        $productId = $this->createTestProduct($categoryId, 'ItemsProduct', 'cup', 450);
        $transactionId = $this->createTestTransaction($memberId, $productId, 450, 'purchase', '2026-02-10 09:00:00');

        $settlementId = $this->createSettlementRow($adminId, '2026-02-11', '2026-02-12', 450, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => 450,
        ]);

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);

        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertEquals($transactionId, $item['transaction_id']);
        $this->assertEquals($memberId, $item['member_id']);
        $this->assertEquals(450, (int) $item['amount_cents']);
        $this->assertEquals('Items', $item['first_name']);
        $this->assertEquals('Member', $item['last_name']);
        $this->assertEquals('purchase', $item['transaction_type']);
        $this->assertEquals($productId, $item['product_id']);
        $this->assertEquals(450, (int) $item['product_price_cents']);
    }

    public function test_findItemsBySettlementId_returns_empty_array_for_nonexistent_settlement(): void
    {
        $items = $this->settlementsRepository->findItemsBySettlementId($this->generateUuid());

        $this->assertIsArray($items);
        $this->assertCount(0, $items);
    }

    // ------------------------------------------------------------------
    // calculateUnsettledPositions / findParticipantMemberIds (#161, ruling #141)
    // ------------------------------------------------------------------

    public function test_calculateUnsettledPositions_sums_unsettled_transactions_and_excludes_settled(): void
    {
        $adminId = $this->createTestAdminUser('balance-admin@example.com');
        $memberId = $this->createTestMember('Balance', 'Member');
        $categoryId = $this->createTestCategory('BalanceCategory');
        $productId = $this->createTestProduct($categoryId, 'BalanceProduct', 'mug', 300);

        // Unsettled transaction: should be included (id not needed, only its existence)
        $this->createTestTransaction($memberId, $productId, 300, 'purchase', '2026-04-01 10:00:00');

        // Settled transaction (active settlement): should be excluded
        $settledTxId = $this->createTestTransaction($memberId, $productId, 300, 'purchase', '2026-04-01 11:00:00');
        $settlementId = $this->createSettlementRow($adminId, '2026-04-02', '2026-04-03', 300, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $settledTxId,
            'member_id' => $memberId,
            'amount_cents' => 300,
        ]);

        $balances = $this->settlementsRepository->calculateUnsettledPositions();

        $this->assertArrayHasKey($memberId, $balances);
        $this->assertEquals(300, $balances[$memberId], 'Only the unsettled transaction should count towards the balance');
    }

    public function test_calculateUnsettledPositions_includes_transactions_settled_by_cancelled_settlement(): void
    {
        $adminId = $this->createTestAdminUser('cancelbal-admin@example.com');
        $memberId = $this->createTestMember('CancelBal', 'Member');
        $categoryId = $this->createTestCategory('CancelBalCategory');
        $productId = $this->createTestProduct($categoryId, 'CancelBalProduct', 'mug', 500);

        $transactionId = $this->createTestTransaction($memberId, $productId, 500, 'purchase', '2026-04-05 10:00:00');
        $settlementId = $this->createSettlementRow($adminId, '2026-04-06', '2026-04-07', 500, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => 500,
        ]);
        // Cancelled through the operation that cancels, not by flipping the
        // flag: what frees the transaction is the released claim, and only
        // cancelSettlement() releases it.
        $this->settlementsRepository->cancelSettlement($settlementId, $adminId);

        $balances = $this->settlementsRepository->calculateUnsettledPositions();

        $this->assertArrayHasKey($memberId, $balances, 'Transactions "settled" only by a cancelled settlement remain unsettled');
        $this->assertEquals(500, $balances[$memberId]);
    }

    /**
     * The January/February scenario from #161: a €20 credit booked in January
     * and a €5 purchase in February. Settling February alone used to compute
     * +500 and debit money the member does not owe. The exclusion test is on
     * the member's *total* unsettled position, so the window must not bound it.
     */
    public function test_calculateUnsettledPositions_ignores_the_settlement_window(): void
    {
        $adminId = $this->createTestAdminUser('carry-admin@example.com');
        $memberId = $this->createTestMember('Carry', 'Forward');
        $categoryId = $this->createTestCategory('CarryCategory');
        $productId = $this->createTestProduct($categoryId, 'CarryProduct', 'mug', 500);

        // January: €20 was collected, then stornoed — the club owes €20 back.
        // The purchase is settled, so only the storno remains unsettled.
        $januaryPurchase = $this->createTestTransaction($memberId, $productId, 2000, 'purchase', '2026-01-10 10:00:00');
        $januarySettlement = $this->createSettlementRow($adminId, '2026-01-31', '2026-02-07', 2000, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $januarySettlement,
            'transaction_id' => $januaryPurchase,
            'member_id' => $memberId,
            'amount_cents' => 2000,
        ]);
        $this->createTestTransaction($memberId, $productId, -2000, 'storno', '2026-01-11 10:00:00', $januaryPurchase);
        // February: a €5 drink.
        $this->createTestTransaction($memberId, $productId, 500, 'purchase', '2026-02-10 10:00:00');

        $positions = $this->settlementsRepository->calculateUnsettledPositions([$memberId]);

        $this->assertSame(
            -1500,
            $positions[$memberId],
            'The unsettled position is the whole carried-forward balance, not the February slice',
        );
    }

    public function test_calculateUnsettledPositions_restricted_to_the_given_members(): void
    {
        $categoryId = $this->createTestCategory('RestrictCategory');
        $productId = $this->createTestProduct($categoryId, 'RestrictProduct', 'mug', 100);
        $wanted = $this->createTestMember('Wanted', 'Member');
        $other = $this->createTestMember('Other', 'Member');

        $this->createTestTransaction($wanted, $productId, 100, 'purchase', '2026-06-01 10:00:00');
        $this->createTestTransaction($other, $productId, 700, 'purchase', '2026-06-01 10:00:00');

        $positions = $this->settlementsRepository->calculateUnsettledPositions([$wanted]);

        $this->assertSame([$wanted => 100], $positions);
    }

    public function test_findParticipantMemberIds_selects_members_with_unsettled_activity_in_the_window(): void
    {
        $categoryId = $this->createTestCategory('ParticipantCategory');
        $productId = $this->createTestProduct($categoryId, 'ParticipantProduct', 'mug', 100);
        $inside = $this->createTestMember('Inside', 'Window');
        $outside = $this->createTestMember('Outside', 'Window');

        $this->createTestTransaction($inside, $productId, 100, 'purchase', '2026-05-15 10:00:00');
        $this->createTestTransaction($outside, $productId, 999, 'purchase', '2026-05-31 10:00:00');

        $participants = $this->settlementsRepository->findParticipantMemberIds('2026-05-10', '2026-05-20');

        $this->assertContains($inside, $participants);
        $this->assertNotContains($outside, $participants);
    }

    public function test_findParticipantMemberIds_can_be_narrowed_to_a_single_member(): void
    {
        $categoryId = $this->createTestCategory('SingleParticipantCategory');
        $productId = $this->createTestProduct($categoryId, 'SingleParticipantProduct', 'mug', 100);
        $wanted = $this->createTestMember('SingleWanted', 'Member');
        $other = $this->createTestMember('SingleOther', 'Member');

        $this->createTestTransaction($wanted, $productId, 100, 'purchase', '2026-07-01 10:00:00');
        $this->createTestTransaction($other, $productId, 100, 'purchase', '2026-07-01 10:00:00');

        $participants = $this->settlementsRepository->findParticipantMemberIds(null, null, $wanted);

        $this->assertSame([$wanted], $participants);
    }

    // ------------------------------------------------------------------
    // findMembersInCredit (#161 work item 3)
    // ------------------------------------------------------------------

    public function test_findMembersInCredit_returns_member_with_net_credit(): void
    {
        $adminId = $this->createTestAdminUser('credit-admin@example.com');
        $memberId = $this->createTestMember('Credit', 'Owed');
        $categoryId = $this->createTestCategory('CreditCategory');
        $productId = $this->createTestProduct($categoryId, 'CreditProduct', 'mug', 2000);

        // A settled €20 purchase, then stornoed (unsettled) — the club owes €20.
        $purchase = $this->createTestTransaction($memberId, $productId, 2000, 'purchase', '2026-01-10 10:00:00');
        $settlementId = $this->createSettlementRow($adminId, '2026-01-31', '2026-02-07', 2000, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $purchase,
            'member_id' => $memberId,
            'amount_cents' => 2000,
        ]);
        $this->createTestTransaction($memberId, $productId, -2000, 'storno', '2026-01-11 10:00:00', $purchase);

        $result = $this->settlementsRepository->findMembersInCredit();

        $entry = $this->findByMemberId($result, $memberId);
        $this->assertNotNull($entry, 'Member with a net credit must appear in the listing');
        $this->assertSame(-2000, $entry['balance_cents']);
        $this->assertSame('Credit', $entry['first_name']);
        $this->assertSame('Owed', $entry['last_name']);
    }

    public function test_findMembersInCredit_excludes_member_whose_storno_nets_to_zero(): void
    {
        $memberId = $this->createTestMember('Zeroed', 'Out');
        $categoryId = $this->createTestCategory('ZeroCategory');
        $productId = $this->createTestProduct($categoryId, 'ZeroProduct', 'mug', 1000);

        // Both purchase and its storno are unsettled — nets to 0, not a credit.
        $purchase = $this->createTestTransaction($memberId, $productId, 1000, 'purchase', '2026-03-01 10:00:00');
        $this->createTestTransaction($memberId, $productId, -1000, 'storno', '2026-03-01 11:00:00', $purchase);

        $result = $this->settlementsRepository->findMembersInCredit();

        $this->assertNull($this->findByMemberId($result, $memberId), 'A position netting to zero is not a credit');
    }

    public function test_findMembersInCredit_excludes_member_who_owes_money(): void
    {
        $memberId = $this->createTestMember('Owes', 'Money');
        $categoryId = $this->createTestCategory('OwesCategory');
        $productId = $this->createTestProduct($categoryId, 'OwesProduct', 'mug', 400);

        $this->createTestTransaction($memberId, $productId, 400, 'purchase', '2026-03-05 10:00:00');

        $result = $this->settlementsRepository->findMembersInCredit();

        $this->assertNull($this->findByMemberId($result, $memberId), 'A positive (owed) position must not be listed as a credit');
    }

    public function test_findMembersInCredit_excludes_settled_transactions(): void
    {
        $adminId = $this->createTestAdminUser('settled-credit-admin@example.com');
        $memberId = $this->createTestMember('AllSettled', 'Member');
        $categoryId = $this->createTestCategory('AllSettledCategory');
        $productId = $this->createTestProduct($categoryId, 'AllSettledProduct', 'mug', 1500);

        // Storno itself is settled too — nothing unsettled remains, so no credit shows.
        $purchase = $this->createTestTransaction($memberId, $productId, 1500, 'purchase', '2026-04-01 10:00:00');
        $storno = $this->createTestTransaction($memberId, $productId, -1500, 'storno', '2026-04-01 11:00:00', $purchase);
        $settlementId = $this->createSettlementRow($adminId, '2026-04-02', '2026-04-05', 0, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $purchase,
            'member_id' => $memberId,
            'amount_cents' => 1500,
        ]);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $storno,
            'member_id' => $memberId,
            'amount_cents' => -1500,
        ]);

        $result = $this->settlementsRepository->findMembersInCredit();

        $this->assertNull($this->findByMemberId($result, $memberId), 'A fully settled position must not be listed');
    }

    public function test_findMembersInCredit_excludes_soft_deleted_members(): void
    {
        $memberId = $this->createTestMember('Deleted', 'Member');
        $categoryId = $this->createTestCategory('DeletedCategory');
        $productId = $this->createTestProduct($categoryId, 'DeletedProduct', 'mug', 1000);

        $purchase = $this->createTestTransaction($memberId, $productId, 1000, 'purchase', '2026-04-10 10:00:00');
        $this->createTestTransaction($memberId, $productId, -1000, 'storno', '2026-04-10 11:00:00', $purchase);

        $this->db->prepare('UPDATE members SET deleted_at = ? WHERE id = ?')
            ->execute(['2026-04-11 00:00:00', $memberId]);

        $result = $this->settlementsRepository->findMembersInCredit();

        $this->assertNull($this->findByMemberId($result, $memberId), 'Soft-deleted members must be excluded from the credit listing');
    }

    public function test_findMembersInCredit_orders_most_negative_first(): void
    {
        $adminId = $this->createTestAdminUser('order-credit-admin@example.com');
        $categoryId = $this->createTestCategory('OrderCategory');
        $productId = $this->createTestProduct($categoryId, 'OrderProduct', 'mug', 100);
        $small = $this->createTestMember('SmallCredit', 'Member');
        $large = $this->createTestMember('LargeCredit', 'Member');

        $smallPurchase = $this->createTestTransaction($small, $productId, 500, 'purchase', '2026-05-01 10:00:00');
        $smallSettlementId = $this->createSettlementRow($adminId, '2026-05-02', '2026-05-05', 500, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $smallSettlementId,
            'transaction_id' => $smallPurchase,
            'member_id' => $small,
            'amount_cents' => 500,
        ]);
        $this->createTestTransaction($small, $productId, -500, 'storno', '2026-05-01 11:00:00', $smallPurchase);

        $largePurchase = $this->createTestTransaction($large, $productId, 3000, 'purchase', '2026-05-01 10:00:00');
        $largeSettlementId = $this->createSettlementRow($adminId, '2026-05-02', '2026-05-05', 3000, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $largeSettlementId,
            'transaction_id' => $largePurchase,
            'member_id' => $large,
            'amount_cents' => 3000,
        ]);
        $this->createTestTransaction($large, $productId, -3000, 'storno', '2026-05-01 11:00:00', $largePurchase);

        $result = $this->settlementsRepository->findMembersInCredit();

        $smallIndex = $this->findIndexByMemberId($result, $small);
        $largeIndex = $this->findIndexByMemberId($result, $large);

        $this->assertNotFalse($smallIndex);
        $this->assertNotFalse($largeIndex);
        $this->assertLessThan($smallIndex, $largeIndex, 'The larger (more negative) credit must sort before the smaller one');
    }

    // ------------------------------------------------------------------
    // create / createItem
    // ------------------------------------------------------------------

    public function test_create_creates_settlement_with_all_fields_intact(): void
    {
        $adminId = $this->createTestAdminUser('create-admin@example.com');
        $id = $this->generateUuid();
        $this->testSettlementIds[] = $id;

        $result = $this->settlementsRepository->create([
            'id' => $id,
            // manual_reason 'cash' maps to method 'bank_transfer' post-migration; a non-direct_debit
            // method requires member_count = 1 (chk_settlements_manual_is_single_member).
            'method' => 'bank_transfer',
            'settlement_date' => '2026-06-01',
            'execution_date' => '2026-06-05',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'sepa_message_id' => null,
            'total_amount_cents' => 12345,
            'member_count' => 1,
            'notes' => 'Test settlement notes',
            'created_by_admin_id' => $adminId,
        ]);

        $this->assertNotNull($result);
        $this->assertEquals($id, $result['id']);
        $this->assertEquals('bank_transfer', $result['method']);
        $this->assertEquals('2026-06-01', $result['settlement_date']);
        $this->assertEquals('2026-06-05', $result['execution_date']);
        $this->assertEquals('2026-05-01', $result['period_start']);
        $this->assertEquals('2026-05-31', $result['period_end']);
        $this->assertEquals(12345, (int) $result['total_amount_cents']);
        $this->assertEquals(1, (int) $result['member_count']);
        $this->assertEquals(0, (int) $result['is_cancelled']);
        $this->assertEquals('Test settlement notes', $result['notes']);
        $this->assertEquals($adminId, $result['created_by_admin_id']);
    }

    public function test_create_generates_id_when_not_provided(): void
    {
        $adminId = $this->createTestAdminUser('genid-admin@example.com');

        $result = $this->settlementsRepository->create([
            'settlement_date' => '2026-06-10',
            'execution_date' => '2026-06-11',
            'total_amount_cents' => 100,
            'member_count' => 1,
            'created_by_admin_id' => $adminId,
        ]);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['id']);
        $this->testSettlementIds[] = $result['id'];
    }

    public function test_createItem_stores_negative_amount_cents_for_corrections(): void
    {
        // Regression guard: settlement_items.amount_cents is a SIGNED BIGINT
        // specifically to allow correction/refund amounts to be settled.
        $adminId = $this->createTestAdminUser('negative-admin@example.com');
        $memberId = $this->createTestMember('Negative', 'Amount');
        $categoryId = $this->createTestCategory('NegativeCategory');
        $productId = $this->createTestProduct($categoryId, 'NegativeProduct', 'mug', 500);
        $purchaseTransactionId = $this->createTestTransaction($memberId, $productId, 500, 'purchase', '2026-06-15 09:00:00');
        $transactionId = $this->createTestTransaction($memberId, $productId, -500, 'storno', '2026-06-15 10:00:00', $purchaseTransactionId);

        // Note: settlements.total_amount_cents is UNSIGNED (unlike settlement_items.amount_cents),
        // so the settlement-level total uses 0 here; only the item-level amount is negative.
        $settlementId = $this->createSettlementRow($adminId, '2026-06-16', '2026-06-17', 0, 1);

        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => -500,
        ]);

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);

        $this->assertCount(1, $items);
        $this->assertEquals(-500, (int) $items[0]['amount_cents'], 'Negative amount_cents must round-trip correctly');
    }

    // ------------------------------------------------------------------
    // cancelSettlement
    // ------------------------------------------------------------------

    public function test_cancelSettlement_marks_cancelled_and_keeps_the_items_it_releases(): void
    {
        $adminId = $this->createTestAdminUser('cancel-admin@example.com');
        $cancellerId = $this->createTestAdminUser('canceller-admin@example.com');
        $memberId = $this->createTestMember('Cancel', 'Member');
        $categoryId = $this->createTestCategory('CancelCategory');
        $productId = $this->createTestProduct($categoryId, 'CancelProduct', 'mug', 250);
        $transactionId = $this->createTestTransaction($memberId, $productId, 250, 'purchase', '2026-06-20 10:00:00');

        $settlementId = $this->createSettlementRow($adminId, '2026-06-21', '2026-06-22', 250, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => 250,
        ]);

        $result = $this->settlementsRepository->cancelSettlement($settlementId, $cancellerId);

        $this->assertTrue($result);

        $settlement = $this->settlementsRepository->findById($settlementId);
        $this->assertEquals(1, (int) $settlement['is_cancelled']);
        $this->assertEquals($cancellerId, $settlement['cancelled_by_admin_id']);
        $this->assertNotNull($settlement['cancelled_at']);

        // Ruling #142 §3: the rows are the record of what this settlement
        // contained, so they survive; only their *claim* on the transaction is
        // released, by nulling active_transaction_id.
        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        $this->assertCount(1, $items, 'A cancelled settlement still records what it contained');
        $this->assertEquals($transactionId, $items[0]['transaction_id']);
        $this->assertNull($items[0]['active_transaction_id'], 'Cancellation releases the claim on the transaction');
    }

    public function test_cancelSettlement_frees_the_transaction_for_a_later_settlement(): void
    {
        // The point of releasing the claim: the transaction is unsettled again
        // and a second settlement may take it — which the UNIQUE index on
        // active_transaction_id would otherwise forbid (#81, #142 §3).
        $adminId = $this->createTestAdminUser('recancel-admin@example.com');
        $memberId = $this->createTestMember('Recancel', 'Member');
        $categoryId = $this->createTestCategory('RecancelCategory');
        $productId = $this->createTestProduct($categoryId, 'RecancelProduct', 'mug', 400);
        $transactionId = $this->createTestTransaction($memberId, $productId, 400, 'purchase', '2026-06-20 10:00:00');

        $firstId = $this->createSettlementRow($adminId, '2026-06-21', '2026-06-22', 400, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $firstId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => 400,
        ]);

        $this->assertNotEmpty(
            $this->settlementsRepository->hasConflicts([$transactionId]),
            'While the first settlement is live the transaction is claimed'
        );

        $this->settlementsRepository->cancelSettlement($firstId, $adminId);

        $this->assertSame(
            [],
            $this->settlementsRepository->hasConflicts([$transactionId]),
            'After cancellation the transaction is unclaimed'
        );

        $secondId = $this->createSettlementRow($adminId, '2026-06-25', '2026-06-26', 400, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $secondId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => 400,
        ]);

        $this->assertCount(1, $this->settlementsRepository->findItemsBySettlementId($secondId));
        $this->assertCount(
            1,
            $this->settlementsRepository->findItemsBySettlementId($firstId),
            'The cancelled settlement keeps its history even after the transaction is re-settled'
        );
    }

    public function test_two_live_settlements_cannot_claim_the_same_transaction(): void
    {
        // The database, not the application, is what makes double-settlement
        // impossible — including under two concurrent runs (#142 §3).
        $adminId = $this->createTestAdminUser('doubleclaim-admin@example.com');
        $memberId = $this->createTestMember('Double', 'Claim');
        $categoryId = $this->createTestCategory('DoubleClaimCategory');
        $productId = $this->createTestProduct($categoryId, 'DoubleClaimProduct', 'mug', 150);
        $transactionId = $this->createTestTransaction($memberId, $productId, 150, 'purchase', '2026-06-20 10:00:00');

        $firstId = $this->createSettlementRow($adminId, '2026-06-21', '2026-06-22', 150, 1);
        $secondId = $this->createSettlementRow($adminId, '2026-06-21', '2026-06-22', 150, 1);

        $item = ['transaction_id' => $transactionId, 'member_id' => $memberId, 'amount_cents' => 150];
        $this->settlementsRepository->createItem($item + ['settlement_id' => $firstId]);

        $this->expectException(\PDOException::class);
        $this->settlementsRepository->createItem($item + ['settlement_id' => $secondId]);
    }

    // ------------------------------------------------------------------
    // markExported
    // ------------------------------------------------------------------

    public function test_markSubmitted_records_the_moment_and_the_admin(): void
    {
        // The point of no return (#142 §1): after this the settlement can only
        // be reversed, so who reported it and when is part of the record.
        $adminId = $this->createTestAdminUser('submit-admin@example.com');
        $submitterId = $this->createTestAdminUser('submitter-admin@example.com');
        $settlementId = $this->createSettlementRow($adminId, '2026-06-21', '2026-06-22', 250, 1);

        $this->assertTrue($this->settlementsRepository->markSubmitted($settlementId, $submitterId));

        $settlement = $this->settlementsRepository->findById($settlementId);
        $this->assertNotNull($settlement['submitted_at']);
        $this->assertEquals($submitterId, $settlement['submitted_by_admin_id']);
    }

    public function test_markExported_sets_exported_at(): void
    {
        $adminId = $this->createTestAdminUser('export-admin@example.com');
        $settlementId = $this->createSettlementRow($adminId, '2026-06-25', '2026-06-26', 100, 1);

        $before = $this->settlementsRepository->findById($settlementId);
        $this->assertNull($before['exported_at']);

        $result = $this->settlementsRepository->markExported($settlementId);

        $this->assertTrue($result);

        $after = $this->settlementsRepository->findById($settlementId);
        $this->assertNotNull($after['exported_at']);
    }

    // ------------------------------------------------------------------
    // listPaginated
    // ------------------------------------------------------------------

    public function test_listPaginated_respects_limit_and_offset(): void
    {
        $adminId = $this->createTestAdminUser('page-admin@example.com');

        $ids = [];
        // Use a tight, unique date range so we only ever see our own rows.
        $dates = ['2027-01-01', '2027-01-02', '2027-01-03'];
        foreach ($dates as $i => $date) {
            $id = $this->createSettlementRow($adminId, $date, $date, 100 * ($i + 1), 1);
            $this->setSettlementCreatedAt($id, "2027-01-0" . ($i + 1) . " 00:00:0" . $i);
            $ids[] = $id;
        }

        $page1 = $this->settlementsRepository->listPaginated(2, 0, null, 'created_at', 'asc', '2027-01-01', '2027-01-03');
        $page2 = $this->settlementsRepository->listPaginated(2, 2, null, 'created_at', 'asc', '2027-01-01', '2027-01-03');

        $this->assertEquals(3, $page1['total']);
        $this->assertCount(2, $page1['items']);
        $this->assertCount(1, $page2['items']);

        $this->assertEquals($ids[0], $page1['items'][0]['id']);
        $this->assertEquals($ids[1], $page1['items'][1]['id']);
        $this->assertEquals($ids[2], $page2['items'][0]['id']);
    }

    public function test_listPaginated_sorts_by_created_at_desc(): void
    {
        $adminId = $this->createTestAdminUser('sort-admin@example.com');

        $idOlder = $this->createSettlementRow($adminId, '2027-02-01', '2027-02-01', 100, 1);
        $this->setSettlementCreatedAt($idOlder, '2027-02-01 00:00:00');

        $idNewer = $this->createSettlementRow($adminId, '2027-02-02', '2027-02-02', 200, 1);
        $this->setSettlementCreatedAt($idNewer, '2027-02-02 00:00:00');

        $result = $this->settlementsRepository->listPaginated(10, 0, null, 'created_at', 'desc', '2027-02-01', '2027-02-02');

        $this->assertEquals(2, $result['total']);
        $this->assertEquals($idNewer, $result['items'][0]['id'], 'DESC order should return the newer settlement first');
        $this->assertEquals($idOlder, $result['items'][1]['id']);
    }

    public function test_listPaginated_sorts_by_created_by(): void
    {
        // The settlements table offers a "Created by" header, and until #120
        // clicking it re-sorted by date: $sortCol was a ternary whose two
        // branches were both 's.created_at'.
        $zoe = $this->createTestAdminUser('sortby-zoe@example.com', 'Zoe Treasurer');
        $ada = $this->createTestAdminUser('sortby-ada@example.com', 'Ada Treasurer');

        // Created in the order Zoe-then-Ada, so a fallback to created_at would
        // put Zoe first and be visibly distinguishable from the name order.
        $zoeSettlement = $this->createSettlementRow($zoe, '2027-03-01', '2027-03-01', 100, 1);
        $this->setSettlementCreatedAt($zoeSettlement, '2027-03-01 00:00:00');

        $adaSettlement = $this->createSettlementRow($ada, '2027-03-02', '2027-03-02', 200, 1);
        $this->setSettlementCreatedAt($adaSettlement, '2027-03-02 00:00:00');

        $result = $this->settlementsRepository->listPaginated(10, 0, null, 'created_by', 'asc', '2027-03-01', '2027-03-02');

        $this->assertEquals($adaSettlement, $result['items'][0]['id'], 'ASC by created_by must order by the admin display name');
        $this->assertEquals($zoeSettlement, $result['items'][1]['id']);
    }

    public function test_listPaginated_falls_back_to_created_at_for_an_unknown_sort_key(): void
    {
        // An unsupported key must not throw or produce arbitrary SQL — it
        // orders by the default column.
        $adminId = $this->createTestAdminUser('sortkey-admin@example.com');

        $idOlder = $this->createSettlementRow($adminId, '2027-03-11', '2027-03-11', 100, 1);
        $this->setSettlementCreatedAt($idOlder, '2027-03-11 00:00:00');

        $idNewer = $this->createSettlementRow($adminId, '2027-03-12', '2027-03-12', 200, 1);
        $this->setSettlementCreatedAt($idNewer, '2027-03-12 00:00:00');

        $result = $this->settlementsRepository->listPaginated(10, 0, null, 'total_amount_cents', 'desc', '2027-03-11', '2027-03-12');

        $this->assertEquals($idNewer, $result['items'][0]['id']);
        $this->assertEquals($idOlder, $result['items'][1]['id']);
    }

    public function test_listPaginated_filters_by_status_active_and_cancelled(): void
    {
        $adminId = $this->createTestAdminUser('status-admin@example.com');

        $activeId = $this->createSettlementRow($adminId, '2027-04-01', '2027-04-01', 100, 1);
        $this->setSettlementCreatedAt($activeId, '2027-04-01 10:00:00');
        $cancelledId = $this->createSettlementRow($adminId, '2027-04-01', '2027-04-01', 200, 1, isCancelled: true);
        $this->setSettlementCreatedAt($cancelledId, '2027-04-01 11:00:00');

        $activeResult = $this->settlementsRepository->listPaginated(50, 0, 'active', 'created_at', 'desc', '2027-04-01', '2027-04-01');
        $cancelledResult = $this->settlementsRepository->listPaginated(50, 0, 'cancelled', 'created_at', 'desc', '2027-04-01', '2027-04-01');

        $activeIds = array_column($activeResult['items'], 'id');
        $cancelledIds = array_column($cancelledResult['items'], 'id');

        $this->assertContains($activeId, $activeIds);
        $this->assertNotContains($cancelledId, $activeIds);

        $this->assertContains($cancelledId, $cancelledIds);
        $this->assertNotContains($activeId, $cancelledIds);
    }

    public function test_listPaginated_filters_by_date_range(): void
    {
        $adminId = $this->createTestAdminUser('range-admin@example.com');

        $insideId = $this->createSettlementRow($adminId, '2027-05-10', '2027-05-10', 100, 1);
        $this->setSettlementCreatedAt($insideId, '2027-05-10 12:00:00');

        $outsideId = $this->createSettlementRow($adminId, '2027-05-20', '2027-05-20', 200, 1);
        $this->setSettlementCreatedAt($outsideId, '2027-05-20 12:00:00');

        $result = $this->settlementsRepository->listPaginated(50, 0, null, 'created_at', 'desc', '2027-05-09', '2027-05-11');

        $ids = array_column($result['items'], 'id');
        $this->assertContains($insideId, $ids);
        $this->assertNotContains($outsideId, $ids);
    }

    public function test_listPaginated_includes_transaction_count_and_date_bounds(): void
    {
        $adminId = $this->createTestAdminUser('txcount-admin@example.com');
        $memberId = $this->createTestMember('TxCount', 'Member');
        $categoryId = $this->createTestCategory('TxCountCategory');
        $productId = $this->createTestProduct($categoryId, 'TxCountProduct', 'mug', 150);

        $tx1 = $this->createTestTransaction($memberId, $productId, 150, 'purchase', '2027-06-01 08:00:00');
        $tx2 = $this->createTestTransaction($memberId, $productId, 150, 'purchase', '2027-06-01 09:00:00');

        $settlementId = $this->createSettlementRow($adminId, '2027-06-02', '2027-06-03', 300, 1);
        $this->setSettlementCreatedAt($settlementId, '2027-06-02 12:00:00');
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId, 'transaction_id' => $tx1, 'member_id' => $memberId, 'amount_cents' => 150,
        ]);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId, 'transaction_id' => $tx2, 'member_id' => $memberId, 'amount_cents' => 150,
        ]);

        $result = $this->settlementsRepository->listPaginated(50, 0, null, 'created_at', 'desc', '2027-06-02', '2027-06-02');

        $items = array_values(array_filter($result['items'], fn ($item) => $item['id'] === $settlementId));
        $this->assertCount(1, $items);
        $this->assertEquals(2, (int) $items[0]['transaction_count']);
        $this->assertEquals('2027-06-01 08:00:00', $items[0]['transaction_date_min']);
        $this->assertEquals('2027-06-01 09:00:00', $items[0]['transaction_date_max']);
    }

    // ------------------------------------------------------------------
    // getNextSepaMessageId
    // ------------------------------------------------------------------

    public function test_getNextSepaMessageId_returns_prefixed_unique_string(): void
    {
        $id1 = $this->settlementsRepository->getNextSepaMessageId();
        $id2 = $this->settlementsRepository->getNextSepaMessageId();

        $this->assertStringStartsWith('SEPA-', $id1);
        $this->assertNotEquals($id1, $id2);
        $this->assertLessThanOrEqual(35, strlen($id1), 'sepa_message_id column is VARCHAR(35)');
    }

    // ------------------------------------------------------------------
    // hasConflicts
    // ------------------------------------------------------------------

    public function test_hasConflicts_returns_empty_array_for_empty_input(): void
    {
        $result = $this->settlementsRepository->hasConflicts([]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function test_hasConflicts_detects_transaction_already_settled_and_excludes_cancelled(): void
    {
        $adminId = $this->createTestAdminUser('conflict-admin@example.com');
        $memberId = $this->createTestMember('Conflict', 'Member');
        $categoryId = $this->createTestCategory('ConflictCategory');
        $productId = $this->createTestProduct($categoryId, 'ConflictProduct', 'mug', 200);

        $settledTxId = $this->createTestTransaction($memberId, $productId, 200, 'purchase', '2026-07-01 10:00:00');
        $settlementId = $this->createSettlementRow($adminId, '2026-07-02', '2026-07-03', 200, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $settlementId, 'transaction_id' => $settledTxId, 'member_id' => $memberId, 'amount_cents' => 200,
        ]);

        $cancelledTxId = $this->createTestTransaction($memberId, $productId, 200, 'purchase', '2026-07-01 11:00:00');
        $cancelledSettlementId = $this->createSettlementRow($adminId, '2026-07-04', '2026-07-05', 200, 1);
        $this->settlementsRepository->createItem([
            'settlement_id' => $cancelledSettlementId, 'transaction_id' => $cancelledTxId, 'member_id' => $memberId, 'amount_cents' => 200,
        ]);
        $this->settlementsRepository->cancelSettlement($cancelledSettlementId, $adminId);

        $unsettledTxId = $this->createTestTransaction($memberId, $productId, 200, 'purchase', '2026-07-01 12:00:00');

        $conflicts = $this->settlementsRepository->hasConflicts([$settledTxId, $cancelledTxId, $unsettledTxId]);

        $conflictTxIds = array_column($conflicts, 'transaction_id');
        $this->assertContains($settledTxId, $conflictTxIds, 'Transaction settled by an active settlement is a conflict');
        $this->assertNotContains($cancelledTxId, $conflictTxIds, 'Transaction settled only by a cancelled settlement is not a conflict');
        $this->assertNotContains($unsettledTxId, $conflictTxIds, 'Never-settled transaction is not a conflict');
    }

    // ------------------------------------------------------------------
    // countPending
    // ------------------------------------------------------------------

    public function test_countPending_excludes_exported_settlements(): void
    {
        $adminId = $this->createTestAdminUser('pending-admin@example.com');
        $before = $this->settlementsRepository->countPending();

        $pendingId = $this->createSettlementRow($adminId, '2026-08-10', '2026-08-11', 100, 1);
        $afterPendingCreated = $this->settlementsRepository->countPending();
        $this->assertEquals($before + 1, $afterPendingCreated, 'Newly created settlement should count as pending (not exported)');

        $this->settlementsRepository->markExported($pendingId);
        $afterExported = $this->settlementsRepository->countPending();

        $this->assertEquals($before, $afterExported, 'Exported settlement must no longer count as pending');
    }

    // ------------------------------------------------------------------
    // Helper methods
    // ------------------------------------------------------------------

    private function createTestMember(string $firstName, string $lastName): string
    {
        $memberId = $this->generateUuid();
        $this->testMemberIds[] = $memberId;

        $stmt = $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $firstName, $lastName, strtolower($firstName) . '-' . substr($memberId, 0, 8) . '@example.com', 'de', 1]);

        return $memberId;
    }

    private function createTestAdminUser(string $email, ?string $displayName = null): string
    {
        $adminId = $this->generateUuid();
        $this->testAdminUserIds[] = $adminId;

        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$adminId, $email, password_hash('test123', PASSWORD_BCRYPT), $displayName, 1]);

        return $adminId;
    }

    private function createTestCategory(string $name): string
    {
        $categoryId = $this->generateUuid();
        $this->testCategoryIds[] = $categoryId;

        $names = json_encode(['de' => $name, 'en' => $name]);

        $stmt = $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, ?)');
        $stmt->execute([$categoryId, $names, 1]);

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

    private function createTestTransaction(string $memberId, string $productId, int $amountCents, string $type, string $createdAt, ?string $relatedTransactionId = null): string
    {
        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;

        if ($relatedTransactionId !== null) {
            $stmt = $this->db->prepare(
                'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, related_transaction_id, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$transactionId, $memberId, $productId, $amountCents, $type, $relatedTransactionId, $createdAt]);

            return $transactionId;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $memberId, $productId, $amountCents, $type, $createdAt]);

        return $transactionId;
    }

    private function createSettlementRow(
        string $adminId,
        string $settlementDate,
        string $executionDate,
        int $totalAmountCents,
        int $memberCount,
        bool $isCancelled = false
    ): string {
        $id = $this->generateUuid();
        $this->testSettlementIds[] = $id;

        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id, is_cancelled) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $settlementDate, $executionDate, $totalAmountCents, $memberCount, $adminId, $isCancelled ? 1 : 0]);

        return $id;
    }

    private function setSettlementCreatedAt(string $settlementId, string $createdAt): void
    {
        $stmt = $this->db->prepare('UPDATE settlements SET created_at = ? WHERE id = ?');
        $stmt->execute([$createdAt, $settlementId]);
    }

    private function findByMemberId(array $rows, string $memberId): ?array
    {
        foreach ($rows as $row) {
            if ($row['member_id'] === $memberId) {
                return $row;
            }
        }
        return null;
    }

    private function findIndexByMemberId(array $rows, string $memberId): int|false
    {
        foreach ($rows as $index => $row) {
            if ($row['member_id'] === $memberId) {
                return $index;
            }
        }
        return false;
    }
}
