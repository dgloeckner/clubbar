<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * Timestamp authority (#144) and the storno contract (#158, CONTEXT.md) as the
 * `transactions` table enforces them.
 */
class TransactionSchemaTest extends SchemaTestCase
{
    public function test_occurred_at_is_terminal_owned_and_received_at_is_stamped_by_the_server(): void
    {
        $memberId = $this->createMember();
        $soldYesterday = date('Y-m-d H:i:s', strtotime('-1 day'));

        $id = $this->insertTransaction($memberId, ['occurred_at' => $soldYesterday]);

        $row = $this->fetchTransaction($id);

        $this->assertSame(
            $soldYesterday,
            $row['occurred_at'],
            'occurred_at must keep the time the terminal reported the sale'
        );
        $this->assertEqualsWithDelta(
            time(),
            strtotime($row['received_at']),
            60,
            'received_at must be stamped by the server when the row arrives, not backdated with it'
        );
    }

    public function test_a_storno_must_name_the_transaction_it_reverses(): void
    {
        $memberId = $this->createMember();

        $this->assertDatabaseRefuses(
            fn() => $this->insertTransaction($memberId, [
                'amount_cents' => -350,
                'transaction_type' => 'storno',
                'related_transaction_id' => null,
            ]),
            'GoBD Rz. 64 makes the reversal-to-original linkage a legal requirement, '
                . 'so an unlinked storno must not be storable at all'
        );
    }

    public function test_a_purchase_needs_no_linkage(): void
    {
        $memberId = $this->createMember();

        $id = $this->insertTransaction($memberId, ['related_transaction_id' => null]);

        $this->assertNull($this->fetchTransaction($id)['related_transaction_id']);
    }

    public function test_a_transaction_can_be_stornoed_at_most_once(): void
    {
        $memberId = $this->createMember();
        $purchaseId = $this->insertTransaction($memberId);

        $this->insertTransaction($memberId, [
            'amount_cents' => -350,
            'transaction_type' => 'storno',
            'related_transaction_id' => $purchaseId,
        ]);

        $this->assertDatabaseRefuses(
            fn() => $this->insertTransaction($memberId, [
                'amount_cents' => -350,
                'transaction_type' => 'storno',
                'related_transaction_id' => $purchaseId,
            ]),
            'a second storno of the same purchase would double the credit'
        );
    }

    public function test_payout_is_a_transaction_type(): void
    {
        $memberId = $this->createMember();

        $id = $this->insertTransaction($memberId, [
            'amount_cents' => -1250,
            'transaction_type' => 'payout',
        ]);

        $this->assertSame('payout', $this->fetchTransaction($id)['transaction_type']);
    }

    public function test_correction_is_no_longer_a_transaction_type(): void
    {
        $memberId = $this->createMember();

        $this->assertDatabaseRefuses(
            fn() => $this->insertTransaction($memberId, [
                'amount_cents' => -350,
                'transaction_type' => 'correction',
            ]),
            'the free-amount correction was replaced by the storno; the old value must not survive'
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertTransaction(string $memberId, array $overrides = []): string
    {
        $row = array_merge([
            'id' => $this->generateUuid(),
            'member_id' => $memberId,
            'amount_cents' => 350,
            'transaction_type' => 'purchase',
        ], $overrides);

        $this->track('transactions', $row['id']);

        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $this->db->prepare("INSERT INTO transactions ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));

        return $row['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTransaction(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }
}
