<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Sync;

use App\Modules\Transactions\Sync\TerminalTransactionAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * #79 / ruling #144 §3: the sync path builds its insert from an explicit
 * allowlist. A terminal token owns the sale — id, member, product, amount,
 * time, dispenser telemetry — and nothing else. Every field that decides what
 * a row *means* to the ledger is the server's.
 */
class TerminalTransactionAllowlistTest extends TestCase
{
    private const TERMINAL_ID = '11111111-1111-4111-8111-111111111111';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => '22222222-2222-4222-8222-222222222222',
            'member_id' => '33333333-3333-4333-8333-333333333333',
            'product_id' => '44444444-4444-4444-8444-444444444444',
            'amount_cents' => 350,
            'created_at' => '2026-08-08T18:00:00Z',
        ], $overrides);
    }

    public function test_it_keeps_the_fields_the_terminal_owns(): void
    {
        $row = TerminalTransactionAllowlist::build($this->payload(), self::TERMINAL_ID);

        $this->assertSame('22222222-2222-4222-8222-222222222222', $row['id']);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $row['member_id']);
        $this->assertSame('44444444-4444-4444-8444-444444444444', $row['product_id']);
        $this->assertSame(350, $row['amount_cents']);
        $this->assertSame('2026-08-08T18:00:00Z', $row['created_at']);
    }

    public function test_it_keeps_dispenser_telemetry_which_is_terminal_hardware(): void
    {
        $row = TerminalTransactionAllowlist::build($this->payload([
            'dispenser_tx_id' => 'DISP-001',
            'dispenser_requested' => 500,
            'dispenser_actual' => 495,
        ]), self::TERMINAL_ID);

        $this->assertSame('DISP-001', $row['dispenser_tx_id']);
        $this->assertSame(500, $row['dispenser_requested']);
        $this->assertSame(495, $row['dispenser_actual']);
    }

    public function test_a_forged_transaction_type_is_replaced_by_purchase(): void
    {
        $row = TerminalTransactionAllowlist::build(
            $this->payload(['transaction_type' => 'storno']),
            self::TERMINAL_ID,
        );

        $this->assertSame('purchase', $row['transaction_type']);
    }

    public function test_transaction_type_is_purchase_even_when_the_client_omits_it(): void
    {
        $row = TerminalTransactionAllowlist::build($this->payload(), self::TERMINAL_ID);

        $this->assertSame('purchase', $row['transaction_type']);
    }

    /**
     * The denial-of-correction guard. #169 made the UNIQUE index on
     * related_transaction_id the arbiter of "stornoable at most once", so a
     * forged link would consume the slot and make the genuine storno of that
     * purchase impossible forever.
     */
    public function test_a_supplied_related_transaction_id_is_forced_to_null(): void
    {
        $row = TerminalTransactionAllowlist::build(
            $this->payload(['related_transaction_id' => '55555555-5555-4555-8555-555555555555']),
            self::TERMINAL_ID,
        );

        $this->assertArrayHasKey('related_transaction_id', $row);
        $this->assertNull($row['related_transaction_id']);
    }

    public function test_a_supplied_created_by_admin_id_is_forced_to_null(): void
    {
        $row = TerminalTransactionAllowlist::build(
            $this->payload(['created_by_admin_id' => '66666666-6666-4666-8666-666666666666']),
            self::TERMINAL_ID,
        );

        $this->assertArrayHasKey('created_by_admin_id', $row);
        $this->assertNull($row['created_by_admin_id']);
    }

    public function test_the_terminal_id_comes_from_the_auth_token_not_the_payload(): void
    {
        $row = TerminalTransactionAllowlist::build(
            $this->payload(['created_by_terminal_id' => '99999999-9999-4999-8999-999999999999']),
            self::TERMINAL_ID,
        );

        $this->assertSame(self::TERMINAL_ID, $row['created_by_terminal_id']);
    }

    public function test_an_unknown_field_never_reaches_the_insert(): void
    {
        $row = TerminalTransactionAllowlist::build(
            $this->payload(['notes' => 'sneaky', 'received_at' => '1999-01-01T00:00:00Z', 'whatever' => 1]),
            self::TERMINAL_ID,
        );

        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('received_at', $row);
        $this->assertArrayNotHasKey('whatever', $row);
    }

    public function test_absent_optional_fields_stay_absent(): void
    {
        $row = TerminalTransactionAllowlist::build($this->payload(), self::TERMINAL_ID);

        $this->assertArrayNotHasKey('dispenser_tx_id', $row);
        $this->assertArrayNotHasKey('dispenser_requested', $row);
        $this->assertArrayNotHasKey('dispenser_actual', $row);
    }

    public function test_an_unauthenticated_batch_stores_no_terminal_id(): void
    {
        $row = TerminalTransactionAllowlist::build($this->payload(), null);

        $this->assertNull($row['created_by_terminal_id']);
    }
}
