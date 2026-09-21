<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Enums;

/**
 * Why the dispenser cannot serve a token right now — one reason, always
 * nameable.
 *
 * The kiosk says which of these it is (#948) and so does the admin panel
 * (#953); "unavailable" with no reason is what this enum exists to end. The
 * backend derives it rather than accepting it on the wire, so the terminal and
 * the panel cannot disagree about a document they both have in front of them.
 *
 * Mirrors `DispenserUnavailableReason` in the terminal (`dispenser_client.dart`).
 */
enum DispenserUnavailableReason: string
{
    /** Nothing answered — network, power, wrong address. */
    case OFFLINE = 'offline';

    /** Something answered, in a protocol the terminal does not speak. A
     *  deployment errand: nothing is wrong at the machine. */
    case PROTOCOL_MISMATCH = 'protocol_mismatch';

    /** `fault: jam` — wedged, or empty. */
    case JAM = 'jam';

    /** `fault: hopper_error` — the hopper's own verdict, with its code. */
    case HOPPER_ERROR = 'hopper_error';

    /**
     * `state: fault` with no fault naming it. Not producible by a conforming
     * device; kept so an unavailable dispenser is never rendered as available.
     */
    case UNSPECIFIED_FAULT = 'unspecified_fault';
}
