<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Enums;

use App\Modules\Settlements\Enums\SettlementMethod;
use PHPUnit\Framework\TestCase;

class SettlementMethodTest extends TestCase
{
    public function test_direct_debit_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('direct_debit', SettlementMethod::DIRECT_DEBIT->value);
    }

    public function test_bank_transfer_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('bank_transfer', SettlementMethod::BANK_TRANSFER->value);
    }

    public function test_write_off_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('write_off', SettlementMethod::WRITE_OFF->value);
    }

    public function test_direct_debit_label(): void
    {
        // Act & Assert
        $this->assertSame('SEPA Direct Debit', SettlementMethod::DIRECT_DEBIT->label());
    }

    public function test_bank_transfer_label(): void
    {
        // Act & Assert
        $this->assertSame('Bank Transfer', SettlementMethod::BANK_TRANSFER->label());
    }

    public function test_write_off_label(): void
    {
        // Act & Assert
        $this->assertSame('Write Off', SettlementMethod::WRITE_OFF->label());
    }

    public function test_direct_debit_is_sepa_exportable(): void
    {
        // Act & Assert
        $this->assertTrue(SettlementMethod::DIRECT_DEBIT->isSepaExportable());
    }

    public function test_bank_transfer_is_not_sepa_exportable(): void
    {
        // Act & Assert
        $this->assertFalse(SettlementMethod::BANK_TRANSFER->isSepaExportable());
    }

    public function test_write_off_is_not_sepa_exportable(): void
    {
        // Act & Assert
        $this->assertFalse(SettlementMethod::WRITE_OFF->isSepaExportable());
    }

    public function test_from_direct_debit(): void
    {
        // Act & Assert
        $this->assertSame(SettlementMethod::DIRECT_DEBIT, SettlementMethod::from('direct_debit'));
    }

    public function test_from_bank_transfer(): void
    {
        // Act & Assert
        $this->assertSame(SettlementMethod::BANK_TRANSFER, SettlementMethod::from('bank_transfer'));
    }

    public function test_from_write_off(): void
    {
        // Act & Assert
        $this->assertSame(SettlementMethod::WRITE_OFF, SettlementMethod::from('write_off'));
    }

    public function test_try_from_direct_debit(): void
    {
        // Act & Assert
        $this->assertSame(SettlementMethod::DIRECT_DEBIT, SettlementMethod::tryFrom('direct_debit'));
    }

    public function test_try_from_bank_transfer(): void
    {
        // Act & Assert
        $this->assertSame(SettlementMethod::BANK_TRANSFER, SettlementMethod::tryFrom('bank_transfer'));
    }

    public function test_try_from_invalid_returns_null(): void
    {
        // Act & Assert
        $this->assertNull(SettlementMethod::tryFrom('invalid'));
    }
}
