<?php

declare(strict_types=1);

namespace App\Shared\Utils;

/**
 * RFC 4122 version 4 identifiers.
 *
 * Eight files carried a private `generateUuid()` (issue #119) — seven
 * repositories and one service. Client-generated UUIDs are what make the sync
 * API idempotent (ADR-0004), so how they are produced is a property of the
 * system, not of whichever repository happened to need one first.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        // Version 4 in the high nibble of byte 6, RFC 4122 variant in the two
        // high bits of byte 8.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
