<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * Settlement cancellation (#142), reversal (#148) and the single method field
 * (#163), as the schema enforces them.
 */
class SettlementSchemaTest extends SchemaTestCase
{
    public function test_two_live_settlements_cannot_claim_the_same_transaction(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $transactionId = $this->insertPurchase($memberId);

        $first = $this->insertSettlement($adminId);
        $this->insertItem($first, $transactionId, $memberId, ['active_transaction_id' => $transactionId]);

        $second = $this->insertSettlement($adminId);

        $this->assertDatabaseRefuses(
            fn() => $this->insertItem($second, $transactionId, $memberId, [
                'active_transaction_id' => $transactionId,
            ]),
            'two live settlements claiming one transaction is the double debit of #81'
        );
    }

    public function test_a_released_transaction_can_be_claimed_again_while_the_history_survives(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $transactionId = $this->insertPurchase($memberId);

        // A settlement is cancelled by releasing its claim, not by deleting the
        // row: the CSV export of a cancelled settlement must still show what it
        // contained (#142 §3).
        $cancelled = $this->insertSettlement($adminId, ['is_cancelled' => 1]);
        $this->insertItem($cancelled, $transactionId, $memberId, ['active_transaction_id' => null]);

        $alsoCancelled = $this->insertSettlement($adminId, ['is_cancelled' => 1]);
        $this->insertItem($alsoCancelled, $transactionId, $memberId, ['active_transaction_id' => null]);

        $live = $this->insertSettlement($adminId);
        $this->insertItem($live, $transactionId, $memberId, ['active_transaction_id' => $transactionId]);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM settlement_items WHERE transaction_id = ?');
        $stmt->execute([$transactionId]);

        $this->assertSame(
            3,
            (int) $stmt->fetchColumn(),
            'the historical rows must survive alongside the live claim'
        );
    }

    public function test_a_settlement_item_carries_the_end_to_end_id_that_was_sent_to_the_bank(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $transactionId = $this->insertPurchase($memberId);
        $settlementId = $this->insertSettlement($adminId);

        $this->insertItem($settlementId, $transactionId, $memberId, [
            'active_transaction_id' => $transactionId,
            'end_to_end_id' => 'CLUBBAR-2026-08-0001',
        ]);

        $stmt = $this->db->prepare('SELECT end_to_end_id FROM settlement_items WHERE transaction_id = ?');
        $stmt->execute([$transactionId]);

        $this->assertSame(
            'CLUBBAR-2026-08-0001',
            $stmt->fetchColumn(),
            'a bank return quotes EREF+; without the sent identifier it cannot be matched (#149, #150)'
        );
    }

    public function test_a_settlement_records_who_submitted_it_and_when(): void
    {
        $adminId = $this->createAdminUser();
        $settlementId = $this->insertSettlement($adminId, [
            'submitted_at' => '2026-08-07 09:30:00',
            'submitted_by_admin_id' => $adminId,
        ]);

        $row = $this->fetchSettlement($settlementId);

        $this->assertSame('2026-08-07 09:30:00', $row['submitted_at']);
        $this->assertSame($adminId, $row['submitted_by_admin_id']);
    }

    public function test_a_new_settlement_is_a_direct_debit_unless_told_otherwise(): void
    {
        $adminId = $this->createAdminUser();

        $settlementId = $this->insertSettlement($adminId);

        $this->assertSame('direct_debit', $this->fetchSettlement($settlementId)['method']);
    }

    public function test_a_bank_transfer_settlement_covers_exactly_one_member(): void
    {
        $adminId = $this->createAdminUser();

        $settlementId = $this->insertSettlement($adminId, [
            'method' => 'bank_transfer',
            'member_count' => 1,
        ]);
        $this->assertSame('bank_transfer', $this->fetchSettlement($settlementId)['method']);

        $this->assertDatabaseRefuses(
            fn() => $this->insertSettlement($adminId, [
                'method' => 'write_off',
                'member_count' => 4,
            ]),
            'a write-off is a decision about one member, not a batch'
        );
    }

    public function test_manual_reason_is_gone(): void
    {
        $columns = $this->db->query('SHOW COLUMNS FROM settlements')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertNotContains(
            'manual_reason',
            $columns,
            'the unvalidated free string was replaced by the method field; notes carries the free text'
        );
    }

    public function test_a_reversal_records_a_bank_return_once_per_member_and_settlement(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();
        $settlementId = $this->insertSettlement($adminId);

        $this->insertReversal($settlementId, $memberId, $adminId, [
            'reason' => 'bank_return',
            'bank_reference' => 'MS03',
        ]);

        $this->assertDatabaseRefuses(
            fn() => $this->insertReversal($settlementId, $memberId, $adminId),
            'one member is reversed out of one settlement at most once'
        );
    }

    public function test_a_reversal_of_a_different_member_in_the_same_settlement_is_allowed(): void
    {
        $adminId = $this->createAdminUser();
        $settlementId = $this->insertSettlement($adminId);

        $this->insertReversal($settlementId, $this->createMember('One'), $adminId);
        $id = $this->insertReversal($settlementId, $this->createMember('Two'), $adminId);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM settlement_reversals WHERE settlement_id = ?');
        $stmt->execute([$settlementId]);

        $this->assertSame(2, (int) $stmt->fetchColumn());
        $this->assertNotEmpty($id);
    }

    private function insertPurchase(string $memberId, int $amountCents = 350): string
    {
        $id = $this->track('transactions', $this->generateUuid());
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, amount_cents, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $memberId, $amountCents, 'purchase']);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertSettlement(string $adminId, array $overrides = []): string
    {
        $row = array_merge([
            'id' => $this->generateUuid(),
            'settlement_date' => '2026-08-01',
            'execution_date' => '2026-08-05',
            'total_amount_cents' => 350,
            'member_count' => 1,
            'created_by_admin_id' => $adminId,
        ], $overrides);

        $this->track('settlements', $row['id']);
        $this->insertRow('settlements', $row);

        return $row['id'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertItem(
        string $settlementId,
        string $transactionId,
        string $memberId,
        array $overrides = []
    ): void {
        $this->insertRow('settlement_items', array_merge([
            'settlement_id' => $settlementId,
            'transaction_id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => 350,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertReversal(
        string $settlementId,
        string $memberId,
        string $adminId,
        array $overrides = []
    ): string {
        $row = array_merge([
            'id' => $this->generateUuid(),
            'settlement_id' => $settlementId,
            'member_id' => $memberId,
            'reason' => 'bank_return',
            'amount_cents' => 350,
            'created_by_admin_id' => $adminId,
        ], $overrides);

        $this->track('settlement_reversals', $row['id']);
        $this->insertRow('settlement_reversals', $row);

        return $row['id'];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertRow(string $table, array $row): void
    {
        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $this->db->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSettlement(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM settlements WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }
}
