<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * What this backend reports as its version — the tag CI wrote into
 * `backend/VERSION`, or `dev` for a tree that was never released from.
 *
 * It was read inline by `HealthCheckService` while `/api/health` was the only
 * reader. ADR-0054 gave it a second one: the same string is the target every
 * terminal in the club installs, and the yardstick the Terminals page measures
 * each terminal against. Two `file_get_contents` of the same file, disagreeing
 * about what counts as absent, is precisely the silent contradiction the ADR
 * refuses for the terminal's own version.
 */
final class AppVersion
{
    /** What a tree with no VERSION file reports, and what ADR-0054 treats as "never update". */
    public const DEV = 'dev';

    public function __construct(
        /** Overridable so tests need no file on disk; defaults to `backend/VERSION`. */
        private readonly ?string $versionFile = null,
    ) {}

    public function current(): string
    {
        $file = $this->versionFile ?? dirname(__DIR__, 3) . '/VERSION';
        if (!is_file($file)) {
            return self::DEV;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return self::DEV;
        }

        $trimmed = trim($contents);

        return $trimmed === '' ? self::DEV : $trimmed;
    }
}
