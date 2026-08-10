<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\DTOs;

use App\Modules\Settlements\DTOs\MandateMissingDto;
use PHPUnit\Framework\TestCase;

/**
 * A member the next run cannot reach for want of a mandate (#258).
 */
class MandateMissingDtoTest extends TestCase
{
    public function test_fromRow_reads_the_row_the_repository_returns(): void
    {
        $dto = MandateMissingDto::fromRow([
            'member_id' => 'member-1',
            'first_name' => 'Jonas',
            'last_name' => 'Feld',
            'balance_cents' => 1250,
        ]);

        $this->assertSame('member-1', $dto->memberId);
        $this->assertSame('Jonas', $dto->firstName);
        $this->assertSame('Feld', $dto->lastName);
        $this->assertSame(1250, $dto->balanceCents);
    }

    public function test_fromRow_casts_a_string_balance_to_an_integer(): void
    {
        // SUM() comes back from PDO as a string; money is integer cents
        // everywhere above this line, so the cast belongs here.
        $dto = MandateMissingDto::fromRow([
            'member_id' => 'member-1',
            'first_name' => 'Jonas',
            'last_name' => 'Feld',
            'balance_cents' => '640',
        ]);

        $this->assertSame(640, $dto->balanceCents);
    }

    public function test_toArray_is_the_shape_the_endpoint_promises(): void
    {
        $dto = new MandateMissingDto(
            memberId: 'member-1',
            firstName: 'Jonas',
            lastName: 'Feld',
            balanceCents: 1250,
        );

        // Deliberately no iban / mandate_reference: since #164 the pair lives
        // on one mandate row and arrives together, so "which half is missing"
        // could only ever answer "both".
        $this->assertSame([
            'member_id' => 'member-1',
            'first_name' => 'Jonas',
            'last_name' => 'Feld',
            'balance_cents' => 1250,
        ], $dto->toArray());
    }
}
