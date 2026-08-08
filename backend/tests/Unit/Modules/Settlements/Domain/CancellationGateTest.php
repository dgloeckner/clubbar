<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Domain;

use App\Modules\Settlements\Domain\CancellationGate;
use App\Modules\Settlements\Enums\SettlementMethod;
use PHPUnit\Framework\TestCase;

/**
 * The gate reads raw `settlements` rows from two callers — the service, which
 * throws, and the DTO, which reports `is_cancellable` to the admin panel. These
 * cases pin the rows that are not well-formed enough to reach it through
 * `createSettlement()`: the pre-#163 row with no `method`, and the row whose
 * dates arrive as full DATETIME strings.
 */
class CancellationGateTest extends TestCase
{
    public function test_a_row_predating_the_method_column_is_treated_as_a_direct_debit(): void
    {
        $row = ['is_cancelled' => 0, 'execution_date' => '2020-01-01'];

        $this->assertFalse(CancellationGate::isCancellable($row, '2026-08-08'));
        $this->assertStringContainsString('execution date', (string) CancellationGate::blocker($row, '2026-08-08'));
    }

    public function test_a_row_without_an_execution_date_is_not_blocked_by_one(): void
    {
        // A write-off or a hand-built fixture may carry no date at all; the
        // backstop must then simply not apply, rather than compare against ''.
        $row = ['is_cancelled' => 0, 'method' => SettlementMethod::DIRECT_DEBIT->value, 'execution_date' => null];

        $this->assertTrue(CancellationGate::isCancellable($row, '2026-08-08'));
        $this->assertNull(CancellationGate::blocker($row, '2026-08-08'));
    }

    public function test_dates_arriving_as_datetimes_are_compared_by_day(): void
    {
        $row = [
            'is_cancelled' => 0,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'execution_date' => '2026-08-08 00:00:00',
        ];

        $this->assertTrue(CancellationGate::isCancellable($row, '2026-08-08'), 'The execution day itself is still open');
        $this->assertFalse(CancellationGate::isCancellable($row, '2026-08-09'));
    }

    public function test_the_submitted_moment_is_named_in_the_reason(): void
    {
        $row = [
            'is_cancelled' => 0,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'execution_date' => '2026-08-20',
            'submitted_at' => '2026-08-10 14:03:11',
        ];

        $this->assertSame(
            'This settlement was submitted to the bank on 2026-08-10, so the money has moved. Reverse it instead.',
            CancellationGate::blocker($row, '2026-08-08'),
        );
    }
}
