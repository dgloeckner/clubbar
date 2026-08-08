<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Domain;

use App\Modules\Settlements\Domain\CancellationGate;
use App\Modules\Settlements\Domain\ReversalGate;
use App\Modules\Settlements\Enums\SettlementMethod;
use PHPUnit\Framework\TestCase;

/**
 * The reversal gate is the mirror of the cancellation gate (#196, ruling #148
 * §7), so what these cases pin is mostly the *mirroring* itself: every row is
 * either cancellable or reversible, never both and — apart from a cancelled
 * one, where nothing ever moved — never neither.
 *
 * That property is what stops #81's "reverse it instead" pointing at a door
 * that refuses to open.
 */
class ReversalGateTest extends TestCase
{
    public function test_a_submitted_direct_debit_can_be_reversed(): void
    {
        $row = $this->row(submittedAt: '2026-08-01 10:00:00');

        $this->assertTrue(ReversalGate::isReversible($row, '2026-08-08'));
        $this->assertNull(ReversalGate::blocker($row, '2026-08-08'));
    }

    public function test_a_direct_debit_whose_execution_date_has_passed_can_be_reversed(): void
    {
        // The backstop against a forgotten "mark submitted" click: the bank has
        // almost certainly collected it, so reversal is the only remedy left.
        $row = $this->row(executionDate: '2026-08-01');

        $this->assertTrue(ReversalGate::isReversible($row, '2026-08-08'));
    }

    public function test_a_direct_debit_that_never_reached_the_bank_is_cancelled_instead(): void
    {
        $row = $this->row();

        $this->assertFalse(ReversalGate::isReversible($row, '2026-08-08'));
        $this->assertStringContainsString('Cancel it instead', (string) ReversalGate::blocker($row, '2026-08-08'));
    }

    public function test_an_exported_but_unsubmitted_file_is_still_cancelled_not_reversed(): void
    {
        // Generating a pain.008 is not sending it. Locking here would strand
        // the ordinary case of spotting a wrong amount in the downloaded file.
        $row = $this->row(exportedAt: '2026-08-07 09:00:00');

        $this->assertFalse(ReversalGate::isReversible($row, '2026-08-08'));
    }

    public function test_a_bank_transfer_is_always_reversible_because_the_money_already_arrived(): void
    {
        $row = $this->row(method: SettlementMethod::BANK_TRANSFER);

        $this->assertTrue(ReversalGate::isReversible($row, '2026-08-08'));
    }

    public function test_a_write_off_is_never_reversible_because_it_moved_nothing(): void
    {
        $row = $this->row(method: SettlementMethod::WRITE_OFF, executionDate: '2020-01-01');

        $this->assertFalse(ReversalGate::isReversible($row, '2026-08-08'));
        $this->assertStringContainsString('Cancel it instead', (string) ReversalGate::blocker($row, '2026-08-08'));
    }

    public function test_a_cancelled_settlement_is_neither_cancelled_again_nor_reversed(): void
    {
        $row = $this->row(isCancelled: true, submittedAt: '2026-08-01 10:00:00');

        $this->assertFalse(ReversalGate::isReversible($row, '2026-08-08'));
        $this->assertFalse(CancellationGate::isCancellable($row, '2026-08-08'));
        $this->assertStringContainsString('never collected anything', (string) ReversalGate::blocker($row, '2026-08-08'));
    }

    /**
     * The invariant, stated as a test: for every settlement that is not
     * cancelled, exactly one of the two gates is open. If a future rule change
     * opens or closes only one side, this fails.
     */
    public function test_exactly_one_gate_is_open_for_every_live_settlement(): void
    {
        $rows = [
            'draft direct debit' => $this->row(),
            'exported direct debit' => $this->row(exportedAt: '2026-08-07 09:00:00'),
            'submitted direct debit' => $this->row(submittedAt: '2026-08-01 10:00:00'),
            'overdue direct debit' => $this->row(executionDate: '2026-08-01'),
            'bank transfer' => $this->row(method: SettlementMethod::BANK_TRANSFER),
            'write off' => $this->row(method: SettlementMethod::WRITE_OFF),
            'row predating the method column' => ['is_cancelled' => 0, 'execution_date' => '2026-08-20'],
        ];

        foreach ($rows as $label => $row) {
            $this->assertNotSame(
                CancellationGate::isCancellable($row, '2026-08-08'),
                ReversalGate::isReversible($row, '2026-08-08'),
                "{$label}: a settlement is cancelled or reversed, never both and never neither",
            );
        }
    }

    /** @return array<string, mixed> A `settlements` row. */
    private function row(
        SettlementMethod $method = SettlementMethod::DIRECT_DEBIT,
        bool $isCancelled = false,
        ?string $submittedAt = null,
        ?string $exportedAt = null,
        string $executionDate = '2026-08-20',
    ): array {
        return [
            'is_cancelled' => $isCancelled ? 1 : 0,
            'method' => $method->value,
            'execution_date' => $executionDate,
            'submitted_at' => $submittedAt,
            'exported_at' => $exportedAt,
        ];
    }
}
