<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Enums;

use App\Modules\Notifications\Enums\DispenserAttentionOccasion;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use PHPUnit\Framework\TestCase;

/**
 * What makes one dispenser notice one notice (#956).
 *
 * Part of #956, epic #944.
 */
class DispenserAttentionOccasionTest extends TestCase
{
    /**
     * Every reason ADR-0057 can derive maps onto an occasion, and the mapping
     * keeps the two errands apart: a protocol mismatch sends somebody to a
     * keyboard, everything else to the machine.
     */
    public function test_every_unavailable_reason_names_an_errand(): void
    {
        $expected = [
            DispenserUnavailableReason::OFFLINE->value => DispenserAttentionOccasion::OFFLINE,
            DispenserUnavailableReason::PROTOCOL_MISMATCH->value => DispenserAttentionOccasion::MISMATCH,
            DispenserUnavailableReason::JAM->value => DispenserAttentionOccasion::FAULT,
            DispenserUnavailableReason::HOPPER_ERROR->value => DispenserAttentionOccasion::FAULT,
            DispenserUnavailableReason::UNSPECIFIED_FAULT->value => DispenserAttentionOccasion::FAULT,
        ];

        foreach (DispenserUnavailableReason::cases() as $reason) {
            $this->assertSame(
                $expected[$reason->value],
                DispenserAttentionOccasion::forReason($reason),
                $reason->value . ' must state which errand it is',
            );
        }
    }

    /**
     * The episode is stripped to digits so the longest occasion still fits the
     * budget `warnAdmins()` has: `occasion:adminUserId` into a VARCHAR(64),
     * with 36 characters spent on the id.
     */
    public function test_an_occasion_fits_beside_an_admin_id(): void
    {
        foreach (DispenserAttentionOccasion::cases() as $occasion) {
            $key = $occasion->withEpisode('2026-09-21T10:00:00.123Z');

            $this->assertLessThanOrEqual(27, strlen($key), $key);
            $this->assertSame($occasion, DispenserAttentionOccasion::fromDedupKey($key . ':' . str_repeat('a', 36)));
        }
    }

    /** The same episode is the same key; a different one is not. */
    public function test_the_episode_is_what_separates_two_notices(): void
    {
        $first = DispenserAttentionOccasion::FAULT->withEpisode('2026-09-21T10:00:00.000Z');

        $this->assertSame($first, DispenserAttentionOccasion::FAULT->withEpisode('2026-09-21T10:00:00.000Z'));
        $this->assertNotSame($first, DispenserAttentionOccasion::FAULT->withEpisode('2026-09-21T10:00:00.001Z'));
        $this->assertNotSame(
            $first,
            DispenserAttentionOccasion::OFFLINE->withEpisode('2026-09-21T10:00:00.000Z'),
            'two conditions in the same second are two errands',
        );
    }

    /** A key from another kind, or written by hand, names no occasion here. */
    public function test_a_foreign_key_names_no_occasion(): void
    {
        $this->assertNull(DispenserAttentionOccasion::fromDedupKey('30d:admin-1'));
        $this->assertNull(DispenserAttentionOccasion::fromDedupKey(''));
        $this->assertNull(DispenserAttentionOccasion::fromDedupKey('stale:2026-09-21:admin-1'));
    }
}
