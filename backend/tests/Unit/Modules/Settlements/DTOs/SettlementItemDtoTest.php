<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\SettlementItemDto;
use PHPUnit\Framework\TestCase;

class SettlementItemDtoTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        // Arrange & Act
        $dto = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'transaction-456',
            memberId: 'member-789',
            memberName: 'John Doe',
            amountCents: 5000,
            transactionType: 'purchase',
            notes: 'Test notes',
            productName: 'Beer',
            transactionCreatedAt: '2026-08-07 10:30:00',
        );

        // Assert
        $this->assertSame('settlement-123', $dto->settlementId);
        $this->assertSame('transaction-456', $dto->transactionId);
        $this->assertSame('member-789', $dto->memberId);
        $this->assertSame('John Doe', $dto->memberName);
        $this->assertSame(5000, $dto->amountCents);
        $this->assertSame('purchase', $dto->transactionType);
        $this->assertSame('Test notes', $dto->notes);
        $this->assertSame('Beer', $dto->productName);
        $this->assertSame('2026-08-07 10:30:00', $dto->transactionCreatedAt);
    }

    public function test_constructor_allows_nullable_fields(): void
    {
        // Arrange & Act
        $dto = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'transaction-456',
            memberId: 'member-789',
            memberName: null,
            amountCents: 1000,
            transactionType: null,
            notes: null,
            productName: null,
            transactionCreatedAt: null,
        );

        // Assert
        $this->assertNull($dto->memberName);
        $this->assertNull($dto->transactionType);
        $this->assertNull($dto->notes);
        $this->assertNull($dto->productName);
        $this->assertNull($dto->transactionCreatedAt);
    }

    public function test_from_row_with_complete_data_including_german_product_name(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'amount_cents' => '5000',
            'transaction_type' => 'purchase',
            'transaction_notes' => 'Test notes',
            'product_names' => json_encode(['de' => 'Bier', 'en' => 'Beer']),
            'transaction_created_at' => '2026-08-07 10:30:00',
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertSame('settlement-123', $dto->settlementId);
        $this->assertSame('transaction-456', $dto->transactionId);
        $this->assertSame('member-789', $dto->memberId);
        $this->assertSame('John Doe', $dto->memberName);
        $this->assertSame(5000, $dto->amountCents);
        $this->assertSame('purchase', $dto->transactionType);
        $this->assertSame('Test notes', $dto->notes);
        $this->assertSame('Bier', $dto->productName);
        $this->assertSame('2026-08-07 10:30:00', $dto->transactionCreatedAt);
    }

    public function test_from_row_prefers_german_product_name_over_english(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'amount_cents' => '2000',
            'transaction_type' => 'refund',
            'transaction_notes' => null,
            'product_names' => json_encode(['en' => 'Beer', 'de' => 'Bier', 'fr' => 'Bière']),
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertSame('Bier', $dto->productName);
    }

    public function test_from_row_falls_back_to_english_when_german_not_available(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'Alice',
            'last_name' => 'Brown',
            'amount_cents' => '3000',
            'transaction_type' => 'purchase',
            'transaction_notes' => null,
            'product_names' => json_encode(['en' => 'Wine', 'fr' => 'Vin']),
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertSame('Wine', $dto->productName);
    }

    public function test_from_row_uses_first_language_when_preferred_languages_unavailable(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'amount_cents' => '1500',
            'transaction_type' => 'purchase',
            'transaction_notes' => null,
            'product_names' => json_encode(['fr' => 'Eau']),
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertSame('Eau', $dto->productName);
    }

    public function test_from_row_handles_empty_product_names(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'Charlie',
            'last_name' => 'Davis',
            'amount_cents' => '2500',
            'transaction_type' => 'purchase',
            'transaction_notes' => null,
            'product_names' => '{}',
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertNull($dto->productName);
    }

    public function test_from_row_handles_missing_product_names(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'Diana',
            'last_name' => 'Evans',
            'amount_cents' => '2500',
            'transaction_type' => 'purchase',
            'transaction_notes' => null,
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertNull($dto->productName);
    }

    public function test_from_row_builds_member_name_from_first_and_last(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'Emma',
            'last_name' => 'Fletcher',
            'amount_cents' => '1000',
            'transaction_type' => 'purchase',
            'transaction_notes' => null,
            'product_names' => null,
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertSame('Emma Fletcher', $dto->memberName);
    }

    public function test_from_row_handles_missing_member_name_fields(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'amount_cents' => '1000',
            'transaction_type' => 'purchase',
            'transaction_notes' => null,
            'product_names' => null,
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertNull($dto->memberName);
    }

    public function test_from_row_handles_missing_last_name(): void
    {
        // Arrange
        $row = [
            'settlement_id' => 'settlement-123',
            'transaction_id' => 'transaction-456',
            'member_id' => 'member-789',
            'first_name' => 'Frank',
            'amount_cents' => '1000',
            'transaction_type' => 'purchase',
            'transaction_notes' => null,
            'product_names' => null,
            'transaction_created_at' => null,
        ];

        // Act
        $dto = SettlementItemDto::fromRow($row);

        // Assert
        $this->assertSame('Frank ', $dto->memberName);
    }

    public function test_to_array_returns_correct_keys_and_values(): void
    {
        // Arrange
        $dto = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'transaction-456',
            memberId: 'member-789',
            memberName: 'John Doe',
            amountCents: 5000,
            transactionType: 'purchase',
            notes: 'Test notes',
            productName: 'Beer',
            transactionCreatedAt: '2026-08-07 10:30:00',
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertArrayHasKey('settlement_id', $array);
        $this->assertArrayHasKey('transaction_id', $array);
        $this->assertArrayHasKey('member_id', $array);
        $this->assertArrayHasKey('member_name', $array);
        $this->assertArrayHasKey('amount_cents', $array);
        $this->assertArrayHasKey('amount_eur', $array);
        $this->assertArrayHasKey('transaction_type', $array);
        $this->assertArrayHasKey('product_name', $array);
        $this->assertArrayHasKey('notes', $array);
        $this->assertArrayHasKey('transaction_date', $array);

        $this->assertSame('settlement-123', $array['settlement_id']);
        $this->assertSame('transaction-456', $array['transaction_id']);
        $this->assertSame('member-789', $array['member_id']);
        $this->assertSame('John Doe', $array['member_name']);
        $this->assertSame(5000, $array['amount_cents']);
        $this->assertSame(50.0, $array['amount_eur']);
        $this->assertSame('purchase', $array['transaction_type']);
        $this->assertSame('Beer', $array['product_name']);
        $this->assertSame('Test notes', $array['notes']);
    }

    public function test_to_array_converts_amount_cents_to_eur(): void
    {
        // Arrange
        $dto = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'transaction-456',
            memberId: 'member-789',
            memberName: null,
            amountCents: 12345,
            transactionType: null,
            notes: null,
            productName: null,
            transactionCreatedAt: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame(12345, $array['amount_cents']);
        $this->assertSame(123.45, $array['amount_eur']);
    }

    public function test_to_array_handles_zero_amount(): void
    {
        // Arrange
        $dto = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'transaction-456',
            memberId: 'member-789',
            memberName: null,
            amountCents: 0,
            transactionType: null,
            notes: null,
            productName: null,
            transactionCreatedAt: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame(0, $array['amount_cents']);
        $this->assertSame(0.0, $array['amount_eur']);
    }

    public function test_to_array_handles_negative_amount(): void
    {
        // Arrange
        $dto = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'transaction-456',
            memberId: 'member-789',
            memberName: null,
            amountCents: -5000,
            transactionType: null,
            notes: null,
            productName: null,
            transactionCreatedAt: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame(-5000, $array['amount_cents']);
        $this->assertSame(-50.0, $array['amount_eur']);
    }

    public function test_to_array_with_null_transaction_date(): void
    {
        // Arrange
        $dto = new SettlementItemDto(
            settlementId: 'settlement-123',
            transactionId: 'transaction-456',
            memberId: 'member-789',
            memberName: null,
            amountCents: 1000,
            transactionType: null,
            notes: null,
            productName: null,
            transactionCreatedAt: null,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertNull($array['transaction_date']);
    }
}
