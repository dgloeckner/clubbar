<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\DTOs;

use App\Modules\Notifications\DTOs\DrainResultDto;
use App\Modules\Notifications\Enums\DrainSource;
use PHPUnit\Framework\TestCase;

/**
 * The one distinction a drain's summary line has to carry (#463).
 *
 * `DrainService::run()` catches everything, because a crontab nobody reads is
 * no place to propagate an exception. The cost of that — until this — was that
 * a run which threw printed the same all-zero counters as a run with nothing to
 * do. Somebody testing the command by hand, which is exactly what
 * `docs/deployment.md` tells an operator to do, read a stopped queue as a
 * healthy idle one. It is how the announcement chain stayed green over a drain
 * that aborted on its first message.
 */
class DrainResultDtoTest extends TestCase
{
    public function test_an_idle_run_reports_counters(): void
    {
        $summary = DrainResultDto::idle(DrainSource::CLI, 0.12)->summary();

        $this->assertStringContainsString('claimed=0', $summary);
        $this->assertStringContainsString('sent=0', $summary);
        $this->assertFalse(DrainResultDto::idle(DrainSource::CLI)->wasAborted());
    }

    /**
     * An aborted run does **not** print counters, and that is the point.
     *
     * Zero here would be a claim about the queue, and it is not one: messages
     * may already have been sent, and rows may already have been claimed and
     * left claimed, before whatever threw.
     */
    public function test_an_aborted_run_says_so_instead_of_reporting_zeroes(): void
    {
        $result = DrainResultDto::aborted(DrainSource::CLI, 'PDOException: Data truncated for column', 1.5);

        $this->assertTrue($result->wasAborted());
        $this->assertStringContainsString('ABORTED', $result->summary());
        $this->assertStringContainsString('Data truncated', $result->summary());
        $this->assertStringNotContainsString(
            'sent=0',
            $result->summary(),
            'a zero here reads as a fact about the queue, and the run never established one'
        );
    }

    /** The log gets the reason too, so a run can be diagnosed after the fact. */
    public function test_the_reason_reaches_the_log_context(): void
    {
        $array = DrainResultDto::aborted(DrainSource::URL, 'boom')->toArray();

        $this->assertSame('boom', $array['aborted_reason']);
        $this->assertNull(DrainResultDto::idle(DrainSource::URL)->toArray()['aborted_reason']);
    }
}
