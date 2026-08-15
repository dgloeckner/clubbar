<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\DTOs;

use App\Modules\Terminals\DTOs\AnomalyScanResultDto;
use App\Modules\Terminals\Enums\TerminalAnomalyKind;
use PHPUnit\Framework\TestCase;

/**
 * What one anomaly scan reports (ADR-0041 §2).
 *
 * `opened` and `refreshed` are counted apart on purpose, and the summary line
 * is what the cron's stdout and the crontab mail actually carry — an admin
 * reading a nightly cron report should be able to tell "nothing new" from
 * "something new" without opening the panel.
 */
class AnomalyScanResultDtoTest extends TestCase
{
    public function testAnIdleScanReportsZeroes(): void
    {
        $result = new AnomalyScanResultDto();

        $this->assertSame(0, $result->opened);
        $this->assertSame(0, $result->refreshed);
        $this->assertSame(0, $result->mailsQueued);
        $this->assertSame(0, $result->sightingsPruned);
    }

    public function testTheSummaryNamesEveryCount(): void
    {
        $summary = (new AnomalyScanResultDto(
            terminalsExamined: 3,
            opened: 1,
            refreshed: 2,
            mailsQueued: 4,
            sightingsPruned: 120,
            durationSeconds: 1.2345,
        ))->summary();

        $this->assertStringContainsString('terminals=3', $summary);
        $this->assertStringContainsString('opened=1', $summary);
        $this->assertStringContainsString('refreshed=2', $summary);
        $this->assertStringContainsString('mails=4', $summary);
        $this->assertStringContainsString('pruned=120', $summary);
        $this->assertStringContainsString('1.23s', $summary);
    }

    /**
     * An ongoing anomaly is refreshed on every tick and is not news; only an
     * opened one is the moment somebody needs to be told. A summary that
     * conflated them would make a quiet night look like an incident.
     */
    public function testRefreshingIsDistinguishableFromOpening(): void
    {
        $quiet = new AnomalyScanResultDto(terminalsExamined: 1, refreshed: 5);

        $this->assertStringContainsString('opened=0', $quiet->summary());
        $this->assertStringContainsString('refreshed=5', $quiet->summary());
    }

    public function testItSerialisesToSnakeCase(): void
    {
        $array = (new AnomalyScanResultDto(
            terminalsExamined: 2,
            opened: 1,
            refreshed: 0,
            mailsQueued: 3,
            sightingsPruned: 7,
            durationSeconds: 0.5,
        ))->toArray();

        $this->assertSame([
            'terminals_examined' => 2,
            'opened' => 1,
            'refreshed' => 0,
            'mails_queued' => 3,
            'sightings_pruned' => 7,
            'duration_seconds' => 0.5,
        ], $array);
    }

    /**
     * Concurrent use is the only kind with no routine innocent cause, so it is
     * the only one that reads as an error rather than a warning.
     */
    public function testOnlyConcurrentUseIsAnError(): void
    {
        $this->assertSame('error', TerminalAnomalyKind::CONCURRENT_IP->severity());
        $this->assertSame('warning', TerminalAnomalyKind::CURSOR_REGRESSION->severity());
        $this->assertSame('warning', TerminalAnomalyKind::CURSOR_RESET->severity());
    }
}
