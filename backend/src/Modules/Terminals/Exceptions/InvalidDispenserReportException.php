<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Exceptions;

/**
 * A dispenser status report that will not be stored (Pattern 018).
 *
 * It is never rendered to a caller: `PUT /api/sync/terminal-status` answers
 * `204` whatever this says, because a terminal must not fail a sync cycle over
 * telemetry. The message exists for the log line that replaces the response —
 * so the field that was wrong is nameable when somebody asks why the panel
 * stopped moving.
 */
final class InvalidDispenserReportException extends \RuntimeException
{
}
