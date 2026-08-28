<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

/**
 * Hashing and constant-time comparison for the URL-trigger secret (#473).
 *
 * Deliberately self-contained rather than a call into `App\Modules\Auth`'s
 * `TokenService`: the mechanics are identical — SHA-256, `hash_equals()` — but
 * a cron secret is not a terminal credential, and Notifications has no reason
 * to depend on Auth for two library calls (ADR-0018 modular architecture).
 *
 * It no longer *generates* one. Since #744 a secret is minted in exactly one
 * place — `install.php`, which writes it to `config.php` beside the scheduler
 * instructions that quote it — and `config.php` holds it in the clear, so
 * nothing hashes a freshly made secret any more. What is left is reading an
 * `mail_config.cron_secret_hash` that the removed panel rotation (#473) left
 * behind on an installation that used it, whose scheduler is still sending
 * that value.
 */
final class CronSecret
{
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function verify(string $plaintext, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($plaintext));
    }
}
