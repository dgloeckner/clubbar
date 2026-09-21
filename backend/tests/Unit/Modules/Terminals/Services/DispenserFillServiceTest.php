<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\DispenserFillService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Recording a refill, and what the estimate reads afterwards (#955, ADR-0058).
 *
 * The property most of these defend is that the estimate stays **anchored to a
 * counted number**. It is arithmetic, not a measurement: the hopper has no
 * empty sensor, the device's counters are cumulative and RAM-only, and no
 * history of its reports is kept. A refill is therefore the only moment the
 * fill level is ever known, and everything after it is a subtraction whose
 * drift is bounded by the next count.
 */
final class DispenserFillServiceTest extends TestCase
{
    private const TERMINAL = 'a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7';

    /** @return array<string, mixed> */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'id' => self::TERMINAL,
            'dispenser_refilled_at' => '2026-09-20 18:00:00',
            'dispenser_refill_tokens' => 400,
            'dispenser_low_threshold' => 20,
        ], $overrides);
    }

    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed>|null $row
     * @param array<string, int>        $sold
     */
    private function terminals(?array $row, array $sold = []): TerminalsRepository
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findById')->willReturn($row);
        $terminals->method('countTokensSoldSinceRefill')->willReturn($sold);

        return $terminals;
    }

    public function test_the_estimate_is_the_refill_minus_what_sold_since(): void
    {
        $service = new DispenserFillService(
            $this->terminals(self::row(), [self::TERMINAL => 137]),
            $this->createMock(AuditService::class),
        );

        $fill = $service->readFor(self::row());

        $this->assertSame(400, $fill->refillTokens);
        $this->assertSame(137, $fill->soldSince);
        $this->assertSame(263, $fill->estimatedLeft());
        $this->assertFalse($fill->isLow());
        $this->assertFalse($fill->isExhausted());
    }

    /**
     * A terminal that has sold nothing since its refill is simply absent from
     * the grouped count. That zero is one the caller *asked* for — unlike the
     * null a code path that never counted passes, which reads as no estimate.
     */
    public function test_a_terminal_with_no_sales_since_the_refill_reads_as_full(): void
    {
        $service = new DispenserFillService(
            $this->terminals(self::row(), []),
            $this->createMock(AuditService::class),
        );

        $this->assertSame(400, $service->readFor(self::row())->estimatedLeft());
    }

    public function test_no_refill_recorded_means_no_estimate(): void
    {
        $service = new DispenserFillService(
            $this->terminals(null, [self::TERMINAL => 90]),
            $this->createMock(AuditService::class),
        );

        $fill = $service->readFor(self::row([
            'dispenser_refilled_at' => null,
            'dispenser_refill_tokens' => null,
        ]));

        $this->assertNull($fill->estimatedLeft());
        $this->assertNull($fill->soldSince);
        $this->assertFalse($fill->isLow());
        $this->assertFalse($fill->isExhausted());
        // The threshold is a stored setting and survives the absence of an
        // estimate — what is missing is the number to compare against it.
        $this->assertSame(20, $fill->lowThreshold);
    }

    public function test_at_or_below_the_threshold_the_estimate_reads_low(): void
    {
        $service = new DispenserFillService(
            $this->terminals(self::row(), [self::TERMINAL => 380]),
            $this->createMock(AuditService::class),
        );

        $fill = $service->readFor(self::row());

        $this->assertSame(20, $fill->estimatedLeft());
        $this->assertTrue($fill->isLow());
        $this->assertFalse($fill->isExhausted());
    }

    public function test_estimate_at_or_below_zero_is_reported_as_exhausted(): void
    {
        // More sold than were counted in: the drift the arithmetic cannot see
        // has caught up with it. It reads as used up, never as "-11 tokens",
        // which would be false precision about an unmeasured quantity.
        $service = new DispenserFillService(
            $this->terminals(self::row(), [self::TERMINAL => 411]),
            $this->createMock(AuditService::class),
        );

        $fill = $service->readFor(self::row());

        $this->assertSame(0, $fill->estimatedLeft());
        $this->assertTrue($fill->isExhausted());
        $this->assertTrue($fill->isLow());
    }

    public function test_refill_replaces_the_total_and_restarts_the_count(): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findById')->willReturn(self::row());
        $terminals->method('countTokensSoldSinceRefill')->willReturn([self::TERMINAL => 390]);

        $written = null;
        $terminals->expects($this->once())
            ->method('recordDispenserRefill')
            ->willReturnCallback(function (string $id, int $tokens, string $at) use (&$written) {
                $written = [$id, $tokens, $at];

                return true;
            });

        $fill = (new DispenserFillService($terminals, $this->createMock(AuditService::class)))
            ->recordRefill(self::TERMINAL, 500, 'admin-1', self::at('2026-09-21 09:30:00'));

        $this->assertSame([self::TERMINAL, 500, '2026-09-21 09:30:00'], $written);
        // The new count *replaces* the old estimate rather than adding to it:
        // 500 in the hopper, not 500 plus the 10 the arithmetic still believed
        // in. The sales are counted again against the new anchor rather than
        // assumed to be zero — the stub here still answers 390, and a response
        // that ignored that would disagree with the next read of the same row.
        $this->assertSame(500, $fill->refillTokens);
        $this->assertSame(390, $fill->soldSince);
        $this->assertSame(20, $fill->lowThreshold);
    }

    public function test_refill_audit_row_carries_old_estimate_and_new_count(): void
    {
        $audit = $this->createMock(AuditService::class);

        $logged = null;
        $audit->expects($this->once())
            ->method('log')
            ->willReturnCallback(function (
                AuditAction $action,
                EntityType $entityType,
                string $entityId,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $adminUserId = null,
            ) use (&$logged) {
                $logged = compact('action', 'entityType', 'entityId', 'oldValues', 'newValues', 'adminUserId');
            });

        (new DispenserFillService($this->terminals(self::row(), [self::TERMINAL => 390]), $audit))
            ->recordRefill(self::TERMINAL, 500, 'admin-1', self::at('2026-09-21 09:30:00'));

        $this->assertSame(AuditAction::TERMINAL_DISPENSER_REFILLED, $logged['action']);
        $this->assertSame(EntityType::TERMINAL, $logged['entityType']);
        $this->assertSame(self::TERMINAL, $logged['entityId']);
        $this->assertSame('admin-1', $logged['adminUserId']);
        // The pair that makes the drift visible: what the arithmetic believed a
        // moment ago, beside what somebody counted. 10 against 500 is not a
        // refill of 490 — it is a hopper that was nowhere near empty, or an
        // estimate that had drifted, and only the log can ever say which.
        $this->assertSame(10, $logged['oldValues']['estimated_left']);
        $this->assertSame(400, $logged['oldValues']['refill_tokens']);
        $this->assertSame(390, $logged['oldValues']['sold_since']);
        $this->assertSame(500, $logged['newValues']['refill_tokens']);
        $this->assertSame('2026-09-21 09:30:00', $logged['newValues']['refilled_at']);
    }

    /**
     * The first refill a terminal ever gets has no drift to report, and a zero
     * there would invent one.
     */
    public function test_the_first_refill_reports_no_previous_estimate(): void
    {
        $audit = $this->createMock(AuditService::class);

        $old = null;
        $audit->method('log')->willReturnCallback(
            function (AuditAction $a, EntityType $e, string $id, ?array $oldValues = null) use (&$old) {
                $old = $oldValues;
            },
        );

        $row = self::row(['dispenser_refilled_at' => null, 'dispenser_refill_tokens' => null]);

        (new DispenserFillService($this->terminals($row), $audit))
            ->recordRefill(self::TERMINAL, 400, 'admin-1', self::at('2026-09-21 09:30:00'));

        $this->assertNull($old['estimated_left']);
        $this->assertNull($old['refilled_at']);
        $this->assertNull($old['sold_since']);
    }

    public function test_a_refill_for_an_unknown_terminal_is_refused(): void
    {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('log');

        $this->expectException(NotFoundException::class);

        (new DispenserFillService($this->terminals(null), $audit))
            ->recordRefill(self::TERMINAL, 400, 'admin-1');
    }

    /**
     * Zero is a count, not a refusal: a hopper emptied for maintenance is a
     * known state, and the estimate should say *exhausted* rather than carry on
     * from whatever it believed before somebody emptied it.
     */
    public function test_a_refill_of_zero_is_a_count_like_any_other(): void
    {
        $fill = (new DispenserFillService(
            $this->terminals(self::row(), [self::TERMINAL => 12]),
            $this->createMock(AuditService::class),
        ))->recordRefill(self::TERMINAL, 0, 'admin-1', self::at('2026-09-21 09:30:00'));

        $this->assertSame(0, $fill->refillTokens);
        $this->assertSame(0, $fill->estimatedLeft());
        $this->assertTrue($fill->isExhausted());
    }
}
