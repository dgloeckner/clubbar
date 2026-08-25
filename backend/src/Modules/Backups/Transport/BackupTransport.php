<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

/**
 * Where sealed archives go once they exist.
 *
 * One interface behind `backup.dsn`, in the shape `mail.dsn` already has
 * (ADR-0038 decision 2, ADR-0049 decision 6), so the storage target can be
 * swapped without the producer knowing: `msgraph://` today, `s3://` with
 * object lock when the append-only gap is closed properly, `hidrive://` last.
 *
 * ## Everything here reports; nothing throws
 *
 * The caller is a scheduler nobody is watching. An archive on the webspace
 * with a failed upload is a worse night than a successful one and a much
 * better night than a non-zero exit into a panel that mails the account owner
 * — somebody who cannot fix it.
 *
 * ## A delete can only name something a listing produced
 *
 * {@see delete()} takes a {@see RemoteArchive}, not a name. The app holds a
 * credential that can delete anything in the library — `Sites.Selected` has no
 * add-only role and its `write` includes delete, which is the honest gap #691
 * documents rather than papers over. Making the type system refuse a delete of
 * something the code did not just see is the cheapest real narrowing available.
 *
 * Part of #691, epic #686.
 */
interface BackupTransport
{
    /** One line for a log or the self-check: which store, which folder. */
    public function describe(): string;

    /**
     * Push one local archive, resuming a previous run's session if there is one.
     *
     * @param int $budgetSeconds wall clock this run may spend here; on
     *                           exhaustion the transfer stops cleanly and the
     *                           sidecar carries the rest to the next run
     */
    public function upload(string $localPath, int $budgetSeconds): TransportResult;

    /**
     * Every archive this job has put in the store.
     *
     * The remote half of ADR-0049 decision 8's rule that *the directory is what
     * is true*: remote retention deletes what a listing found, and "which keys
     * still open archives that still exist" is answered from headers, never
     * from a table that could drift.
     *
     * @return list<RemoteArchive>
     * @throws BackupTransportException when the store cannot be listed
     */
    public function list(): array;

    /** @return bool true when the archive is gone from the store */
    public function delete(RemoteArchive $archive): bool;
}
