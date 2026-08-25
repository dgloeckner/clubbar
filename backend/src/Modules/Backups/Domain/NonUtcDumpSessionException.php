<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

use RuntimeException;

/**
 * The connection being dumped is not reading in UTC (pattern-018).
 *
 * A `TIMESTAMP` is rendered to text in the *session* zone, so a dump taken on a
 * connection at `Europe/Berlin` writes literals two hours off the instant they
 * represent — and the archive that results is wrong in a way no later check can
 * see, because it is internally consistent.
 *
 * {@see \App\Shared\Database\ConnectionFactory} pins the offset for every
 * connection the application makes; this exists because {@see DatabaseDump}
 * accepts any PDO and must not trust that it came from there.
 */
final class NonUtcDumpSessionException extends RuntimeException
{
    public static function offsetIs(string $offset): self
    {
        return new self(sprintf(
            'The dump connection reads at offset %s, not UTC. Every TIMESTAMP value would be '
            . 'written shifted by that amount, and the archive would look correct. Connect '
            . 'through ConnectionFactory, which pins the session time zone.',
            $offset
        ));
    }
}
