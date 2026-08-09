<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\ExcludedMemberDto;
use App\Modules\Settlements\Enums\SepaExclusionReason;
use PHPUnit\Framework\TestCase;

class ExcludedMemberDtoTest extends TestCase
{
    public function test_it_takes_the_name_from_the_member_row(): void
    {
        $dto = ExcludedMemberDto::fromMember(
            'member-1',
            ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            1500,
            SepaExclusionReason::NO_ACTIVE_MANDATE,
        );

        $this->assertSame('Ada Lovelace', $dto->displayName());
        $this->assertSame('Ada', $dto->firstName);
        $this->assertSame('Lovelace', $dto->lastName);
    }

    /**
     * The written-down form of an exclusion carries no name (#115). An audit
     * entry filed under the settlement is out of reach of the member's own
     * erasure, so a name in it survives that erasure indefinitely.
     */
    public function test_the_audit_projection_carries_the_id_and_no_name(): void
    {
        $dto = ExcludedMemberDto::fromMember(
            'member-1',
            ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            1500,
            SepaExclusionReason::NO_ACTIVE_MANDATE,
        );

        $this->assertSame([
            'member_id' => 'member-1',
            'amount_cents' => 1500,
            'reason' => 'no_active_mandate',
        ], $dto->toAuditArray());
    }

    public function test_a_member_row_that_is_gone_entirely_still_reports_the_exclusion(): void
    {
        // An anonymised or hard-deleted member has no name left to print. The
        // exclusion is still reported — #114 is about omissions that vanish,
        // and one with no name attached is still better than none at all.
        $dto = ExcludedMemberDto::fromMember('member-2', null, 2000, SepaExclusionReason::MEMBER_DELETED);

        $this->assertNull($dto->firstName);
        $this->assertNull($dto->lastName);
        $this->assertSame('member-2', $dto->displayName());
    }
}
