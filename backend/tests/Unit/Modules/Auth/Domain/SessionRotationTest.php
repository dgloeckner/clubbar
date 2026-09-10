<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Domain;

use App\Modules\Auth\Domain\SessionRotation;
use App\Modules\Auth\Domain\SessionTimeout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SessionRotationTest extends TestCase
{
    private const SUCCESSOR = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /* ─────────────────────────── The tombstone ─────────────────────────── */

    public function test_tombstone_carries_the_successor_and_the_moment(): void
    {
        $tombstone = SessionRotation::tombstone(self::SUCCESSOR, 1_700_000_000);

        $this->assertSame(self::SUCCESSOR, $tombstone[SessionRotation::SUCCESSOR_ID]);
        $this->assertSame(1_700_000_000, $tombstone[SessionRotation::ROTATED_AT]);
    }

    /**
     * The property the whole design rests on: a tombstone is a forwarding
     * address, not a session. If it ever carried `admin_user_id`, the old ID
     * would stay a working login for the grace window instead of merely being
     * exchangeable for one — and `AdminSessionAuth` would authenticate it
     * without ever hopping.
     */
    public function test_tombstone_carries_nothing_that_could_authenticate(): void
    {
        $tombstone = SessionRotation::tombstone(self::SUCCESSOR);

        $this->assertSame(
            [SessionRotation::SUCCESSOR_ID, SessionRotation::ROTATED_AT],
            array_keys($tombstone),
            'a tombstone holds exactly two keys — anything else is data that outlived its session'
        );

        foreach ([
            'admin_user_id',
            'csrf_token',
            'totp_setup_required',
            'mfa_pending_user_id',
            SessionTimeout::AUTHENTICATED_AT,
            SessionTimeout::LAST_ACTIVITY_AT,
        ] as $key) {
            $this->assertArrayNotHasKey($key, $tombstone);
        }
    }

    /* ──────────────────────── Following the pointer ────────────────────── */

    public function test_successor_is_returned_inside_the_grace_window(): void
    {
        $now = 1_700_000_000;
        $session = SessionRotation::tombstone(self::SUCCESSOR, $now);

        $this->assertSame(
            self::SUCCESSOR,
            SessionRotation::successorWithinGrace($session, $now + SessionRotation::GRACE_SECONDS - 1)
        );
    }

    public function test_successor_is_returned_for_a_rotation_that_just_happened(): void
    {
        $now = 1_700_000_000;
        $session = SessionRotation::tombstone(self::SUCCESSOR, $now);

        $this->assertSame(self::SUCCESSOR, SessionRotation::successorWithinGrace($session, $now));
    }

    /**
     * The boundary is exclusive, matching how `SessionTimeout` reads its own
     * limits: at exactly the grace window the pointer is spent.
     */
    public function test_successor_is_refused_at_the_grace_boundary(): void
    {
        $now = 1_700_000_000;
        $session = SessionRotation::tombstone(self::SUCCESSOR, $now);

        $this->assertNull(
            SessionRotation::successorWithinGrace($session, $now + SessionRotation::GRACE_SECONDS)
        );
    }

    public function test_successor_is_refused_long_after_the_rotation(): void
    {
        $now = 1_700_000_000;
        $session = SessionRotation::tombstone(self::SUCCESSOR, $now);

        $this->assertNull(SessionRotation::successorWithinGrace($session, $now + 3600));
    }

    /**
     * A stamp in the future means the clock moved backwards — an NTP step, or a
     * session file copied between hosts. Reading that as "0 seconds old, for as
     * long as the skew lasts" would keep a rotated-away ID alive indefinitely,
     * so it is treated as spent instead.
     */
    public function test_a_stamp_from_the_future_is_refused_rather_than_trusted(): void
    {
        $now = 1_700_000_000;
        $session = SessionRotation::tombstone(self::SUCCESSOR, $now + 600);

        $this->assertNull(SessionRotation::successorWithinGrace($session, $now));
    }

    public function test_an_ordinary_session_has_no_successor(): void
    {
        $session = ['admin_user_id' => 'admin-1', SessionTimeout::LAST_ACTIVITY_AT => time()];

        $this->assertNull(SessionRotation::successorWithinGrace($session));
        $this->assertFalse(SessionRotation::isStaleTombstone($session));
    }

    public function test_an_empty_session_has_no_successor(): void
    {
        $this->assertNull(SessionRotation::successorWithinGrace([]));
        $this->assertFalse(SessionRotation::isStaleTombstone([]));
    }

    /**
     * Everything malformed answers null rather than throwing: this runs on
     * whatever the session store hands back, which on a shared host is not a
     * thing to be trusting about types.
     *
     * @param array<string, mixed> $session
     */
    #[DataProvider('malformedTombstones')]
    public function test_a_malformed_tombstone_is_refused(array $session): void
    {
        $this->assertNull(SessionRotation::successorWithinGrace($session));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedTombstones(): array
    {
        return [
            'no timestamp'      => [[SessionRotation::SUCCESSOR_ID => self::SUCCESSOR]],
            'no successor'      => [[SessionRotation::ROTATED_AT => time()]],
            'empty successor'   => [[SessionRotation::SUCCESSOR_ID => '', SessionRotation::ROTATED_AT => time()]],
            'successor not a string' => [[SessionRotation::SUCCESSOR_ID => 12345, SessionRotation::ROTATED_AT => time()]],
            'timestamp a string'     => [[SessionRotation::SUCCESSOR_ID => self::SUCCESSOR, SessionRotation::ROTATED_AT => '1700000000']],
            'timestamp null'         => [[SessionRotation::SUCCESSOR_ID => self::SUCCESSOR, SessionRotation::ROTATED_AT => null]],
        ];
    }

    /* ───────────────────────── The stale notice ────────────────────────── */

    public function test_a_spent_tombstone_is_reported_as_stale(): void
    {
        $now = 1_700_000_000;
        $session = SessionRotation::tombstone(self::SUCCESSOR, $now);

        $this->assertTrue(SessionRotation::isStaleTombstone($session, $now + 3600));
    }

    public function test_a_live_tombstone_is_not_stale(): void
    {
        $now = 1_700_000_000;
        $session = SessionRotation::tombstone(self::SUCCESSOR, $now);

        $this->assertFalse(SessionRotation::isStaleTombstone($session, $now + 1));
    }
}
