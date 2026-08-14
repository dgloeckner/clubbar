<?php

declare(strict_types=1);

namespace App\Shared\Config;

/**
 * The interpreter this process is actually running on.
 *
 * Exists because on mass hosting there is rarely one PHP. The panel serves the
 * web with one build and runs cron with another — a different version, a
 * different `php.ini`, a different extension set — and the second one is the
 * one nobody looks at. A drain (#403) running under a PHP without `openssl`
 * cannot open a TLS connection to the mail server, while exactly the same code
 * reached through the browser can; the symptom is a queue that never empties
 * and a heartbeat that says the scheduler is alive.
 *
 * So the drain records what it found here, and the answer is readable in the
 * settlement detail instead of requiring somebody to guess which `php` binary
 * the crontab picked up.
 */
final class PhpRuntime
{
    /**
     * What the drain needs to get a message out of the host.
     *
     * Narrower than the installer's prerequisite list on purpose: this is the
     * *sending* path, not the whole application. `fileinfo` types uploads and
     * `sodium` opens sealed IBANs — neither is on the way to an SMTP socket, and
     * naming them here would report a gap the cron does not have.
     *
     * `openssl` is the one that bites: SMTP submission on 587 is STARTTLS, and
     * without the extension symfony/mailer fails the handshake rather than
     * falling back to plaintext.
     */
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'json', 'mbstring', 'openssl'];

    /** e.g. `8.3.14`. Truncated to what `cron_heartbeat.php_version` holds. */
    public static function version(): string
    {
        return substr(PHP_VERSION, 0, 32);
    }

    /**
     * The required extensions this interpreter does not load.
     *
     * @return list<string>
     */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => !extension_loaded($extension),
        ));
    }

    /**
     * The same answer as one column value: `''` when nothing is missing, which
     * is deliberately not NULL — "checked, all present" and "never checked" are
     * different states.
     */
    public static function missingExtensionsSummary(): string
    {
        return substr(implode(',', self::missingExtensions()), 0, 255);
    }

    /** True when this process is a command-line one rather than a request. */
    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}
