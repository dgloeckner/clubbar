<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

/**
 * Generation and hashing for the URL-trigger secret (#473).
 *
 * Deliberately self-contained rather than a call into `App\Modules\Auth`'s
 * `TokenService`: the mechanics are identical — 256 bits of `random_bytes()`,
 * SHA-256, `hash_equals()` — but a cron secret is not a terminal credential,
 * and Notifications has no reason to depend on Auth for two library calls
 * (ADR-0018 modular architecture). `install.php` generates a secret the same
 * way for the same reason: nothing here is a new scheme, just its second home.
 */
final class CronSecret
{
    private const ENTROPY_BYTES = 32;

    public static function generate(): string
    {
        return bin2hex(random_bytes(self::ENTROPY_BYTES));
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function verify(string $plaintext, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($plaintext));
    }
}
