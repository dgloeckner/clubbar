<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Enums;

/**
 * What the device itself says it is doing — `state` in firmware protocol 2,
 * which replaced the overlapping `status` + `dispenser` pair of protocol 1.
 *
 * Availability is `state !== fault`. That is a different question from "does
 * this need a human", which only {@see DispenserFault} answers: a dispenser
 * that crashed and came back is `idle` with `fault: none` and an `error`
 * transaction behind it, and must not be taken out of service for it.
 *
 * Mirrors `DispenserDeviceState` in the terminal (`dispenser_client.dart`).
 */
enum DispenserDeviceState: string
{
    case IDLE = 'idle';
    case DISPENSING = 'dispensing';
    case FAULT = 'fault';

    public static function tryFromWire(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
