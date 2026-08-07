<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Enums;

use App\Modules\Settlements\Enums\ManualReason;
use PHPUnit\Framework\TestCase;

class ManualReasonTest extends TestCase
{
    public function test_cash_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('cash', ManualReason::CASH->value);
    }

    public function test_bank_transfer_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('bank_transfer', ManualReason::BANK_TRANSFER->value);
    }

    public function test_write_off_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('write_off', ManualReason::WRITE_OFF->value);
    }

    public function test_other_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('other', ManualReason::OTHER->value);
    }

    public function test_cash_label(): void
    {
        // Act & Assert
        $this->assertSame('Cash Payment', ManualReason::CASH->label());
    }

    public function test_bank_transfer_label(): void
    {
        // Act & Assert
        $this->assertSame('Bank Transfer', ManualReason::BANK_TRANSFER->label());
    }

    public function test_write_off_label(): void
    {
        // Act & Assert
        $this->assertSame('Write Off', ManualReason::WRITE_OFF->label());
    }

    public function test_other_label(): void
    {
        // Act & Assert
        $this->assertSame('Other', ManualReason::OTHER->label());
    }

    public function test_from_cash(): void
    {
        // Act & Assert
        $this->assertSame(ManualReason::CASH, ManualReason::from('cash'));
    }

    public function test_from_bank_transfer(): void
    {
        // Act & Assert
        $this->assertSame(ManualReason::BANK_TRANSFER, ManualReason::from('bank_transfer'));
    }

    public function test_from_write_off(): void
    {
        // Act & Assert
        $this->assertSame(ManualReason::WRITE_OFF, ManualReason::from('write_off'));
    }

    public function test_from_other(): void
    {
        // Act & Assert
        $this->assertSame(ManualReason::OTHER, ManualReason::from('other'));
    }

    public function test_try_from_cash(): void
    {
        // Act & Assert
        $this->assertSame(ManualReason::CASH, ManualReason::tryFrom('cash'));
    }

    public function test_try_from_bank_transfer(): void
    {
        // Act & Assert
        $this->assertSame(ManualReason::BANK_TRANSFER, ManualReason::tryFrom('bank_transfer'));
    }

    public function test_try_from_invalid_returns_null(): void
    {
        // Act & Assert
        $this->assertNull(ManualReason::tryFrom('invalid'));
    }
}
