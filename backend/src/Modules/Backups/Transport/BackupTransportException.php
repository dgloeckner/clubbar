<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

use RuntimeException;

/**
 * The remote could not be reached, or refused (pattern-018).
 *
 * Thrown inside the transport and caught at its own boundary, so callers get a
 * {@see TransportResult} rather than an exception — the messages here are
 * written for the volunteer reading a cron mail, which is why several of them
 * name the configuration key to edit rather than the HTTP status observed.
 *
 * Part of #691, epic #686.
 */
class BackupTransportException extends RuntimeException
{
}
