<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

use App\Modules\Terminals\Enums\DispenserContact;
use App\Modules\Terminals\Enums\DispenserDeviceState;
use App\Modules\Terminals\Enums\DispenserFault;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use App\Modules\Terminals\Exceptions\InvalidDispenserReportException;

/**
 * One terminal's last word about the token dispenser attached to it
 * (ADR-0057, #952).
 *
 * The vocabulary is the terminal's, not a second one invented here: `contact`,
 * `state`, `fault` + `fault_code` are the fields `dispenser_client.dart`
 * already carries, with the same meanings. Three of those meanings are load
 * bearing and easy to lose:
 *
 * - **Availability is `state !== fault`; "needs a human" is `fault !== none`.**
 *   They are different questions. A dispenser that crashed and recovered is
 *   `idle` / `none` with an `error` transaction behind it, and taking it out of
 *   service for that would close a working bar.
 * - **A protocol mismatch is not a device fault.** Nothing is wrong at the
 *   machine; the errand is a deployment one. It is its own `contact` value and
 *   its own unavailable reason, never folded into "offline".
 * - **Counts only.** The terminal's `dispenser_operations` rows carry a member
 *   id; none of that is in this document, and nothing here may grow a field
 *   that identifies a person.
 *
 * The object is built by {@see self::fromWire()}, which either returns a
 * complete, validated report or throws — there is no half-parsed state, and the
 * caller's fail-open behaviour is the `catch`, not a pile of nullable fields.
 */
final readonly class DispenserStatusReport
{
    /**
     * The largest report body the backend will look at, in bytes.
     *
     * The document is display-only and fully described below; anything an order
     * of magnitude past it is either a firmware that has grown something this
     * backend cannot render anyway, or somebody filling a JSON column over a
     * bearer token. Dropping it is the same outcome as any other malformed
     * body — `204`, previous value kept.
     */
    public const MAX_BODY_BYTES = 8192;

    /**
     * Cumulative counters, by the names the device publishes. A key not listed
     * here is dropped rather than stored: the firmware will grow counters this
     * backend has never heard of, and a report carrying one must still be
     * recorded. Growing the panel's view of the hopper therefore means adding
     * the key here — which is the place the question "does an admin need this
     * number?" gets asked.
     *
     * `filtered_pulses` is optional in practice as well as in the schema: the
     * mock dispenser does not emit it.
     */
    public const LIFETIME_KEYS = [
        'requested_tokens',
        'dispensed_tokens',
        'jams',
        'crashes',
        'overrun_tokens',
        'filtered_pulses',
    ];

    /**
     * @param array<string, int> $lifetime
     */
    private function __construct(
        public bool $configured,
        public ?DispenserContact $contact,
        public ?DispenserDeviceState $state,
        public DispenserFault $fault,
        public int $faultCode,
        public ?string $firmware,
        public ?int $protocol,
        public ?int $rssi,
        public ?int $uptimeSeconds,
        public ?string $resetReason,
        public array $lifetime,
        public ?int $pendingReconciliations,
        public ?int $manualReconciliations,
        public ?string $observedAt,
    ) {}

    /**
     * Validate the `dispenser` object of a report body.
     *
     * Two rules, and the difference between them is the whole
     * forward-compatibility story:
     *
     * - **An unknown key is dropped**, never stored and never fatal. The
     *   firmware grows; a backend that refused every document carrying a field
     *   it had not been taught would stop reporting at the first firmware
     *   release and nothing would say why.
     * - **A known key with a value that fails its rule drops the whole
     *   report.** A `state` of `"idle "` or a `fault` of `"stuck"` is a
     *   disagreement about the protocol, not an extension of it, and storing
     *   the rest of a document whose verdict we cannot read would put a green
     *   cell in front of an admin over a machine nobody understood.
     *
     * @throws InvalidDispenserReportException
     */
    public static function fromWire(mixed $wire): self
    {
        if (!is_array($wire) || array_is_list($wire)) {
            throw new InvalidDispenserReportException('dispenser must be an object');
        }

        $configured = $wire['configured'] ?? null;
        if (!is_bool($configured)) {
            throw new InvalidDispenserReportException('dispenser.configured must be a boolean');
        }

        // "No dispenser is attached" is a report, not an absence of one: it is
        // what lets the panel show *no dispenser* rather than *unknown*. It
        // carries nothing else — a terminal with no dispenser has no firmware
        // version, no counters and no fault to describe.
        if ($configured === false) {
            return new self(
                configured: false,
                contact: null,
                state: null,
                fault: DispenserFault::NONE,
                faultCode: 0,
                firmware: null,
                protocol: null,
                rssi: null,
                uptimeSeconds: null,
                resetReason: null,
                lifetime: [],
                pendingReconciliations: null,
                manualReconciliations: null,
                observedAt: self::optionalInstant($wire, 'observed_at'),
            );
        }

        $contact = DispenserContact::tryFromWire($wire['contact'] ?? null);
        if ($contact === null) {
            throw new InvalidDispenserReportException('dispenser.contact must name how the terminal reached the device');
        }

        $state = null;
        if (array_key_exists('state', $wire) && $wire['state'] !== null) {
            $state = DispenserDeviceState::tryFromWire($wire['state']);
            if ($state === null) {
                throw new InvalidDispenserReportException('dispenser.state is not a state this protocol defines');
            }
        }

        $fault = DispenserFault::NONE;
        if (array_key_exists('fault', $wire) && $wire['fault'] !== null) {
            $fault = DispenserFault::tryFromWire($wire['fault'])
                ?? throw new InvalidDispenserReportException('dispenser.fault is not a fault this protocol defines');
        }

        // A device that answered must say what it is doing. Without `state`
        // there is no availability verdict to render, and a document with no
        // verdict is worse than no document: the cell goes green.
        if ($contact === DispenserContact::REPORTED && $state === null) {
            throw new InvalidDispenserReportException('dispenser.state is required when the device reported');
        }

        return new self(
            configured: true,
            contact: $contact,
            state: $state,
            fault: $fault,
            faultCode: self::optionalInt($wire, 'fault_code', min: 0, max: 255) ?? 0,
            firmware: self::optionalString($wire, 'firmware', maxLength: 32),
            protocol: self::optionalInt($wire, 'protocol', min: 0, max: 255),
            // dBm, and always negative in practice. The band is wide enough to
            // hold a radio nobody has met yet without accepting a counter that
            // landed in the wrong field.
            rssi: self::optionalInt($wire, 'rssi', min: -127, max: 0),
            uptimeSeconds: self::optionalInt($wire, 'uptime_s', min: 0),
            resetReason: self::optionalString($wire, 'reset_reason', maxLength: 64),
            lifetime: self::lifetime($wire['lifetime'] ?? null),
            pendingReconciliations: self::optionalInt($wire, 'pending_reconciliations', min: 0),
            manualReconciliations: self::optionalInt($wire, 'manual_reconciliations', min: 0),
            observedAt: self::optionalInstant($wire, 'observed_at'),
        );
    }

    /**
     * Why a token cannot be served right now, or null when one can.
     *
     * Contact is read before the device's own words, because a machine we did
     * not reach has no state we can trust — and because "offline" and
     * "protocol mismatch" send two different people to two different places.
     * A fault outranks `state`, so the specific reason wins over the generic
     * one; `unspecified_fault` catches the `state: fault` a conforming device
     * never sends without naming it, so an unavailable dispenser can never be
     * rendered as available.
     */
    public function unavailableReason(): ?DispenserUnavailableReason
    {
        if (!$this->configured) {
            return null;
        }

        return match (true) {
            $this->contact === DispenserContact::UNREACHABLE => DispenserUnavailableReason::OFFLINE,
            $this->contact === DispenserContact::PROTOCOL_MISMATCH => DispenserUnavailableReason::PROTOCOL_MISMATCH,
            $this->fault === DispenserFault::JAM => DispenserUnavailableReason::JAM,
            $this->fault === DispenserFault::HOPPER_ERROR => DispenserUnavailableReason::HOPPER_ERROR,
            $this->state === DispenserDeviceState::FAULT => DispenserUnavailableReason::UNSPECIFIED_FAULT,
            default => null,
        };
    }

    /** A configured dispenser with no reason not to serve. False when none is attached. */
    public function isAvailable(): bool
    {
        return $this->configured && $this->unavailableReason() === null;
    }

    /**
     * What makes two reports the same episode.
     *
     * `state_since` moves when this changes and not otherwise, so a dispenser
     * reporting `jam` every thirty seconds for an hour is one fault that
     * started an hour ago — which is what the panel shows as *since …* and what
     * #956's dedup key is built on. `fault_code` is in the key because two
     * hopper errors with different codes are two different errands, and
     * `contact` is because a machine that went offline and came back has begun
     * something new.
     */
    public function episodeKey(): string
    {
        return implode('|', [
            $this->configured ? 'configured' : 'absent',
            $this->contact?->value ?? '-',
            $this->state?->value ?? '-',
            $this->fault->value,
            (string) $this->faultCode,
        ]);
    }

    /**
     * The same key, read back off a document that was already stored.
     *
     * Both derivations live here on purpose: `state_since` survives only for as
     * long as the two agree about what "the same episode" means, and two copies
     * of that rule in two classes would drift into a *since …* that resets at
     * random.
     *
     * @param array<string, mixed> $stored
     */
    public static function episodeKeyOf(array $stored): string
    {
        $configured = ($stored['configured'] ?? null) === true;

        return implode('|', [
            $configured ? 'configured' : 'absent',
            is_string($stored['contact'] ?? null) ? $stored['contact'] : '-',
            is_string($stored['state'] ?? null) ? $stored['state'] : '-',
            is_string($stored['fault'] ?? null) ? $stored['fault'] : DispenserFault::NONE->value,
            (string) (int) ($stored['fault_code'] ?? 0),
        ]);
    }

    /**
     * The document as it is stored and served.
     *
     * The three derived fields are stamped in by the backend and never accepted
     * from the wire: the terminal and the panel must not be able to disagree
     * about a document they are both looking at, and a reason that arrived in
     * the body could contradict the `state` beside it.
     *
     * @return array<string, mixed>
     */
    public function toStoredDocument(string $stateSince): array
    {
        return [
            'configured' => $this->configured,
            'contact' => $this->contact?->value,
            'state' => $this->state?->value,
            'fault' => $this->fault->value,
            'fault_code' => $this->faultCode,
            'firmware' => $this->firmware,
            'protocol' => $this->protocol,
            'rssi' => $this->rssi,
            'uptime_s' => $this->uptimeSeconds,
            'reset_reason' => $this->resetReason,
            'lifetime' => (object) $this->lifetime,
            'pending_reconciliations' => $this->pendingReconciliations,
            'manual_reconciliations' => $this->manualReconciliations,
            'observed_at' => $this->observedAt,
            'available' => $this->isAvailable(),
            'unavailable_reason' => $this->unavailableReason()?->value,
            'state_since' => $stateSince,
        ];
    }

    /**
     * @param array<string, mixed> $wire
     * @throws InvalidDispenserReportException
     */
    private static function optionalInt(array $wire, string $key, ?int $min = null, ?int $max = null): ?int
    {
        if (!array_key_exists($key, $wire) || $wire[$key] === null) {
            return null;
        }

        $value = $wire[$key];
        // Strictly an integer: `true` is an int to PHP's loose rules and
        // `"3"` is one to a careless cast, and neither is a number a device
        // meant to send.
        if (!is_int($value)) {
            throw new InvalidDispenserReportException("dispenser.{$key} must be an integer");
        }
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            throw new InvalidDispenserReportException("dispenser.{$key} is out of range");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $wire
     * @throws InvalidDispenserReportException
     */
    private static function optionalString(array $wire, string $key, int $maxLength): ?string
    {
        if (!array_key_exists($key, $wire) || $wire[$key] === null) {
            return null;
        }

        $value = $wire[$key];
        if (!is_string($value)) {
            throw new InvalidDispenserReportException("dispenser.{$key} must be a string");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidDispenserReportException("dispenser.{$key} is longer than {$maxLength} characters");
        }

        return $value;
    }

    /**
     * The device's own clock, normalised to the one spelling everything else on
     * this surface uses (Pattern 020). It is kept beside — never instead of —
     * `dispenser_status_at`, which is when the backend heard it: a kiosk whose
     * clock is a year out must not be able to date a fault.
     *
     * @param array<string, mixed> $wire
     * @throws InvalidDispenserReportException
     */
    private static function optionalInstant(array $wire, string $key): ?string
    {
        if (!array_key_exists($key, $wire) || $wire[$key] === null || $wire[$key] === '') {
            return null;
        }

        $value = $wire[$key];
        if (!is_string($value)) {
            throw new InvalidDispenserReportException("dispenser.{$key} must be a timestamp string");
        }

        // An ISO-8601 instant, checked before it is parsed. PHP's parser is
        // generous to a fault: `new DateTimeImmutable('tuesday')` succeeds and
        // silently means *this coming Tuesday*, so a device sending a word
        // where a timestamp belongs would stamp a plausible date on a status
        // nobody observed.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            throw new InvalidDispenserReportException("dispenser.{$key} is not an ISO-8601 timestamp");
        }

        try {
            $instant = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidDispenserReportException("dispenser.{$key} is not a timestamp");
        }

        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array<string, int>
     * @throws InvalidDispenserReportException
     */
    private static function lifetime(mixed $wire): array
    {
        if ($wire === null) {
            return [];
        }
        if (!is_array($wire) || array_is_list($wire)) {
            throw new InvalidDispenserReportException('dispenser.lifetime must be an object');
        }

        $counters = [];
        foreach (self::LIFETIME_KEYS as $key) {
            try {
                $value = self::optionalInt($wire, $key, min: 0);
            } catch (InvalidDispenserReportException $e) {
                // The helper names the key it was handed; say where it sat.
                throw new InvalidDispenserReportException(
                    str_replace('dispenser.', 'dispenser.lifetime.', $e->getMessage()),
                );
            }
            if ($value !== null) {
                $counters[$key] = $value;
            }
        }

        return $counters;
    }
}
