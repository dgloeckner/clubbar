<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Enums;

/**
 * How the terminal came by the dispenser document it is reporting.
 *
 * This is deliberately *not* the device's own state: it answers "did we hear
 * from the machine, and did we understand it", which is a question about the
 * link, not about the hopper. Folding the two together is what epic finding 13
 * is about — a protocol mismatch reported as "offline" sends somebody to look
 * for a power cable on a machine that is running perfectly.
 *
 * Mirrors `DispenserContact` in the terminal (`dispenser_client.dart`); the
 * backing strings are the wire values.
 */
enum DispenserContact: string
{
    /** A protocol-2 `/health` document the terminal parsed. */
    case REPORTED = 'reported';

    /** Nothing answered — network, power, wrong address. */
    case UNREACHABLE = 'unreachable';

    /** Something answered, in a protocol this terminal does not speak. */
    case PROTOCOL_MISMATCH = 'protocol_mismatch';

    public static function tryFromWire(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
