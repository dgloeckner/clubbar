<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Terminals\DTOs\DispenserStatusReport;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\DispenserStatusService;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recording a dispenser report (ADR-0057, #952).
 *
 * The property under test in most of these is **fail-open**: every refusal must
 * leave the stored document exactly as it was and write nothing. A terminal
 * that cannot file telemetry still has to be able to sell beer, and an admin
 * looking at a stale-but-dated cell is better off than one looking at a cell
 * that was overwritten with whatever a broken firmware sent.
 */
final class DispenserStatusServiceTest extends TestCase
{
    private const TERMINAL = 'a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7';

    /** @return array<string, mixed> */
    private static function body(array $dispenser = []): array
    {
        return ['dispenser' => array_merge([
            'configured' => true,
            'contact' => 'reported',
            'state' => 'idle',
            'fault' => 'none',
            'firmware' => '1.2.0',
            'protocol' => 2,
            'lifetime' => ['requested_tokens' => 10, 'dispensed_tokens' => 10],
            'observed_at' => '2026-09-20T18:03:11Z',
        ], $dispenser)];
    }

    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function service(TerminalsRepository $terminals): DispenserStatusService
    {
        return new DispenserStatusService($terminals, $this->createMock(Logger::class));
    }

    public function test_a_valid_report_is_stored_with_the_time_it_arrived(): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findDispenserStatus')->willReturn(null);

        $stored = null;
        $terminals->expects($this->once())
            ->method('updateDispenserStatus')
            ->willReturnCallback(function (string $id, string $document, string $at) use (&$stored) {
                $this->assertSame(self::TERMINAL, $id);
                $this->assertSame('2026-09-20 18:05:00', $at);
                $stored = json_decode($document, true);

                return true;
            });

        $written = $this->service($terminals)
            ->record(self::TERMINAL, self::body(), null, self::at('2026-09-20 18:05:00'));

        $this->assertTrue($written);
        $this->assertTrue($stored['available']);
        $this->assertNull($stored['unavailable_reason']);
        $this->assertSame('2026-09-20T18:05:00.000Z', $stored['state_since']);
    }

    #[DataProvider('bodiesThatAreDropped')]
    public function test_a_body_it_cannot_read_writes_nothing(mixed $body): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->expects($this->never())->method('updateDispenserStatus');

        $this->assertFalse($this->service($terminals)->record(self::TERMINAL, $body));
    }

    /** @return array<string, array{0: mixed}> */
    public static function bodiesThatAreDropped(): array
    {
        return [
            'nothing at all' => [null],
            'a string' => ['jam'],
            'a list' => [[1, 2, 3]],
            'no envelope' => [['configured' => true, 'state' => 'idle']],
            'an envelope holding a string' => [['dispenser' => 'idle']],
            'a state outside the protocol' => [self::body(['state' => 'refilling'])],
            'a fault outside the protocol' => [self::body(['fault' => 'stuck'])],
        ];
    }

    /**
     * The column is display-only and fully described by the schema; anything an
     * order of magnitude past it is either a firmware this backend could not
     * render anyway or somebody filling a JSON column over a bearer token.
     */
    public function test_an_oversized_body_writes_nothing(): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->expects($this->never())->method('updateDispenserStatus');

        $this->assertFalse($this->service($terminals)->record(
            self::TERMINAL,
            self::body(),
            DispenserStatusReport::MAX_BODY_BYTES + 1,
        ));
    }

    public function test_a_body_at_the_cap_is_still_read(): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findDispenserStatus')->willReturn(null);
        $terminals->expects($this->once())->method('updateDispenserStatus')->willReturn(true);

        $this->assertTrue($this->service($terminals)->record(
            self::TERMINAL,
            self::body(),
            DispenserStatusReport::MAX_BODY_BYTES,
        ));
    }

    /**
     * A jam re-reported every thirty seconds for an hour is one fault that
     * started an hour ago. That is what the panel shows as *since …* and what
     * #956's deduplication key is built on, so it must not reset on every
     * report.
     */
    public function test_the_same_condition_keeps_the_stamp_it_began_with(): void
    {
        $previous = DispenserStatusReport::fromWire(self::body([
            'state' => 'fault',
            'fault' => 'jam',
        ])['dispenser'])->toStoredDocument('2026-09-20T17:00:00Z');

        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findDispenserStatus')->willReturn($previous);

        $stored = null;
        $terminals->method('updateDispenserStatus')
            ->willReturnCallback(function (string $id, string $document) use (&$stored) {
                $stored = json_decode($document, true);

                return true;
            });

        // Same jam, an hour later, with the counters moved on.
        $this->service($terminals)->record(
            self::TERMINAL,
            self::body([
                'state' => 'fault',
                'fault' => 'jam',
                'lifetime' => ['requested_tokens' => 99, 'dispensed_tokens' => 90],
            ]),
            null,
            self::at('2026-09-20 18:00:00'),
        );

        $this->assertSame('2026-09-20T17:00:00Z', $stored['state_since']);
    }

    public function test_a_new_condition_starts_a_new_stamp(): void
    {
        $previous = DispenserStatusReport::fromWire(self::body()['dispenser'])
            ->toStoredDocument('2026-09-20T17:00:00Z');

        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findDispenserStatus')->willReturn($previous);

        $stored = null;
        $terminals->method('updateDispenserStatus')
            ->willReturnCallback(function (string $id, string $document) use (&$stored) {
                $stored = json_decode($document, true);

                return true;
            });

        $this->service($terminals)->record(
            self::TERMINAL,
            self::body(['state' => 'fault', 'fault' => 'jam']),
            null,
            self::at('2026-09-20 18:00:00'),
        );

        $this->assertSame('2026-09-20T18:00:00.000Z', $stored['state_since']);
        $this->assertSame('jam', $stored['unavailable_reason']);
    }

    /**
     * The stamp comes from the backend's clock, not from the device's. A kiosk
     * whose clock is a year out would otherwise date a fault to next spring,
     * and every surface downstream reads this as an age.
     */
    public function test_a_wrong_device_clock_cannot_date_a_fault(): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findDispenserStatus')->willReturn(null);

        $stored = null;
        $terminals->method('updateDispenserStatus')
            ->willReturnCallback(function (string $id, string $document) use (&$stored) {
                $stored = json_decode($document, true);

                return true;
            });

        $this->service($terminals)->record(
            self::TERMINAL,
            self::body(['observed_at' => '2027-04-01T09:00:00Z']),
            null,
            self::at('2026-09-20 18:00:00'),
        );

        $this->assertSame('2026-09-20T18:00:00.000Z', $stored['state_since']);
        // The device's own claim is kept beside it, never instead of it.
        $this->assertSame('2027-04-01T09:00:00Z', $stored['observed_at']);
    }

    /**
     * A stored document this backend cannot decode — a column nothing else
     * writes — reads as *never reported*, and the next report simply starts a
     * fresh episode rather than throwing on the sync path.
     */
    public function test_an_unreadable_previous_document_does_not_block_the_next_report(): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findDispenserStatus')->willReturn(null);
        $terminals->expects($this->once())->method('updateDispenserStatus')->willReturn(true);

        $this->assertTrue($this->service($terminals)
            ->record(self::TERMINAL, self::body(), null, self::at('2026-09-20 18:00:00')));
    }

    /** A terminal saying it has no dispenser is a report, and is stored as one. */
    public function test_no_dispenser_attached_is_recorded(): void
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findDispenserStatus')->willReturn(null);

        $stored = null;
        $terminals->method('updateDispenserStatus')
            ->willReturnCallback(function (string $id, string $document) use (&$stored) {
                $stored = json_decode($document, true);

                return true;
            });

        $this->assertTrue($this->service($terminals)->record(
            self::TERMINAL,
            ['dispenser' => ['configured' => false]],
            null,
            self::at('2026-09-20 18:00:00'),
        ));
        $this->assertFalse($stored['configured']);
        $this->assertNull($stored['unavailable_reason']);
    }
}
