<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Enums;

use App\Modules\Settlements\Enums\SettlementType;
use PHPUnit\Framework\TestCase;

class SettlementTypeTest extends TestCase
{
    public function test_sepa_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('sepa', SettlementType::SEPA->value);
    }

    public function test_manual_case_has_correct_value(): void
    {
        // Act & Assert
        $this->assertSame('manual', SettlementType::MANUAL->value);
    }

    public function test_sepa_label(): void
    {
        // Act & Assert
        $this->assertSame('SEPA Direct Debit', SettlementType::SEPA->label());
    }

    public function test_manual_label(): void
    {
        // Act & Assert
        $this->assertSame('Manual Settlement', SettlementType::MANUAL->label());
    }

    public function test_sepa_requires_sepa_eligibility(): void
    {
        // Act & Assert
        $this->assertTrue(SettlementType::SEPA->requiresSepaEligibility());
    }

    public function test_manual_does_not_require_sepa_eligibility(): void
    {
        // Act & Assert
        $this->assertFalse(SettlementType::MANUAL->requiresSepaEligibility());
    }

    public function test_from_sepa(): void
    {
        // Act & Assert
        $this->assertSame(SettlementType::SEPA, SettlementType::from('sepa'));
    }

    public function test_from_manual(): void
    {
        // Act & Assert
        $this->assertSame(SettlementType::MANUAL, SettlementType::from('manual'));
    }

    public function test_try_from_sepa(): void
    {
        // Act & Assert
        $this->assertSame(SettlementType::SEPA, SettlementType::tryFrom('sepa'));
    }

    public function test_try_from_manual(): void
    {
        // Act & Assert
        $this->assertSame(SettlementType::MANUAL, SettlementType::tryFrom('manual'));
    }

    public function test_try_from_invalid_returns_null(): void
    {
        // Act & Assert
        $this->assertNull(SettlementType::tryFrom('invalid'));
    }
}
