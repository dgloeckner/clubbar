<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupKeyringException;
use App\Modules\Backups\Domain\BackupOutcome;
use App\Modules\Backups\Domain\BackupRecipient;
use App\Modules\Backups\Domain\BackupRetention;
use App\Shared\Logging\Logger;
use App\Shared\Security\BackupSealedBox;
use Throwable;

/**
 * One backup run: dump, seal, write, journal, prune.
 *
 * ### It writes nothing into the database it dumps
 *
 * No run table, no key table, no configuration row, no audit entry (ADR-0049
 * decision 8). A backup is a procedure that stands outside the system it
 * protects — it happens to share the codebase, and that is the whole
 * relationship. Its record is the **archive header**, which describes itself
 * without any key, and the **journal beside the archives**, which carries the
 * attempts that produced no file. Its configuration is `config.php`.
 *
 * That is not tidiness. The earlier draft's three tables were self-referential
 * in ways that bite: every archive contained its own half-written `running`
 * row, so restoring one resurrected a run that never finishes; a compromise
 * blocklist kept in the database was *reverted by any restore* of an archive
 * predating the compromise; and rows drifted from files the moment an operator
 * deleted an archive by hand.
 *
 * ### Never throws
 *
 * Every caller is a scheduler nobody is watching, so the run reports rather
 * than raises — the same rule `DrainService` follows for the same reason. What
 * went wrong lands in the journal, in the application log, and in the returned
 * {@see BackupOutcome}; #693 turns that into a self-check row and a mail. A run
 * that threw would produce a non-zero exit, which most panels turn into a mail
 * to the account owner — somebody who cannot fix it.
 *
 * ### Atomic here, resumable there
 *
 * The archive is written to `<name>.part`, `fsync`ed and `rename()`d. A rename
 * within one filesystem is atomic, so the directory never holds a half-written
 * archive that a later run would count as a backup. The *upload* (#691) is the
 * opposite: resumable across runs, because a slow network should delay a backup
 * and never corrupt one. A resumable dump was rejected outright — it produces a
 * torn snapshot that looks like a backup and is not one.
 *
 * ### The whole archive is built in memory, and that is a deliberate bound
 *
 * `BackupSealedBox::seal()` takes a string, and it has to: the header carries
 * the manifest and the plaintext's checksum, so sealing cannot begin until the
 * dump has finished. A club database is single-digit megabytes, so this is
 * comfortable at the scale this ships for. Streaming the seal is #691's
 * problem, when the upload needs a stream anyway.
 *
 * ADR-0049. Part of #690 and #703, epic #686.
 */
final class BackupService
{
    /** The directory archives live in, under the data directory and outside the document root. */
    public const DIRECTORY = 'backups';

    /** Sealed-container extension, as `tools/backup-decryptor.html` accepts it. */
    public const EXTENSION = '.cbb';

    /** The archive name this job writes, and the only pattern it will ever prune. */
    private const FILENAME_PREFIX = 'clubbar-';

    /**
     * The floor between two runs, in minutes.
     *
     * Compiled in rather than configurable, and that is the point: it exists
     * only to stop `/api/cron/backup` being called in a loop until the webspace
     * quota is full. A club that could raise it could disable the guard, and no
     * legitimate schedule — daily or weekly — comes anywhere near it.
     */
    public const MINIMUM_INTERVAL_MINUTES = 60;

    private readonly BackupJournal $journal;

    public function __construct(
        private readonly DatabaseDump $dump,
        private readonly BackupKeyring $keyring,
        private readonly Logger $logger,
        private readonly string $backupDirectory,
        private readonly string $configuredRecipientKeys,
        private readonly BackupRetention $retention,
        private readonly string $appEnv = 'production',
        /**
         * `config.php`, carried inside the archive so a restore onto a new host
         * can be logged into at all. Null where a caller does not care; an
         * absent or unreadable file is carried as "nothing", never as a failed
         * run ({@see ConfigSnapshot}).
         */
        private readonly ?ConfigSnapshot $config = null,
    ) {
        $this->journal = new BackupJournal($this->backupDirectory);
    }

    /**
     * Has this installation switched backups on?
     *
     * Configuring a recipient key *is* the on-switch (ADR-0049 decision 2), so
     * there is no flag that could disagree with the keys. The entrypoints ask
     * this before running at all: an installation that has never set up backups
     * should be silent, not fail nightly.
     *
     * The question is deliberately "is there anything there", not "does it
     * parse". A typo'd key must reach {@see run()} and fail loudly — treating a
     * malformed entry as "backups are off" would let one wrong character switch
     * a club's backups off with no complaint, which is the worst outcome
     * available here.
     */
    public function isConfigured(): bool
    {
        return trim($this->configuredRecipientKeys) !== '';
    }

    /**
     * @param string $triggerSource `cli` or `url`
     * @param bool $force skip the minimum-interval guard (an operator asked for this run by hand)
     */
    public function run(string $triggerSource, bool $force = false): BackupOutcome
    {
        if (!$force && ($tooSoon = $this->tooSoonToRunAgain()) !== null) {
            return BackupOutcome::skipped($tooSoon);
        }

        try {
            $recipients = $this->keyring->recipients($this->configuredRecipientKeys);
        } catch (BackupKeyringException $e) {
            // Fail closed, and loudly. No archive at all is the correct outcome
            // here; a plaintext one would be the failure ADR-0031 rule 3 names.
            // Reaching this means somebody called a run on an installation with
            // no usable key — the entrypoints gate on isConfigured() precisely
            // so an unconfigured club never gets here.
            $this->logger->error('Backup refused: no usable recipient key', [
                'message' => $e->getMessage(),
            ]);

            return BackupOutcome::failed($e->getMessage(), [$e->getMessage()]);
        }

        try {
            // Inside the try, and this is the point of the ordering: creating
            // the directory is itself something that fails on shared hosting —
            // a data directory the cron user does not own — and a run that
            // threw there would exit non-zero into a panel that mails the
            // account owner. It reports, like every other failure here.
            $this->ensureDirectory();
            $this->journal->started($triggerSource);

            return $this->produce($recipients);
        } catch (Throwable $e) {
            $this->journal->failed($e->getMessage());
            $this->logger->error('Backup run failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return BackupOutcome::failed($e->getMessage(), [$e->getMessage()]);
        }
    }

    /**
     * @param list<BackupRecipient> $recipients
     */
    private function produce(array $recipients): BackupOutcome
    {
        $source = $this->dump->sourceDescription();

        $sql = '';
        $result = $this->dump->dump(function (string $chunk) use (&$sql): void {
            $sql .= $chunk;
        });

        // Sealed uncompressed, deliberately, and this is a departure from
        // ADR-0049's sketch of `gzip(SQL)` worth stating rather than quietly
        // making. #689's offline decryptor hands the payload straight to the
        // browser as `clubbar-backup.sql`; compressing here would hand a club a
        // gzip stream under a `.sql` name, and the operator would discover it
        // at the one moment the design exists to protect. Compression belongs
        // with the upload (#691) — that is where size costs anything — and the
        // decryptor's inflate side ships in the same slice.
        //
        // Sealing happens *after* the dump because the header describes the
        // plaintext: the manifest, its length and its checksum are not knowable
        // until the last row is written.
        // Appended after the dump's footer, so the payload is still one `.sql`
        // an operator can paste whole into phpMyAdmin — every line of the block
        // is a comment. A restore onto a *new* host needs it: without
        // `security.totp_encryption_key` every admin's second factor fails
        // against the rows that were just restored, and nobody can log in.
        $config = $this->config ?? new ConfigSnapshot();
        $sql .= $config->render();

        $sealed = BackupSealedBox::seal(
            $sql,
            array_map(static fn (BackupRecipient $r): array => $r->toSealRecipient(), $recipients),
            $source + [
                'manifest' => $result->manifest,
                // Answerable with no private key: a holder can see whether an
                // archive is a whole installation or only its rows before
                // working out what a restore will still need.
                'config_included' => $config->isAvailable(),
            ],
            $this->appEnv,
        );
        // The plaintext is the whole database; drop the reference as soon as it
        // is sealed rather than holding both copies until the function returns.
        unset($sql);

        $filename = $this->filenameFor();
        $bytes = $this->writeAtomically($filename, $sealed);
        $sha256 = hash('sha256', $sealed);
        unset($sealed);

        $this->journal->written(
            $filename,
            $bytes,
            $sha256,
            array_map(static fn (BackupRecipient $r): string => $r->fingerprint(), $recipients),
            count($result->manifest),
        );

        $findings = [];
        $pruned = $this->prune($filename, $findings);

        return BackupOutcome::written($filename, $bytes, $findings, $pruned);
    }

    /**
     * Remove archives past their age, then past the total byte cap, oldest
     * first — reading the directory, because the directory is what is true.
     *
     * There is no table of what should be there to disagree with what is: an
     * operator who deleted an archive by hand has deleted it, and a file that
     * appeared by some other route is still occupying the quota. The journal
     * records what was removed; it is never consulted about what exists.
     *
     * The byte cap is a **refusal**, not a best effort: if pruning everything
     * eligible still leaves the directory over the cap, that is recorded as a
     * finding rather than resolved by deleting the newest archive. A full
     * webspace quota breaks logging and mandate storage, neither of which is
     * the backup's to break — but deleting the only recent backup to stay under
     * a number would be worse than reporting it.
     *
     * @param string $justWritten never pruned, whatever the numbers say
     * @param list<string> $findings appended to in place
     */
    private function prune(string $justWritten, array &$findings): int
    {
        $archives = $this->archivesOnDisk();
        $cutoff = time() - ($this->retention->localDays * 86400);

        $pruned = 0;
        $keep = [];

        foreach ($archives as $archive) {
            if ($archive['name'] !== $justWritten && $archive['at'] < $cutoff) {
                if ($this->removeArchive($archive, 'age') === 1) {
                    $pruned++;
                    continue;
                }

                // Still on disk, so still spending the quota. Falling through
                // rather than dropping it keeps the byte total honest — an
                // archive we could not delete must not make the cap look
                // satisfied.
            }

            $keep[] = $archive;
        }

        // Oldest first, so the byte cap takes the archive whose loss costs least.
        $total = array_sum(array_column($keep, 'bytes'));
        foreach ($keep as $i => $archive) {
            if ($total <= $this->retention->localMaxBytes) {
                break;
            }

            // Never the newest: an installation whose single archive is over the
            // cap must end up reported, not empty.
            if ($i === count($keep) - 1 || $archive['name'] === $justWritten) {
                break;
            }

            $removed = $this->removeArchive($archive, 'cap');
            if ($removed === 0) {
                break;
            }

            $total -= $archive['bytes'];
            $pruned += $removed;
        }

        if ($total > $this->retention->localMaxBytes) {
            $findings[] = sprintf(
                'Backups occupy %s bytes, over the %s byte cap, and pruning further would leave '
                . 'the club with no recent archive. Raise the cap, shorten retention, or find '
                . 'the webspace more room — a full quota breaks logging and mandate storage.',
                number_format($total),
                number_format($this->retention->localMaxBytes)
            );
        }

        return $pruned;
    }

    /**
     * Every archive this job wrote, oldest first.
     *
     * Dated from the filename, which the run stamped in UTC, and from `mtime`
     * only when that will not parse. The two agree on every file this job
     * writes; the fallback matters for one somebody restored from a copy, where
     * the name is the honest date and the modification time is the day it was
     * copied.
     *
     * Only `clubbar-*.cbb` is considered, so nothing else an operator has put
     * in the directory — a `.part` from a killed run, the journal itself, a
     * note to a successor — can be deleted by this.
     *
     * @return list<array{name: string, bytes: int, at: int}>
     */
    private function archivesOnDisk(): array
    {
        $pattern = $this->backupDirectory . '/' . self::FILENAME_PREFIX . '*' . self::EXTENSION;

        $archives = [];
        foreach (glob($pattern) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $name = basename($path);
            $archives[] = [
                'name' => $name,
                'bytes' => (int) filesize($path),
                'at' => $this->dateOf($name) ?? (int) filemtime($path),
            ];
        }

        usort($archives, static fn (array $a, array $b): int => [$a['at'], $a['name']] <=> [$b['at'], $b['name']]);

        return $archives;
    }

    /** The UTC instant in `clubbar-20260825-030000-1a2b3c4d.cbb`, or null. */
    private function dateOf(string $filename): ?int
    {
        if (preg_match('/^' . self::FILENAME_PREFIX . '(\d{8}-\d{6})-/', $filename, $m) !== 1) {
            return null;
        }

        $at = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1], new \DateTimeZone('UTC'));

        return $at === false ? null : $at->getTimestamp();
    }

    /**
     * @param array{name: string, bytes: int, at: int} $archive
     * @return int 1 if the archive is gone, 0 if it could not be removed
     */
    private function removeArchive(array $archive, string $reason): int
    {
        $path = $this->backupDirectory . '/' . $archive['name'];

        if (!@unlink($path)) {
            $this->logger->warning('Could not remove a pruned backup archive', ['path' => $path]);

            return 0;
        }

        $this->journal->pruned($archive['name'], $archive['bytes'], $reason);

        return 1;
    }

    /**
     * Directory mode 0700, outside the document root (ADR-0031 decision 2) — an
     * archive under it would be a URL the day `.htaccess` stops being honoured,
     * which has already happened once (#383).
     */
    private function ensureDirectory(): void
    {
        if (!is_dir($this->backupDirectory)
            && !@mkdir($this->backupDirectory, 0700, true)
            && !is_dir($this->backupDirectory)) {
            throw new \RuntimeException(
                'Cannot create the backup directory ' . $this->backupDirectory . '.'
            );
        }
    }

    /**
     * `.part` → `fsync` → `rename()`, so the directory never shows a partial
     * archive.
     */
    private function writeAtomically(string $filename, string $contents): int
    {
        $this->ensureDirectory();

        $final = $this->backupDirectory . '/' . $filename;
        $part = $final . '.part';

        $handle = @fopen($part, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write ' . $part . '.');
        }

        try {
            if (@fwrite($handle, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Short write to ' . $part . '; the disk may be full.');
            }

            // Without this the rename can land before the bytes do, and a crash
            // between them leaves a correctly-named archive that is empty.
            @fflush($handle);
            @fsync($handle);
        } finally {
            @fclose($handle);
        }

        if (!@rename($part, $final)) {
            @unlink($part);

            throw new \RuntimeException('Cannot move ' . $part . ' into place.');
        }

        @chmod($final, 0600);

        return strlen($contents);
    }

    /**
     * `clubbar-<UTC timestamp>-<random>.cbb`.
     *
     * The suffix is what keeps two runs in the same second from colliding —
     * previously the head of a run id, and there is no run id any more. It
     * identifies the file and nothing else; what the archive *is* is in its
     * header.
     */
    private function filenameFor(): string
    {
        return sprintf(
            '%s%s-%s%s',
            self::FILENAME_PREFIX,
            gmdate('Ymd-His'),
            bin2hex(random_bytes(4)),
            self::EXTENSION
        );
    }

    /** @return string|null the reason to skip, or null to go ahead */
    private function tooSoonToRunAgain(): ?string
    {
        $last = $this->journal->lastStartedAt();
        if ($last === null) {
            return null;
        }

        $elapsed = time() - $last;
        if ($elapsed >= self::MINIMUM_INTERVAL_MINUTES * 60) {
            return null;
        }

        return sprintf(
            'A run started %d minute(s) ago; the floor between runs is %d. Nothing was written.',
            intdiv(max(0, $elapsed), 60),
            self::MINIMUM_INTERVAL_MINUTES
        );
    }
}
