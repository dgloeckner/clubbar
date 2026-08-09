<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\SettlementPreviewDto;
use PHPUnit\Framework\TestCase;

class SettlementPreviewDtoTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        // Arrange
        $eligibleMembers = ['member1', 'member2'];
        $ineligibleMembers = ['member3'];
        $eligibleTotal = 10000;
        $ineligibleTotal = 5000;
        $memberCount = 3;
        $warnings = ['warning1', 'warning2'];

        // Act
        $dto = new SettlementPreviewDto(
            eligibleMembers: $eligibleMembers,
            ineligibleMembers: $ineligibleMembers,
            eligibleTotal: $eligibleTotal,
            ineligibleTotal: $ineligibleTotal,
            memberCount: $memberCount,
            warnings: $warnings,
        );

        // Assert
        $this->assertSame($eligibleMembers, $dto->eligibleMembers);
        $this->assertSame($ineligibleMembers, $dto->ineligibleMembers);
        $this->assertSame($eligibleTotal, $dto->eligibleTotal);
        $this->assertSame($ineligibleTotal, $dto->ineligibleTotal);
        $this->assertSame($memberCount, $dto->memberCount);
        $this->assertSame($warnings, $dto->warnings);
    }

    public function test_to_array_returns_correct_keys_and_values(): void
    {
        // Arrange
        $eligibleMembers = ['member1', 'member2'];
        $ineligibleMembers = ['member3'];
        $eligibleTotal = 10000;
        $ineligibleTotal = 5000;
        $memberCount = 3;
        $warnings = ['warning1'];

        $dto = new SettlementPreviewDto(
            eligibleMembers: $eligibleMembers,
            ineligibleMembers: $ineligibleMembers,
            eligibleTotal: $eligibleTotal,
            ineligibleTotal: $ineligibleTotal,
            memberCount: $memberCount,
            warnings: $warnings,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertArrayHasKey('eligible_members', $array);
        $this->assertArrayHasKey('ineligible_members', $array);
        $this->assertArrayHasKey('eligible_total', $array);
        $this->assertArrayHasKey('ineligible_total', $array);
        $this->assertArrayHasKey('member_count', $array);
        $this->assertArrayHasKey('warnings', $array);

        $this->assertSame($eligibleMembers, $array['eligible_members']);
        $this->assertSame($ineligibleMembers, $array['ineligible_members']);
        $this->assertSame($eligibleTotal, $array['eligible_total']);
        $this->assertSame($ineligibleTotal, $array['ineligible_total']);
        $this->assertSame($memberCount, $array['member_count']);
        $this->assertSame($warnings, $array['warnings']);
    }

    public function test_to_array_with_empty_arrays_and_zero_totals(): void
    {
        // Arrange
        $dto = new SettlementPreviewDto(
            eligibleMembers: [],
            ineligibleMembers: [],
            eligibleTotal: 0,
            ineligibleTotal: 0,
            memberCount: 0,
            warnings: [],
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertEmpty($array['eligible_members']);
        $this->assertEmpty($array['ineligible_members']);
        $this->assertSame(0, $array['eligible_total']);
        $this->assertSame(0, $array['ineligible_total']);
        $this->assertSame(0, $array['member_count']);
        $this->assertEmpty($array['warnings']);
    }

    public function test_to_array_with_negative_totals(): void
    {
        // Arrange
        $dto = new SettlementPreviewDto(
            eligibleMembers: [],
            ineligibleMembers: [],
            eligibleTotal: -1000,
            ineligibleTotal: -500,
            memberCount: 0,
            warnings: [],
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertSame(-1000, $array['eligible_total']);
        $this->assertSame(-500, $array['ineligible_total']);
    }

    /**
     * The count of what the run would actually contain (#128). It defaults to
     * zero so the existing callers keep working, but it has to survive
     * serialization or the confirmation falls back to counting the caller's
     * own selection — which is the understatement the issue is about.
     */
    public function test_to_array_carries_the_transaction_count(): void
    {
        // Arrange
        $dto = new SettlementPreviewDto(
            eligibleMembers: [],
            ineligibleMembers: [],
            eligibleTotal: 0,
            ineligibleTotal: 0,
            memberCount: 1,
            warnings: [],
            transactionCount: 12,
        );

        // Act
        $array = $dto->toArray();

        // Assert
        $this->assertArrayHasKey('transaction_count', $array);
        $this->assertSame(12, $array['transaction_count']);
    }

    public function test_transaction_count_defaults_to_zero(): void
    {
        $dto = new SettlementPreviewDto(
            eligibleMembers: [],
            ineligibleMembers: [],
            eligibleTotal: 0,
            ineligibleTotal: 0,
            memberCount: 0,
            warnings: [],
        );

        $this->assertSame(0, $dto->transactionCount);
        $this->assertSame(0, $dto->toArray()['transaction_count']);
    }
}
