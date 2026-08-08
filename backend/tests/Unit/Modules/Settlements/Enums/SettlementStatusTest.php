<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Enums;

use App\Modules\Settlements\Enums\SettlementStatus;
use PHPUnit\Framework\TestCase;

/**
 * The derived status ladder (#196, ruling #148 §6).
 *
 * Order matters more than any single case: each rung has to beat every rung
 * below it, or a fully reversed settlement reads as "submitted" and the
 * treasurer believes money is with the bank that has already come back.
 */
class SettlementStatusTest extends TestCase
{
    public function test_a_settlement_with_nothing_recorded_is_a_draft(): void
    {
        $this->assertSame(SettlementStatus::DRAFT, SettlementStatus::derive([], 0, 3));
    }

    public function test_a_generated_file_makes_it_exported(): void
    {
        $row = ['exported_at' => '2026-08-07 09:00:00'];

        $this->assertSame(SettlementStatus::EXPORTED, SettlementStatus::derive($row, 0, 3));
    }

    public function test_reaching_the_bank_beats_having_generated_a_file(): void
    {
        $row = ['exported_at' => '2026-08-07 09:00:00', 'submitted_at' => '2026-08-07 11:00:00'];

        $this->assertSame(SettlementStatus::SUBMITTED, SettlementStatus::derive($row, 0, 3));
    }

    public function test_one_reversal_out_of_three_members_is_partly_reversed(): void
    {
        $row = ['submitted_at' => '2026-08-07 11:00:00'];

        $this->assertSame(SettlementStatus::PARTLY_REVERSED, SettlementStatus::derive($row, 1, 3));
    }

    public function test_every_member_reversed_is_fully_reversed(): void
    {
        $row = ['submitted_at' => '2026-08-07 11:00:00'];

        $this->assertSame(SettlementStatus::FULLY_REVERSED, SettlementStatus::derive($row, 3, 3));
    }

    public function test_cancellation_beats_everything_below_it(): void
    {
        $row = ['is_cancelled' => 1, 'submitted_at' => '2026-08-07 11:00:00', 'exported_at' => '2026-08-07 09:00:00'];

        $this->assertSame(SettlementStatus::CANCELLED, SettlementStatus::derive($row, 2, 2));
    }

    public function test_a_reversal_against_a_settlement_with_no_items_is_partial_not_complete(): void
    {
        // Zero members covered must never read as "all of them reversed" — the
        // count is missing, not satisfied.
        $this->assertSame(SettlementStatus::PARTLY_REVERSED, SettlementStatus::derive([], 1, 0));
    }

    public function test_more_reversals_than_members_still_reads_as_fully_reversed(): void
    {
        // Defensive: the unique constraint makes this unreachable, but a status
        // that silently downgraded on a count mismatch would hide the fact.
        $this->assertSame(SettlementStatus::FULLY_REVERSED, SettlementStatus::derive([], 4, 3));
    }

    public function test_every_status_has_a_label(): void
    {
        foreach (SettlementStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
