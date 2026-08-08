<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Enums;

use App\Modules\Settlements\Enums\ReversalReason;
use PHPUnit\Framework\TestCase;

/**
 * The one consequence that separates the two reasons (#196, ruling #148 §3).
 */
class ReversalReasonTest extends TestCase
{
    public function test_a_bank_return_places_a_collection_hold(): void
    {
        // Without it the next sweep re-debits exactly what just bounced, earns
        // a second return fee, and loops.
        $this->assertTrue(ReversalReason::BANK_RETURN->placesCollectionHold());
    }

    public function test_a_club_error_places_none(): void
    {
        // Re-collection is the entire point of recording one.
        $this->assertFalse(ReversalReason::CLUB_ERROR->placesCollectionHold());
    }

    public function test_the_reasons_are_exactly_what_the_column_accepts(): void
    {
        // The ENUM in migration 007 carries these two values and no others.
        $this->assertSame(
            ['bank_return', 'club_error'],
            array_column(ReversalReason::cases(), 'value'),
        );
    }

    public function test_every_reason_has_a_label(): void
    {
        foreach (ReversalReason::cases() as $reason) {
            $this->assertNotSame('', $reason->label());
        }
    }
}
