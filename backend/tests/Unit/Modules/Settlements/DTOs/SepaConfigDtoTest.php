<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\SepaConfigDto;
use PHPUnit\Framework\TestCase;

class SepaConfigDtoTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        // Arrange & Act
        $dto = new SepaConfigDto(
            creditorId: 'DE89ZZZ09999999999',
            creditorName: 'My Club',
            creditorIban: 'DE89370400440532013000',
            creditorAddressStreet: '123 Main St',
            creditorAddressCity: 'Berlin',
            creditorAddressCountry: 'DE',
            paymentReferencePrefix: 'CLUB',
            isConfigured: true,
        );

        // Assert
        $this->assertSame('DE89ZZZ09999999999', $dto->creditorId);
        $this->assertSame('My Club', $dto->creditorName);
        $this->assertSame('DE89370400440532013000', $dto->creditorIban);
        $this->assertSame('123 Main St', $dto->creditorAddressStreet);
        $this->assertSame('Berlin', $dto->creditorAddressCity);
        $this->assertSame('DE', $dto->creditorAddressCountry);
        $this->assertSame('CLUB', $dto->paymentReferencePrefix);
        $this->assertTrue($dto->isConfigured);
    }

    public function test_constructor_allows_null_values(): void
    {
        // Arrange & Act
        $dto = new SepaConfigDto(
            creditorId: null,
            creditorName: null,
            creditorIban: null,
            creditorAddressStreet: null,
            creditorAddressCity: null,
            creditorAddressCountry: null,
            paymentReferencePrefix: null,
            isConfigured: false,
        );

        // Assert
        $this->assertNull($dto->creditorId);
        $this->assertNull($dto->creditorName);
        $this->assertNull($dto->creditorIban);
        $this->assertNull($dto->creditorAddressStreet);
        $this->assertNull($dto->creditorAddressCity);
        $this->assertNull($dto->creditorAddressCountry);
        $this->assertNull($dto->paymentReferencePrefix);
        $this->assertFalse($dto->isConfigured);
    }

    public function test_from_row_with_complete_data_unmasked(): void
    {
        // Arrange
        $row = [
            'creditor_id' => 'DE89ZZZ09999999999',
            'creditor_name' => 'My Club',
            'creditor_iban' => 'DE89370400440532013000',
            'creditor_address_street' => '123 Main St',
            'creditor_address_city' => 'Berlin',
            'creditor_address_country' => 'DE',
            'payment_reference_prefix' => 'CLUB',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row, masked: false);

        // Assert
        $this->assertSame('DE89ZZZ09999999999', $dto->creditorId);
        $this->assertSame('My Club', $dto->creditorName);
        $this->assertSame('DE89370400440532013000', $dto->creditorIban);
        $this->assertSame('123 Main St', $dto->creditorAddressStreet);
        $this->assertSame('Berlin', $dto->creditorAddressCity);
        $this->assertSame('DE', $dto->creditorAddressCountry);
        $this->assertSame('CLUB', $dto->paymentReferencePrefix);
        $this->assertTrue($dto->isConfigured);
    }

    public function test_from_row_with_complete_data_masked(): void
    {
        // Arrange
        $row = [
            'creditor_id' => 'DE89ZZZ09999999999',
            'creditor_name' => 'My Club',
            'creditor_iban' => 'DE89370400440532013000',
            'creditor_address_street' => '123 Main St',
            'creditor_address_city' => 'Berlin',
            'creditor_address_country' => 'DE',
            'payment_reference_prefix' => 'CLUB',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row, masked: true);

        // Assert
        $this->assertSame('DE89****9999', $dto->creditorId);
        $this->assertSame('My Club', $dto->creditorName);
        $this->assertSame('DE89****3000', $dto->creditorIban);
        $this->assertSame('123 Main St', $dto->creditorAddressStreet);
        $this->assertSame('Berlin', $dto->creditorAddressCity);
        $this->assertSame('DE', $dto->creditorAddressCountry);
        $this->assertSame('CLUB', $dto->paymentReferencePrefix);
        $this->assertTrue($dto->isConfigured);
    }

    public function test_from_row_masks_short_strings_as_asterisks(): void
    {
        // Arrange
        $row = [
            'creditor_id' => 'SHORT',
            'creditor_name' => 'Club',
            'creditor_iban' => 'DE89370400440532013000',
            'creditor_address_street' => null,
            'creditor_address_city' => null,
            'creditor_address_country' => null,
            'payment_reference_prefix' => null,
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row, masked: true);

        // Assert
        $this->assertSame('****', $dto->creditorId);
    }

    public function test_from_row_handles_missing_optional_fields(): void
    {
        // Arrange
        $row = [
            'creditor_id' => 'DE89ZZZ09999999999',
            'creditor_name' => 'My Club',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row);

        // Assert
        $this->assertSame('DE89ZZZ09999999999', $dto->creditorId);
        $this->assertSame('My Club', $dto->creditorName);
        $this->assertSame('DE89370400440532013000', $dto->creditorIban);
        $this->assertNull($dto->creditorAddressStreet);
        $this->assertNull($dto->creditorAddressCity);
        $this->assertNull($dto->creditorAddressCountry);
        $this->assertNull($dto->paymentReferencePrefix);
        $this->assertTrue($dto->isConfigured);
    }

    public function test_from_row_marks_as_not_configured_when_creditor_id_missing(): void
    {
        // Arrange
        $row = [
            'creditor_name' => 'My Club',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row);

        // Assert
        $this->assertFalse($dto->isConfigured);
    }

    public function test_from_row_marks_as_not_configured_when_creditor_name_missing(): void
    {
        // Arrange
        $row = [
            'creditor_id' => 'DE89ZZZ09999999999',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row);

        // Assert
        $this->assertFalse($dto->isConfigured);
    }

    public function test_from_row_marks_as_not_configured_when_creditor_iban_missing(): void
    {
        // Arrange
        $row = [
            'creditor_id' => 'DE89ZZZ09999999999',
            'creditor_name' => 'My Club',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row);

        // Assert
        $this->assertFalse($dto->isConfigured);
    }

    public function test_from_row_marks_as_not_configured_when_all_fields_missing(): void
    {
        // Arrange
        $row = [];

        // Act
        $dto = SepaConfigDto::fromRow($row);

        // Assert
        $this->assertFalse($dto->isConfigured);
        $this->assertNull($dto->creditorId);
        $this->assertNull($dto->creditorName);
        $this->assertNull($dto->creditorIban);
    }

    public function test_from_row_marks_as_not_configured_with_empty_strings(): void
    {
        // Arrange
        $row = [
            'creditor_id' => '',
            'creditor_name' => '',
            'creditor_iban' => '',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row);

        // Assert
        $this->assertFalse($dto->isConfigured);
    }

    public function test_from_row_default_masked_parameter_is_false(): void
    {
        // Arrange
        $row = [
            'creditor_id' => 'DE89ZZZ09999999999',
            'creditor_name' => 'My Club',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row);

        // Assert
        $this->assertSame('DE89ZZZ09999999999', $dto->creditorId);
        $this->assertSame('DE89370400440532013000', $dto->creditorIban);
    }

    public function test_to_array_returns_correct_keys_and_values(): void
    {
        // Arrange
        $dto = new SepaConfigDto(
            creditorId: 'DE89ZZZ09999999999',
            creditorName: 'My Club',
            creditorIban: 'DE89370400440532013000',
            creditorAddressStreet: '123 Main St',
            creditorAddressCity: 'Berlin',
            creditorAddressCountry: 'DE',
            paymentReferencePrefix: 'CLUB',
            isConfigured: true,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertArrayHasKey('creditor_id', $array);
        $this->assertArrayHasKey('creditor_name', $array);
        $this->assertArrayHasKey('creditor_iban', $array);
        $this->assertArrayHasKey('creditor_address_street', $array);
        $this->assertArrayHasKey('creditor_address_city', $array);
        $this->assertArrayHasKey('creditor_address_country', $array);
        $this->assertArrayHasKey('payment_reference_prefix', $array);
        $this->assertArrayHasKey('is_configured', $array);

        $this->assertSame('DE89ZZZ09999999999', $array['creditor_id']);
        $this->assertSame('My Club', $array['creditor_name']);
        $this->assertSame('DE89370400440532013000', $array['creditor_iban']);
        $this->assertSame('123 Main St', $array['creditor_address_street']);
        $this->assertSame('Berlin', $array['creditor_address_city']);
        $this->assertSame('DE', $array['creditor_address_country']);
        $this->assertSame('CLUB', $array['payment_reference_prefix']);
        $this->assertTrue($array['is_configured']);
    }

    public function test_to_array_with_all_null_values(): void
    {
        // Arrange
        $dto = new SepaConfigDto(
            creditorId: null,
            creditorName: null,
            creditorIban: null,
            creditorAddressStreet: null,
            creditorAddressCity: null,
            creditorAddressCountry: null,
            paymentReferencePrefix: null,
            isConfigured: false,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertNull($array['creditor_id']);
        $this->assertNull($array['creditor_name']);
        $this->assertNull($array['creditor_iban']);
        $this->assertNull($array['creditor_address_street']);
        $this->assertNull($array['creditor_address_city']);
        $this->assertNull($array['creditor_address_country']);
        $this->assertNull($array['payment_reference_prefix']);
        $this->assertFalse($array['is_configured']);
    }

    public function test_masking_eight_character_string(): void
    {
        // Arrange
        $row = [
            'creditor_id' => '12345678',
            'creditor_name' => 'Club',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row, masked: true);

        // Assert
        $this->assertSame('1234****5678', $dto->creditorId);
    }

    public function test_masking_exactly_seven_character_string(): void
    {
        // Arrange
        $row = [
            'creditor_id' => '1234567',
            'creditor_name' => 'Club',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row, masked: true);

        // Assert
        $this->assertSame('****', $dto->creditorId);
    }

    public function test_masking_null_string(): void
    {
        // Arrange
        $row = [
            'creditor_id' => null,
            'creditor_name' => 'Club',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row, masked: true);

        // Assert
        $this->assertNull($dto->creditorId);
    }

    public function test_masking_empty_string(): void
    {
        // Arrange
        $row = [
            'creditor_id' => '',
            'creditor_name' => 'Club',
            'creditor_iban' => 'DE89370400440532013000',
        ];

        // Act
        $dto = SepaConfigDto::fromRow($row, masked: true);

        // Assert
        $this->assertNull($dto->creditorId);
    }
}
