<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

/**
 * The run's wall clock ran out mid-transfer.
 *
 * Not a failure, and kept a separate type so it cannot be reported as one: a
 * club on a slow uplink whose archive needs three nights to leave the building
 * is working exactly as designed. Conflating this with a refusal would train
 * its operator to ignore the backup report, which is the only channel that
 * would tell them about a real one.
 *
 * Part of #691, epic #686.
 */
final class UploadBudgetExhaustedException extends BackupTransportException
{
}
