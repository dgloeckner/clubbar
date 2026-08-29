<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\ReversalCandidateDto;
use App\Modules\Settlements\Domain\BlockedReason;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Enums\SettlementStatus;
use PHPUnit\Framework\TestCase;

/**
 * What a resolved bank reference puts on the wire (epic #433 §3).
 *
 * The shape matters more than usual here: the treasurer picks a row off this
 * list and reverses whoever it names, irreversibly. So a candidate has to carry
 * the four facts that let them match it against the line on their statement,
 * and — when it cannot be acted on — say so rather than leave the click armed.
 */
class ReversalCandidateDtoTest extends TestCase
{
    public function test_a_candidate_carries_the_four_facts_that_identify_a_collection(): void
    {
        $array = $this->candidate()->toArray();

        $this->assertSame('Alice Member', $array['member_name']);
        $this->assertSame(2550, $array['amount_cents']);
        $this->assertSame(25.5, $array['amount_eur']);
        $this->assertSame('2026-08-20', $array['execution_date']);
        $this->assertSame('submitted', $array['status']);
        $this->assertSame('Submitted to the bank', $array['status_label']);

        // Both references travel: the statement quotes one or the other.
        $this->assertSame('E2E-abc123abc123-def456def456', $array['end_to_end_id']);
        $this->assertSame('MND-alice', $array['mandate_reference']);
    }

    public function test_a_reversible_collection_nobody_has_recorded_is_actionable(): void
    {
        $candidate = $this->candidate();

        $this->assertTrue($candidate->isActionable());
        $this->assertTrue($candidate->toArray()['is_actionable']);
        $this->assertNull($candidate->toArray()['reversal_blocked_reason']);
        $this->assertFalse($candidate->toArray()['already_reversed']);
        $this->assertNull($candidate->toArray()['reversed_at']);
    }

    public function test_a_settlement_the_gate_refuses_is_not_actionable_and_says_why(): void
    {
        // Shown rather than filtered out: a reference the treasurer genuinely
        // read off a statement must never come back as "no match".
        $candidate = $this->candidate(
            status: SettlementStatus::EXPORTED,
            isReversible: false,
            reversalBlockedReason: new BlockedReason(
                BusinessRuleReason::SETTLEMENT_NOT_YET_COLLECTED,
                'This settlement has not moved any money yet. Cancel it instead.',
            ),
        );

        $this->assertFalse($candidate->isActionable());

        $array = $candidate->toArray();
        $this->assertFalse($array['is_actionable']);
        $this->assertStringContainsString('Cancel it instead', $array['reversal_blocked_reason']);
        // The client shows the code, not the sentence: an admin reading German
        // must not be handed the English one (#757).
        $this->assertSame('settlement_not_yet_collected', $array['reversal_blocked_code']);
        $this->assertNull($array['reversal_blocked_params']);
    }

    public function test_a_member_already_reversed_names_when_and_by_whom(): void
    {
        // The question actually being asked is "did someone already do this?",
        // so the answer is the provenance, not a disappearance.
        $candidate = $this->candidate(
            alreadyReversed: true,
            reversedAt: '2026-08-21 09:30:00',
            reversedByAdminName: 'The Treasurer',
            reversedReason: ReversalReason::BANK_RETURN,
        );

        $this->assertFalse($candidate->isActionable());

        $array = $candidate->toArray();
        $this->assertTrue($array['already_reversed']);
        $this->assertSame('2026-08-21T09:30:00Z', $array['reversed_at']);
        $this->assertSame('The Treasurer', $array['reversed_by_admin_name']);
        $this->assertSame('bank_return', $array['reversed_reason']);
    }

    public function test_a_run_predating_persisted_identifiers_carries_no_end_to_end_id(): void
    {
        // Reachable by mandate reference only (#150) — and honest about it
        // rather than inventing the identifier the export would have used.
        $array = $this->candidate(endToEndId: null)->toArray();

        $this->assertNull($array['end_to_end_id']);
        $this->assertSame('MND-alice', $array['mandate_reference']);
    }

    private function candidate(
        SettlementStatus $status = SettlementStatus::SUBMITTED,
        bool $isReversible = true,
        ?BlockedReason $reversalBlockedReason = null,
        ?string $endToEndId = 'E2E-abc123abc123-def456def456',
        bool $alreadyReversed = false,
        ?string $reversedAt = null,
        ?string $reversedByAdminName = null,
        ?ReversalReason $reversedReason = null,
    ): ReversalCandidateDto {
        return new ReversalCandidateDto(
            settlementId: 'settlement-1',
            memberId: 'member-alice',
            memberName: 'Alice Member',
            amountCents: 2550,
            endToEndId: $endToEndId,
            mandateReference: 'MND-alice',
            executionDate: '2026-08-20',
            settlementDate: '2026-08-13',
            status: $status,
            isReversible: $isReversible,
            reversalBlockedReason: $reversalBlockedReason,
            alreadyReversed: $alreadyReversed,
            reversedAt: $reversedAt,
            reversedByAdminName: $reversedByAdminName,
            reversedReason: $reversedReason,
        );
    }
}
