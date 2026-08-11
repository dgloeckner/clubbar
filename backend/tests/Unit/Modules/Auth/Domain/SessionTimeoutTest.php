<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Domain;

use App\Modules\Auth\Domain\SessionTimeout;
use PHPUnit\Framework\TestCase;

/**
 * The two session limits Pattern 013 promises and #118 found missing.
 *
 * The absolute limit is the one that carries the security weight: with only an
 * idle timeout, a stolen cookie stays alive indefinitely as long as something
 * keeps touching it.
 */
class SessionTimeoutTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function test_a_fresh_session_is_stamped_with_both_clocks(): void
    {
        $session = [];

        SessionTimeout::begin($session, self::NOW);

        $this->assertSame(self::NOW, $session[SessionTimeout::AUTHENTICATED_AT]);
        $this->assertSame(self::NOW, $session[SessionTimeout::LAST_ACTIVITY_AT]);
        $this->assertSame(self::NOW, $session[SessionTimeout::REGENERATED_AT]);
        $this->assertFalse(SessionTimeout::hasExpired($session, self::NOW));
    }

    public function test_a_session_used_within_the_idle_window_survives(): void
    {
        $session = [];
        SessionTimeout::begin($session, self::NOW);

        $this->assertFalse(SessionTimeout::hasExpired($session, self::NOW + SessionTimeout::IDLE_SECONDS - 1));
    }

    public function test_a_session_left_idle_too_long_expires(): void
    {
        $session = [];
        SessionTimeout::begin($session, self::NOW);

        $this->assertTrue(SessionTimeout::hasExpired($session, self::NOW + SessionTimeout::IDLE_SECONDS));
    }

    public function test_activity_restarts_the_idle_clock(): void
    {
        $session = [];
        SessionTimeout::begin($session, self::NOW);

        $later = self::NOW + SessionTimeout::IDLE_SECONDS - 1;
        SessionTimeout::touch($session, $later);

        $this->assertFalse(SessionTimeout::hasExpired($session, $later + SessionTimeout::IDLE_SECONDS - 1));
    }

    public function test_activity_does_not_restart_the_absolute_clock(): void
    {
        $session = [];
        SessionTimeout::begin($session, self::NOW);

        // Touched every half hour for a day and a bit — the idle clock never runs
        // out, and the session dies anyway.
        for ($elapsed = 0; $elapsed <= SessionTimeout::ABSOLUTE_SECONDS; $elapsed += 1800) {
            SessionTimeout::touch($session, self::NOW + $elapsed);
        }

        $this->assertTrue(SessionTimeout::hasExpired($session, self::NOW + SessionTimeout::ABSOLUTE_SECONDS));
        $this->assertSame(self::NOW, $session[SessionTimeout::AUTHENTICATED_AT]);
    }

    public function test_a_session_expires_the_moment_the_absolute_limit_is_reached(): void
    {
        $session = [];
        SessionTimeout::begin($session, self::NOW);
        SessionTimeout::touch($session, self::NOW + SessionTimeout::ABSOLUTE_SECONDS - 1);

        $this->assertFalse(SessionTimeout::hasExpired($session, self::NOW + SessionTimeout::ABSOLUTE_SECONDS - 1));
        $this->assertTrue(SessionTimeout::hasExpired($session, self::NOW + SessionTimeout::ABSOLUTE_SECONDS));
    }

    public function test_a_session_minted_before_the_rule_existed_is_adopted_rather_than_killed(): void
    {
        $session = ['admin_user_id' => 'admin-1'];

        $this->assertFalse(SessionTimeout::hasExpired($session, self::NOW));

        SessionTimeout::touch($session, self::NOW);

        $this->assertSame(self::NOW, $session[SessionTimeout::AUTHENTICATED_AT]);
        $this->assertSame(self::NOW, $session[SessionTimeout::LAST_ACTIVITY_AT]);
    }

    public function test_a_stamp_that_is_not_a_timestamp_is_ignored_rather_than_trusted(): void
    {
        $session = [
            SessionTimeout::AUTHENTICATED_AT => 'yesterday',
            SessionTimeout::LAST_ACTIVITY_AT => null,
        ];

        $this->assertFalse(SessionTimeout::hasExpired($session, self::NOW));

        SessionTimeout::touch($session, self::NOW);

        $this->assertSame(self::NOW, $session[SessionTimeout::AUTHENTICATED_AT]);
    }

    // ── Periodic session-ID rotation (#340) ─────────────────────────────────

    public function test_a_session_with_no_regeneration_stamp_is_due_immediately(): void
    {
        // Covers both a brand new session and one that predates this rule.
        $this->assertTrue(SessionTimeout::shouldRegenerateId([], 900, self::NOW));
    }

    public function test_a_session_regenerated_within_the_interval_is_not_due(): void
    {
        $session = [SessionTimeout::REGENERATED_AT => self::NOW];

        $this->assertFalse(SessionTimeout::shouldRegenerateId($session, 900, self::NOW + 899));
    }

    public function test_a_session_regenerated_past_the_interval_is_due(): void
    {
        $session = [SessionTimeout::REGENERATED_AT => self::NOW];

        $this->assertTrue(SessionTimeout::shouldRegenerateId($session, 900, self::NOW + 900));
    }

    public function test_a_regeneration_stamp_that_is_not_a_timestamp_is_ignored(): void
    {
        $session = [SessionTimeout::REGENERATED_AT => 'yesterday'];

        $this->assertTrue(SessionTimeout::shouldRegenerateId($session, 900, self::NOW));
    }

    public function test_mark_regenerated_restarts_the_rotation_clock(): void
    {
        $session = [SessionTimeout::REGENERATED_AT => self::NOW - 1000];

        SessionTimeout::markRegenerated($session, self::NOW);

        $this->assertSame(self::NOW, $session[SessionTimeout::REGENERATED_AT]);
        $this->assertFalse(SessionTimeout::shouldRegenerateId($session, 900, self::NOW + 899));
    }
}
