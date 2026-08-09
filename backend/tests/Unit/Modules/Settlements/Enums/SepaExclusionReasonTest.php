<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Enums;

use App\Modules\Settlements\Enums\SepaExclusionReason;
use PHPUnit\Framework\TestCase;

class SepaExclusionReasonTest extends TestCase
{
    public function test_the_stored_values_are_the_ones_the_api_reports(): void
    {
        // These strings reach the audit log and the export report, so a rename
        // is a breaking change to a permanent record, not a refactor.
        $this->assertSame('credit_balance', SepaExclusionReason::CREDIT_BALANCE->value);
        $this->assertSame('no_active_mandate', SepaExclusionReason::NO_ACTIVE_MANDATE->value);
        $this->assertSame('member_deleted', SepaExclusionReason::MEMBER_DELETED->value);
    }

    public function test_every_reason_has_a_label(): void
    {
        foreach (SepaExclusionReason::cases() as $reason) {
            $this->assertNotSame('', $reason->label());
        }
    }

    public function test_only_a_credit_is_not_a_shortfall(): void
    {
        // The whole point of the distinction (#114, ruling #141 §3). A credit
        // is money the club owes the member, so the settlement never counted
        // on collecting it — reporting it as a gap in the bank file would send
        // the treasurer chasing a debt that does not exist.
        $this->assertFalse(SepaExclusionReason::CREDIT_BALANCE->isShortfall());

        $this->assertTrue(SepaExclusionReason::NO_ACTIVE_MANDATE->isShortfall());
        $this->assertTrue(SepaExclusionReason::MEMBER_DELETED->isShortfall());
    }
}
