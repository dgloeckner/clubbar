<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\DTOs\SettlementItemDto;
use PHPUnit\Framework\TestCase;

class SettlementDtoTest extends TestCase
{
    public function test_constructor_sets_all_required_properties(): void
    {
        // Arrange & Act
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: '2026-08-01',
            periodEnd: '2026-08-07',
            sepaMessageId: 'msg-123',
            totalAmountCents: 50000,
            memberCount: 5,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: 'Test settlement',
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: 'admin-123',
            createdByAdminName: 'John Admin',
        );

        // Assert
        $this->assertSame('settlement-123', $dto->id);
        $this->assertNull($dto->manualReason);
        $this->assertSame('2026-08-07', $dto->settlementDate);
        $this->assertSame('2026-08-10', $dto->executionDate);
        $this->assertSame('2026-08-01', $dto->periodStart);
        $this->assertSame('2026-08-07', $dto->periodEnd);
        $this->assertSame('msg-123', $dto->sepaMessageId);
        $this->assertSame(50000, $dto->totalAmountCents);
        $this->assertSame(5, $dto->memberCount);
        $this->assertFalse($dto->isCancelled);
        $this->assertNull($dto->cancelledAt);
        $this->assertNull($dto->exportedAt);
        $this->assertSame('Test settlement', $dto->notes);
        $this->assertEmpty($dto->items);
        $this->assertSame('2026-08-07 10:00:00', $dto->createdAt);
        $this->assertSame('admin-123', $dto->createdByAdminId);
        $this->assertSame('John Admin', $dto->createdByAdminName);
    }

    public function test_constructor_sets_optional_properties_with_defaults(): void
    {
        // Arrange & Act
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 10000,
            memberCount: 2,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );

        // Assert
        $this->assertSame(0, $dto->transactionCount);
        $this->assertNull($dto->transactionDateMin);
        $this->assertNull($dto->transactionDateMax);
    }

    public function test_constructor_allows_custom_transaction_count_and_dates(): void
    {
        // Arrange & Act
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 10000,
            memberCount: 2,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
            transactionCount: 10,
            transactionDateMin: '2026-08-01',
            transactionDateMax: '2026-08-07',
        );

        // Assert
        $this->assertSame(10, $dto->transactionCount);
        $this->assertSame('2026-08-01', $dto->transactionDateMin);
        $this->assertSame('2026-08-07', $dto->transactionDateMax);
    }

    public function test_from_row_with_complete_data(): void
    {
        // Arrange
        $row = [
            'id' => 'settlement-123',
            'manual_reason' => 'cash',
            'settlement_date' => '2026-08-07',
            'execution_date' => '2026-08-10',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-07',
            'sepa_message_id' => 'msg-123',
            'total_amount_cents' => 50000,
            'member_count' => 5,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => 'Test settlement',
            'created_at' => '2026-08-07 10:00:00',
            'created_by_admin_id' => 'admin-123',
            'admin_display_name' => 'John Admin',
            'transaction_count' => 10,
            'transaction_date_min' => '2026-08-01',
            'transaction_date_max' => '2026-08-07',
        ];

        // Act
        $dto = SettlementDto::fromRow($row);

        // Assert
        $this->assertSame('settlement-123', $dto->id);
        $this->assertSame('cash', $dto->manualReason);
        $this->assertSame('2026-08-07', $dto->settlementDate);
        $this->assertSame('2026-08-10', $dto->executionDate);
        $this->assertSame('2026-08-01', $dto->periodStart);
        $this->assertSame('2026-08-07', $dto->periodEnd);
        $this->assertSame('msg-123', $dto->sepaMessageId);
        $this->assertSame(50000, $dto->totalAmountCents);
        $this->assertSame(5, $dto->memberCount);
        $this->assertFalse($dto->isCancelled);
        $this->assertNull($dto->cancelledAt);
        $this->assertNull($dto->exportedAt);
        $this->assertSame('Test settlement', $dto->notes);
        $this->assertEmpty($dto->items);
        $this->assertSame('2026-08-07 10:00:00', $dto->createdAt);
        $this->assertSame('admin-123', $dto->createdByAdminId);
        $this->assertSame('John Admin', $dto->createdByAdminName);
        $this->assertSame(10, $dto->transactionCount);
        $this->assertSame('2026-08-01', $dto->transactionDateMin);
        $this->assertSame('2026-08-07', $dto->transactionDateMax);
    }

    public function test_from_row_with_minimal_data(): void
    {
        // Arrange
        $row = [
            'id' => 'settlement-456',
            'settlement_date' => '2026-08-07',
            'execution_date' => '2026-08-10',
            'total_amount_cents' => 10000,
            'member_count' => 2,
            'is_cancelled' => 1,
            'created_at' => '2026-08-07 10:00:00',
        ];

        // Act
        $dto = SettlementDto::fromRow($row);

        // Assert
        $this->assertSame('settlement-456', $dto->id);
        $this->assertNull($dto->manualReason);
        $this->assertSame('2026-08-07', $dto->settlementDate);
        $this->assertSame('2026-08-10', $dto->executionDate);
        $this->assertNull($dto->periodStart);
        $this->assertNull($dto->periodEnd);
        $this->assertNull($dto->sepaMessageId);
        $this->assertSame(10000, $dto->totalAmountCents);
        $this->assertSame(2, $dto->memberCount);
        $this->assertTrue($dto->isCancelled);
        $this->assertNull($dto->cancelledAt);
        $this->assertNull($dto->exportedAt);
        $this->assertNull($dto->notes);
        $this->assertEmpty($dto->items);
        $this->assertSame('2026-08-07 10:00:00', $dto->createdAt);
        $this->assertNull($dto->createdByAdminId);
        $this->assertNull($dto->createdByAdminName);
        $this->assertSame(0, $dto->transactionCount);
        $this->assertNull($dto->transactionDateMin);
        $this->assertNull($dto->transactionDateMax);
    }

    public function test_from_row_converts_is_cancelled_to_boolean(): void
    {
        // Arrange
        $row = [
            'id' => 'settlement-123',
            'settlement_date' => '2026-08-07',
            'execution_date' => '2026-08-10',
            'total_amount_cents' => 10000,
            'member_count' => 2,
            'is_cancelled' => 0,
            'created_at' => '2026-08-07 10:00:00',
        ];

        // Act
        $dto = SettlementDto::fromRow($row);

        // Assert
        $this->assertIsBool($dto->isCancelled);
        $this->assertFalse($dto->isCancelled);
    }

    public function test_from_row_converts_amounts_to_integers(): void
    {
        // Arrange
        $row = [
            'id' => 'settlement-123',
            'settlement_date' => '2026-08-07',
            'execution_date' => '2026-08-10',
            'total_amount_cents' => '50000',
            'member_count' => '5',
            'is_cancelled' => 0,
            'created_at' => '2026-08-07 10:00:00',
            'transaction_count' => '10',
        ];

        // Act
        $dto = SettlementDto::fromRow($row);

        // Assert
        $this->assertIsInt($dto->totalAmountCents);
        $this->assertIsInt($dto->memberCount);
        $this->assertIsInt($dto->transactionCount);
        $this->assertSame(50000, $dto->totalAmountCents);
        $this->assertSame(5, $dto->memberCount);
        $this->assertSame(10, $dto->transactionCount);
    }

    public function test_from_row_with_items_array(): void
    {
        // Arrange
        $item1 = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'tx-1',
            memberId: 'member-1',
            memberName: 'Member One',
            amountCents: 2500,
            transactionType: 'purchase',
            notes: null,
            productName: 'Beer',
            transactionCreatedAt: null,
        );

        $item2 = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'tx-2',
            memberId: 'member-2',
            memberName: 'Member Two',
            amountCents: 2500,
            transactionType: 'purchase',
            notes: null,
            productName: 'Wine',
            transactionCreatedAt: null,
        );

        $row = [
            'id' => 'settlement-123',
            'settlement_date' => '2026-08-07',
            'execution_date' => '2026-08-10',
            'total_amount_cents' => 5000,
            'member_count' => 2,
            'is_cancelled' => 0,
            'created_at' => '2026-08-07 10:00:00',
        ];

        // Act
        $dto = SettlementDto::fromRow($row, items: [$item1, $item2]);

        // Assert
        $this->assertCount(2, $dto->items);
        $this->assertSame($item1, $dto->items[0]);
        $this->assertSame($item2, $dto->items[1]);
    }

    public function test_to_array_returns_correct_keys(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: 'cash',
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: '2026-08-01',
            periodEnd: '2026-08-07',
            sepaMessageId: 'msg-123',
            totalAmountCents: 50000,
            memberCount: 5,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: 'Test settlement',
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: 'admin-123',
            createdByAdminName: 'John Admin',
            transactionCount: 10,
            transactionDateMin: '2026-08-01',
            transactionDateMax: '2026-08-07',
        );

        // Act
        $array = $dto->toArray();

        // Assert - verify all expected keys exist
        $expectedKeys = [
            'id',
            'manual_reason',
            'settlement_date',
            'execution_date',
            'period_start',
            'period_end',
            'sepa_message_id',
            'total_amount_cents',
            'total_amount_eur',
            'member_count',
            'is_cancelled',
            'cancelled_at',
            'exported_at',
            'notes',
            'items',
            'created_at',
            'created_by_admin_id',
            'created_by_admin_name',
            'transaction_count',
            'transaction_date_min',
            'transaction_date_max',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Missing key: $key");
        }
    }

    public function test_to_array_converts_amount_cents_to_eur(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 123456,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame(123456, $array['total_amount_cents']);
        $this->assertSame(1234.56, $array['total_amount_eur']);
    }

    public function test_to_array_with_zero_amount(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 0,
            memberCount: 0,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame(0, $array['total_amount_cents']);
        $this->assertSame(0.0, $array['total_amount_eur']);
    }

    public function test_to_array_with_negative_amount(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: -50000,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame(-50000, $array['total_amount_cents']);
        $this->assertSame(-500.0, $array['total_amount_eur']);
    }

    public function test_to_array_serializes_nested_items(): void
    {
        // Arrange
        $item = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'tx-1',
            memberId: 'member-1',
            memberName: 'Member One',
            amountCents: 2500,
            transactionType: 'purchase',
            notes: null,
            productName: 'Beer',
            transactionCreatedAt: null,
        );

        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 2500,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [$item],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertIsArray($array['items']);
        $this->assertCount(1, $array['items']);
        $this->assertArrayHasKey('settlement_id', $array['items'][0]);
        $this->assertSame('settlement-123', $array['items'][0]['settlement_id']);
    }

    public function test_to_array_with_null_dates(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 1000,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
            transactionDateMin: null,
            transactionDateMax: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertNull($array['period_start']);
        $this->assertNull($array['period_end']);
        $this->assertNull($array['cancelled_at']);
        $this->assertNull($array['exported_at']);
        $this->assertNull($array['transaction_date_min']);
        $this->assertNull($array['transaction_date_max']);
    }

    public function test_to_array_with_cancelled_settlement(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 5000,
            memberCount: 1,
            isCancelled: true,
            cancelledAt: '2026-08-08 15:30:00',
            exportedAt: null,
            notes: 'Cancelled settlement',
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: 'admin-123',
            createdByAdminName: 'John Admin',
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertTrue($array['is_cancelled']);
        $this->assertNotNull($array['cancelled_at']);
    }

    public function test_to_array_with_exported_settlement(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: 'msg-123',
            totalAmountCents: 5000,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: '2026-08-08 12:00:00',
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertNotNull($array['exported_at']);
        $this->assertSame('msg-123', $array['sepa_message_id']);
    }

    public function test_to_array_preserves_manual_reason(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: 'write_off',
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 5000,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame('write_off', $array['manual_reason']);
    }

    public function test_to_array_with_admin_info(): void
    {
        // Arrange
        $dto = new SettlementDto(
            id: 'settlement-123',
            manualReason: null,
            settlementDate: '2026-08-07',
            executionDate: '2026-08-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 5000,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-08-07 10:00:00',
            createdByAdminId: 'admin-456',
            createdByAdminName: 'Jane Doe',
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame('admin-456', $array['created_by_admin_id']);
        $this->assertSame('Jane Doe', $array['created_by_admin_name']);
    }
}
