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

    // ── The credentials epoch ────────────────────────────────────────────────
    //
    // ADR-0026 used to leave other sessions alive after a credential change, on
    // the reasoning that ending them needed server-side session enumeration. It
    // needs one comparison instead: was this session authenticated before the
    // account's credentials last moved?

    private static function at(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    public function test_a_session_older_than_the_change_is_refused(): void
    {
        $session = [SessionTimeout::AUTHENTICATED_AT => self::NOW - 60];

        $this->assertTrue(SessionTimeout::predatesCredentialChange($session, self::at(self::NOW)));
    }

    /**
     * The tie counts as stale. The column is second-granular, so allowing it
     * would leave a one-second window in which an attacker who authenticated in
     * the same second as the victim's password change kept their session.
     */
    public function test_a_session_authenticated_in_the_same_second_is_refused(): void
    {
        $session = [SessionTimeout::AUTHENTICATED_AT => self::NOW];

        $this->assertTrue(SessionTimeout::predatesCredentialChange($session, self::at(self::NOW)));
    }

    public function test_a_session_authenticated_after_the_change_is_kept(): void
    {
        $session = [SessionTimeout::AUTHENTICATED_AT => self::NOW + 1];

        $this->assertFalse(SessionTimeout::predatesCredentialChange($session, self::at(self::NOW)));
    }

    /**
     * The pairing that keeps the acting request alive: it stamps itself past an
     * epoch written in the same second, so it is not refused by its own change.
     */
    public function test_the_acting_session_survives_the_change_it_just_made(): void
    {
        $session = [];

        SessionTimeout::beginAfterCredentialChange($session, self::NOW);

        $this->assertFalse(SessionTimeout::predatesCredentialChange($session, self::at(self::NOW)));
        $this->assertFalse(SessionTimeout::hasExpired($session, self::NOW));
    }

    /**
     * Every account carries no epoch until somebody changes a credential, so a
     * null must never sign anyone out — otherwise shipping the column would log
     * out every admin at once.
     */
    public function test_no_epoch_refuses_nobody(): void
    {
        $session = [SessionTimeout::AUTHENTICATED_AT => self::NOW - 10_000];

        $this->assertFalse(SessionTimeout::predatesCredentialChange($session, null));
        $this->assertFalse(SessionTimeout::predatesCredentialChange($session, ''));
    }

    /** A session adopted by `touch()` before these stamps existed. */
    public function test_a_session_without_a_stamp_is_not_refused(): void
    {
        $this->assertFalse(SessionTimeout::predatesCredentialChange([], self::at(self::NOW)));
        $this->assertFalse(
            SessionTimeout::predatesCredentialChange(
                [SessionTimeout::AUTHENTICATED_AT => 'yesterday'],
                self::at(self::NOW)
            )
        );
    }

    public function test_an_unparseable_epoch_refuses_nobody(): void
    {
        $session = [SessionTimeout::AUTHENTICATED_AT => self::NOW - 60];

        $this->assertFalse(SessionTimeout::predatesCredentialChange($session, 'not a timestamp'));
    }
}
