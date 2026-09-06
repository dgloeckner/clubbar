<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Enums;

use App\Modules\Terminals\Enums\TerminalVersionState;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0054 requirement 10: three states have to be distinguishable on the
 * Terminals page — current, behind, and stuck after a failed update. The third
 * is the one that needs a human, and the second must not be dressed up as it.
 */
class TerminalVersionStateTest extends TestCase
{
    public function test_a_terminal_on_the_backends_version_is_current(): void
    {
        $this->assertSame(
            TerminalVersionState::CURRENT,
            TerminalVersionState::classify('v1.0.7', null, 'v1.0.7'),
        );
    }

    public function test_an_older_terminal_is_behind(): void
    {
        // The ordinary state of every terminal in the club between a backend
        // upgrade and that night's run. Not an alarm.
        $this->assertSame(
            TerminalVersionState::BEHIND,
            TerminalVersionState::classify('v1.0.6', null, 'v1.0.7'),
        );
    }

    public function test_a_terminal_that_blacklisted_the_backends_version_is_blocked(): void
    {
        // It failed the update to v1.0.7 and will never retry it; exact-match
        // means v1.0.7 is also the only tag it would consider. Frozen.
        $this->assertSame(
            TerminalVersionState::BLOCKED,
            TerminalVersionState::classify('v1.0.6', 'v1.0.7', 'v1.0.7'),
        );
    }

    public function test_a_block_the_terminal_has_since_overtaken_is_history_not_an_alarm(): void
    {
        // v1.0.5 failed once; the terminal has since reached v1.0.6 by another
        // route. Keeping it "blocked" would leave an alarm on screen that
        // nothing can clear.
        $this->assertSame(
            TerminalVersionState::BEHIND,
            TerminalVersionState::classify('v1.0.6', 'v1.0.5', 'v1.0.7'),
        );
    }

    public function test_a_newer_terminal_is_ahead_and_is_reported_not_enforced(): void
    {
        // The updater never produces this — a hand-installed terminal, or a
        // backend rolled back under one.
        $this->assertSame(
            TerminalVersionState::AHEAD,
            TerminalVersionState::classify('v1.0.8', null, 'v1.0.7'),
        );
    }

    public function test_a_dev_backend_has_no_opinion_about_any_terminal(): void
    {
        // A club self-hosting from git never auto-updates its terminals, so
        // claiming one is "behind" would name a gap nothing will ever close.
        $this->assertSame(
            TerminalVersionState::UNKNOWN,
            TerminalVersionState::classify('v1.0.7', null, 'dev'),
        );
        $this->assertSame(
            TerminalVersionState::UNKNOWN,
            TerminalVersionState::classify('v1.0.7', null, 'dev-4f2a9c1'),
        );
    }

    public function test_a_terminal_that_has_reported_nothing_is_unknown(): void
    {
        $this->assertSame(
            TerminalVersionState::UNKNOWN,
            TerminalVersionState::classify(null, null, 'v1.0.7'),
        );
        $this->assertSame(
            TerminalVersionState::UNKNOWN,
            TerminalVersionState::classify('dev', null, 'v1.0.7'),
        );
    }

    public function test_an_unparseable_block_never_turns_behind_into_an_alarm(): void
    {
        $this->assertSame(
            TerminalVersionState::BEHIND,
            TerminalVersionState::classify('v1.0.6', 'garbage', 'v1.0.7'),
        );
    }
}
