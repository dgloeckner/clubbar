<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\DTOs;

use App\Modules\Terminals\DTOs\DispenserStatusReport;
use App\Modules\Terminals\Enums\DispenserContact;
use App\Modules\Terminals\Enums\DispenserDeviceState;
use App\Modules\Terminals\Enums\DispenserFault;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use App\Modules\Terminals\Exceptions\InvalidDispenserReportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator behind `PUT /api/sync/terminal-status` (ADR-0057, #952).
 *
 * Most of what is pinned here is a *distinction* rather than a value, because
 * the distinctions are what the epic found broken elsewhere: availability is
 * not the same question as "does a human have to go there", and a protocol
 * mismatch is not a device fault.
 */
final class DispenserStatusReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function healthy(array $overrides = []): array
    {
        return array_merge([
            'configured' => true,
            'contact' => 'reported',
            'state' => 'idle',
            'fault' => 'none',
            'fault_code' => 0,
            'firmware' => '1.2.0',
            'protocol' => 2,
            'rssi' => -61,
            'uptime_s' => 86400,
            'reset_reason' => 'Power On',
            'lifetime' => [
                'requested_tokens' => 1412,
                'dispensed_tokens' => 1409,
                'jams' => 3,
                'crashes' => 0,
                'overrun_tokens' => 1,
            ],
            'pending_reconciliations' => 0,
            'manual_reconciliations' => 0,
            'observed_at' => '2026-09-20T18:03:11Z',
        ], $overrides);
    }

    public function test_it_accepts_the_document_the_terminal_sends(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy());

        $this->assertTrue($report->configured);
        $this->assertSame(DispenserContact::REPORTED, $report->contact);
        $this->assertSame(DispenserDeviceState::IDLE, $report->state);
        $this->assertSame(DispenserFault::NONE, $report->fault);
        $this->assertSame('1.2.0', $report->firmware);
        $this->assertSame(2, $report->protocol);
        $this->assertSame(-61, $report->rssi);
        $this->assertSame(1409, $report->lifetime['dispensed_tokens']);
        $this->assertTrue($report->isAvailable());
        $this->assertNull($report->unavailableReason());
    }

    /**
     * `configured: false` is a report, not the absence of one — it is what lets
     * the panel say *no dispenser* instead of *unknown*, and the two must not
     * collapse into each other.
     */
    public function test_a_terminal_with_no_dispenser_round_trips(): void
    {
        $report = DispenserStatusReport::fromWire(['configured' => false]);

        $this->assertFalse($report->configured);
        $this->assertNull($report->contact);
        $this->assertNull($report->state);
        $this->assertFalse($report->isAvailable());
        // There is no dispenser, so there is nothing that is unavailable.
        $this->assertNull($report->unavailableReason());

        $document = $report->toStoredDocument('2026-09-20T18:00:00Z');
        $this->assertFalse($document['configured']);
        $this->assertFalse($document['available']);
        $this->assertNull($document['unavailable_reason']);
    }

    /**
     * A terminal that never said whether one is attached has reported nothing
     * usable — the panel's *unknown* is the absence of a row, not a document
     * with a blank in it.
     */
    public function test_a_report_without_configured_is_refused(): void
    {
        $this->expectException(InvalidDispenserReportException::class);
        DispenserStatusReport::fromWire(['contact' => 'reported', 'state' => 'idle']);
    }

    #[DataProvider('outsideTheProtocol')]
    public function test_a_value_outside_the_protocol_drops_the_report(array $overrides): void
    {
        $this->expectException(InvalidDispenserReportException::class);
        DispenserStatusReport::fromWire(self::healthy($overrides));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function outsideTheProtocol(): array
    {
        return [
            'an invented state' => [['state' => 'refilling']],
            'an invented fault' => [['fault' => 'stuck']],
            'an invented contact' => [['contact' => 'maybe']],
            'a state with whitespace' => [['state' => 'idle ']],
            'a numeric string where an integer belongs' => [['protocol' => '2']],
            'a boolean where an integer belongs' => [['fault_code' => true]],
            'a negative counter' => [['lifetime' => ['jams' => -1]]],
            'an rssi out of band' => [['rssi' => 40]],
            'a fault code out of band' => [['fault_code' => 900]],
            'a firmware string longer than the column' => [['firmware' => str_repeat('v', 33)]],
            'a firmware that is not a string' => [['firmware' => 120]],
            'a reset reason that is not a string' => [['reset_reason' => ['Power On']]],
            'a timestamp that is not a string' => [['observed_at' => 1758391391]],
            'a date that does not exist' => [['observed_at' => '2026-13-45T25:61:61Z']],
            'a timestamp that is not one' => [['observed_at' => 'not-a-time']],
            'a lifetime that is a list' => [['lifetime' => [1, 2, 3]]],
            'a timestamp that is a relative word' => [['observed_at' => 'tuesday']],
        ];
    }

    public function test_a_list_is_not_a_dispenser_document(): void
    {
        $this->expectException(InvalidDispenserReportException::class);
        DispenserStatusReport::fromWire(['idle', 'none']);
    }

    /**
     * A device that answered must say what it is doing. Without a state there
     * is no verdict to render, and a document with no verdict is worse than no
     * document: the cell goes green.
     */
    public function test_a_device_that_reported_must_name_its_state(): void
    {
        $this->expectException(InvalidDispenserReportException::class);
        DispenserStatusReport::fromWire(self::healthy(['state' => null]));
    }

    /**
     * The firmware grows. A backend that refused every document carrying a
     * field it had not been taught would stop reporting at the first firmware
     * release, and nothing would say why.
     */
    public function test_an_unknown_key_is_dropped_rather_than_refused(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy([
            'hopper_temperature_c' => 31,
            'lifetime' => ['jams' => 3, 'coins_rejected' => 7],
        ]));

        $document = $report->toStoredDocument('2026-09-20T18:00:00Z');
        $this->assertArrayNotHasKey('hopper_temperature_c', $document);
        $this->assertSame(['jams' => 3], (array) $document['lifetime']);
    }

    /** `filtered_pulses` is optional in practice too — the mock never emits it. */
    public function test_an_absent_counter_is_simply_absent(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy());

        $this->assertArrayNotHasKey('filtered_pulses', $report->lifetime);
        $this->assertSame(1, $report->lifetime['overrun_tokens']);
    }

    #[DataProvider('verdicts')]
    public function test_it_names_one_reason_and_only_one(
        array $overrides,
        ?DispenserUnavailableReason $expected,
    ): void {
        $report = DispenserStatusReport::fromWire(self::healthy($overrides));

        $this->assertSame($expected, $report->unavailableReason());
        $this->assertSame($expected === null, $report->isAvailable());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?DispenserUnavailableReason}> */
    public static function verdicts(): array
    {
        return [
            'idle and faultless serves' => [[], null],
            'dispensing still serves' => [['state' => 'dispensing'], null],
            'nothing answered' => [
                ['contact' => 'unreachable', 'state' => null],
                DispenserUnavailableReason::OFFLINE,
            ],
            // Epic finding 13: nothing is wrong at the machine, and folding
            // this into "offline" sends somebody after a power cable.
            'a protocol nobody here speaks' => [
                ['contact' => 'protocol_mismatch', 'state' => null, 'protocol' => 1],
                DispenserUnavailableReason::PROTOCOL_MISMATCH,
            ],
            'a jam' => [
                ['state' => 'fault', 'fault' => 'jam'],
                DispenserUnavailableReason::JAM,
            ],
            'a hopper error' => [
                ['state' => 'fault', 'fault' => 'hopper_error', 'fault_code' => 4],
                DispenserUnavailableReason::HOPPER_ERROR,
            ],
            // Not producible by a conforming device; kept so an unavailable
            // dispenser can never be rendered as available.
            'a fault with nothing naming it' => [
                ['state' => 'fault', 'fault' => 'none'],
                DispenserUnavailableReason::UNSPECIFIED_FAULT,
            ],
            // A fault outranks the generic state, so the specific reason wins.
            'a named fault outranks the generic state' => [
                ['state' => 'fault', 'fault' => 'jam', 'fault_code' => 0],
                DispenserUnavailableReason::JAM,
            ],
            // A machine we did not reach has no state worth believing, even
            // when a stale one is still in the field.
            'contact outranks a stale state' => [
                ['contact' => 'unreachable', 'state' => 'idle'],
                DispenserUnavailableReason::OFFLINE,
            ],
        ];
    }

    /**
     * The distinction T4 landed on the terminal, asserted from this side: a
     * controller that crashed and came back is `idle` / `none` with an `error`
     * transaction behind it. It counts a crash and it stays in service.
     */
    public function test_a_recovered_crash_does_not_take_the_machine_out_of_service(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy([
            'state' => 'idle',
            'fault' => 'none',
            'reset_reason' => 'Software Reset',
            'lifetime' => ['crashes' => 1],
        ]));

        $this->assertTrue($report->isAvailable());
        $this->assertNull($report->unavailableReason());
        $this->assertSame(1, $report->lifetime['crashes']);
    }

    public function test_the_device_clock_is_normalised_to_utc(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy([
            'observed_at' => '2026-09-20T20:03:11+02:00',
        ]));

        $this->assertSame('2026-09-20T18:03:11Z', $report->observedAt);
    }

    /**
     * `state_since` survives only while both derivations agree about what "the
     * same episode" means. Two copies of that rule would drift into a *since …*
     * that resets at random.
     */
    public function test_the_episode_key_matches_the_one_read_off_a_stored_document(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy([
            'state' => 'fault',
            'fault' => 'hopper_error',
            'fault_code' => 4,
        ]));

        $stored = $report->toStoredDocument('2026-09-20T18:00:00Z');

        $this->assertSame($report->episodeKey(), DispenserStatusReport::episodeKeyOf($stored));
    }

    #[DataProvider('differentEpisodes')]
    public function test_a_changed_condition_is_a_different_episode(array $overrides): void
    {
        $first = DispenserStatusReport::fromWire(self::healthy());
        $second = DispenserStatusReport::fromWire(self::healthy($overrides));

        $this->assertNotSame($first->episodeKey(), $second->episodeKey());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function differentEpisodes(): array
    {
        return [
            'the state changed' => [['state' => 'dispensing']],
            'a fault began' => [['state' => 'fault', 'fault' => 'jam']],
            'the contact was lost' => [['contact' => 'unreachable', 'state' => null]],
        ];
    }

    /**
     * Counters, uptime and signal move on every single report. If they were in
     * the key, every report would start a new episode and *since …* would
     * always read "just now".
     */
    public function test_counters_moving_is_the_same_episode(): void
    {
        $first = DispenserStatusReport::fromWire(self::healthy());
        $second = DispenserStatusReport::fromWire(self::healthy([
            'uptime_s' => 90000,
            'rssi' => -70,
            'lifetime' => ['requested_tokens' => 1500, 'dispensed_tokens' => 1497],
            'observed_at' => '2026-09-20T19:03:11Z',
        ]));

        $this->assertSame($first->episodeKey(), $second->episodeKey());
    }

    /**
     * The verdict is stamped in by the backend and never taken from the wire:
     * a reason that arrived in the body could contradict the `state` beside it,
     * and then the kiosk and the panel describe one machine differently.
     */
    public function test_a_verdict_in_the_body_is_ignored(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy([
            'state' => 'fault',
            'fault' => 'jam',
            'available' => true,
            'unavailable_reason' => null,
            'state_since' => '1999-01-01T00:00:00Z',
        ]));

        $document = $report->toStoredDocument('2026-09-20T18:00:00Z');
        $this->assertFalse($document['available']);
        $this->assertSame('jam', $document['unavailable_reason']);
        $this->assertSame('2026-09-20T18:00:00Z', $document['state_since']);
    }

    /**
     * Counts only. The terminal's `dispenser_operations` rows carry a member
     * id; none of that may reach this column, whatever a firmware decides to
     * put in the document.
     */
    public function test_the_stored_document_carries_no_field_the_schema_did_not_name(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy([
            'member_id' => 'a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7',
            'card_uid' => 'A1B2C3D4',
            'last_member' => 'Max Muster',
        ]));

        $document = $report->toStoredDocument('2026-09-20T18:00:00Z');

        $this->assertSame([
            'configured', 'contact', 'state', 'fault', 'fault_code', 'firmware',
            'protocol', 'rssi', 'uptime_s', 'reset_reason', 'lifetime',
            'pending_reconciliations', 'manual_reconciliations', 'observed_at',
            'available', 'unavailable_reason', 'state_since',
        ], array_keys($document));
    }

    /**
     * A device that has a field and nothing to put in it sends an empty
     * string. That is *absent*, not a firmware version of `""` — a panel
     * rendering the empty string would show a blank where it should show "not
     * reported".
     */
    public function test_a_blank_string_is_absent_rather_than_a_value(): void
    {
        $report = DispenserStatusReport::fromWire(self::healthy([
            'firmware' => '   ',
            'reset_reason' => '',
            'observed_at' => '',
        ]));

        $this->assertNull($report->firmware);
        $this->assertNull($report->resetReason);
        $this->assertNull($report->observedAt);
    }

    /** A document with no counters at all is a document, not a refusal. */
    public function test_a_report_with_no_counters_is_accepted(): void
    {
        $report = DispenserStatusReport::fromWire([
            'configured' => true,
            'contact' => 'reported',
            'state' => 'idle',
        ]);

        $this->assertSame([], $report->lifetime);
        $this->assertTrue($report->isAvailable());
        $this->assertSame(DispenserFault::NONE, $report->fault);
    }
}
