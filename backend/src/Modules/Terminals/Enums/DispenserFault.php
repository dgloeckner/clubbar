<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Enums;

/**
 * The device-level fault — the field that names a dispenser's errand.
 *
 * Anything other than {@see self::NONE} means *a human has to go there*, and
 * there is no way to clear it from any surface: the device has no reset route,
 * a jam is cleared by a power cycle (owner decision 3 of epic #944). An ops
 * "clear fault" button would be a promise the machine cannot keep, which is
 * why nothing in this module writes this value.
 *
 * Mirrors `DispenserFault` in the terminal (`dispenser_client.dart`).
 */
enum DispenserFault: string
{
    case NONE = 'none';

    /** The 5 s jam watchdog: nothing came out. Wedged, or the hopper is empty. */
    case JAM = 'jam';

    /** The hopper reported a fault on its error line; `fault_code` (1-7) names it. */
    case HOPPER_ERROR = 'hopper_error';

    public static function tryFromWire(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
