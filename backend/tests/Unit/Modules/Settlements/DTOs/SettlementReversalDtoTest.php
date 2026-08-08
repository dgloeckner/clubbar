<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\CollectionHoldDto;
use App\Modules\Settlements\DTOs\SettlementReversalDto;
use App\Modules\Settlements\Enums\ReversalReason;
use PHPUnit\Framework\TestCase;

/**
 * The two shapes #196 puts on the wire.
 */
class SettlementReversalDtoTest extends TestCase
{
    public function test_a_reversal_row_becomes_the_documented_response_shape(): void
    {
        $dto = SettlementReversalDto::fromRow([
            'id' => 'rev-1',
            'settlement_id' => 'settlement-1',
            'member_id' => 'member-1',
            'reason' => 'bank_return',
            'amount_cents' => 2550,
            'bank_reference' => 'RET-1',
            'notes' => 'Returned unpaid',
            'created_by_admin_id' => 'admin-1',
            'created_at' => '2026-08-08 12:00:00',
            'first_name' => 'Alice',
            'last_name' => 'Member',
            'admin_display_name' => 'The Treasurer',
        ]);

        $this->assertSame(ReversalReason::BANK_RETURN, $dto->reason);

        $array = $dto->toArray();
        $this->assertSame('bank_return', $array['reason']);
        $this->assertSame('Returned by the bank', $array['reason_label']);
        $this->assertSame(2550, $array['amount_cents']);
        $this->assertSame(25.5, $array['amount_eur']);
        $this->assertSame('Alice', $array['first_name']);
        $this->assertSame('The Treasurer', $array['created_by_admin_name']);
        $this->assertStringStartsWith('2026-08-08T12:00:00', $array['created_at']);
    }

    public function test_a_reversal_without_a_bank_reference_or_notes_keeps_them_null(): void
    {
        // A club error has no return booking to quote.
        $dto = SettlementReversalDto::fromRow([
            'id' => 'rev-2',
            'settlement_id' => 'settlement-1',
            'member_id' => 'member-1',
            'reason' => 'club_error',
            'amount_cents' => 500,
            'created_by_admin_id' => 'admin-1',
            'created_at' => '2026-08-08 12:00:00',
        ]);

        $array = $dto->toArray();
        $this->assertNull($array['bank_reference']);
        $this->assertNull($array['notes']);
        $this->assertNull($array['first_name']);
        $this->assertSame('Club error', $array['reason_label']);
    }

    public function test_a_hold_row_becomes_the_documented_response_shape(): void
    {
        $dto = CollectionHoldDto::fromRow([
            'member_id' => 'member-1',
            'first_name' => 'Alice',
            'last_name' => 'Member',
            'collection_hold_reason' => 'Direct debit returned by the bank',
            'held_at' => '2026-08-08 10:00:00',
            'held_by_admin_id' => 'admin-1',
            'admin_display_name' => 'The Treasurer',
            'balance_cents' => 1500,
        ]);

        $array = $dto->toArray();
        $this->assertSame('member-1', $array['member_id']);
        $this->assertSame('Direct debit returned by the bank', $array['collection_hold_reason']);
        $this->assertSame(1500, $array['balance_cents']);
        $this->assertSame('The Treasurer', $array['held_by_admin_name']);
        $this->assertStringStartsWith('2026-08-08T10:00:00', $array['held_at']);
    }

    public function test_a_hold_row_keyed_by_id_rather_than_member_id_is_accepted(): void
    {
        // The member row itself is the other source of this DTO.
        $dto = CollectionHoldDto::fromRow([
            'id' => 'member-9',
            'first_name' => 'Bob',
            'last_name' => 'Member',
        ]);

        $this->assertSame('member-9', $dto->memberId);
        $this->assertNull($dto->reason);
        $this->assertSame(0, $dto->balanceCents);
    }
}
