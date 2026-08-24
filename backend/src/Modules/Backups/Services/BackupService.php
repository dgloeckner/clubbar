<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupKeyringException;
use App\Modules\Backups\Domain\BackupOutcome;
use App\Modules\Backups\Domain\BackupRecipient;
use App\Modules\Backups\Domain\UnclassifiedTablePolicy;
use App\Modules\Backups\Repositories\BackupConfigRepository;
use App\Modules\Backups\Repositories\BackupKeysRepository;
use App\Modules\Backups\Repositories\BackupRunsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use App\Shared\Security\BackupSealedBox;
use App\Shared\Utils\Uuid;
use Throwable;

/**
 * One backup run: dump, seal, write, record, prune.
 *
 * ### Never throws
 *
 * Every caller is a scheduler nobody is watching, so the run reports rather
 * than raises — the same rule `DrainService` follows for the same reason. What
 * went wrong lands on the `backup_runs` row, in the application log, and in the
 * returned {@see BackupOutcome}; #693 turns that into a self-check row and a
 * mail. A run that threw would produce a non-zero exit, which most panels turn
 * into a mail to the account owner — somebody who cannot fix it.
 *
 * ### Atomic here, resumable there
 *
 * The dump is written to `<name>.part`, `fsync`ed and `rename()`d. A rename
 * within one filesystem is atomic, so the directory never holds a half-written
 * archive that a later run would count as a backup. The *upload* (#691) is the
 * opposite: resumable across runs, because a slow network should delay a backup
 * and never corrupt one. A resumable dump was rejected outright — it produces a
 * torn snapshot that looks like a backup and is not one.
 *
 * ### The whole archive is built in memory, and that is a deliberate bound
 *
 * `BackupSealedBox::seal()` takes a string. A club database is single-digit
 * megabytes and gzip takes it further, so this is comfortable at the scale this
 * ships for — and the budget check below is what stops a database that has
 * outgrown it from being discovered at 03:00 by an OOM with no row written.
 * Streaming the seal is #691's problem, when the upload needs a stream anyway.
 *
 * ADR-0049. Part of #690, epic #686.
 */
final class BackupService
{
    /** The directory archives live in, under the data directory and outside the document root. */
    public const DIRECTORY = 'backups';

    /** Sealed-container extension, as `tools/backup-decryptor.html` accepts it. */
    public const EXTENSION = '.cbb';

    /**
     * The floor between two runs, in minutes.
     *
     * Compiled in rather than configurable, and that is the point: it exists
     * only to stop `/api/cron/backup` being called in a loop until the webspace
     * quota is full. A club that could raise it could disable the guard, and no
     * legitimate schedule — daily or weekly — comes anywhere near it.
     */
    public const MINIMUM_INTERVAL_MINUTES = 60;

    public function __construct(
        private readonly DatabaseDump $dump,
        private readonly BackupKeyring $keyring,
        private readonly BackupRunsRepository $runs,
        private readonly BackupKeysRepository $keys,
        private readonly BackupConfigRepository $config,
        private readonly AuditService $audit,
        private readonly Logger $logger,
        private readonly string $backupDirectory,
        private readonly string $configuredPublicKeys,
        private readonly string $appEnv = 'production',
    ) {
    }

    /**
     * @param string $triggerSource `cli` or `url`
     * @param bool $force skip the minimum-interval guard (an operator asked for this run by hand)
     */
    public function run(string $triggerSource, bool $force = false): BackupOutcome
    {
        $settings = $this->config->get();

        if ((int) $settings['enabled'] !== 1) {
            return BackupOutcome::skipped(
                'Backups are disabled (Settings → Backups). Nothing was written.'
            );
        }

        if (!$force && ($tooSoon = $this->tooSoonToRunAgain()) !== null) {
            return BackupOutcome::skipped($tooSoon);
        }

        try {
            $recipients = $this->keyring->recipients($this->configuredPublicKeys);
        } catch (BackupKeyringException $e) {
            // Fail closed, and loudly. No archive at all is the correct outcome
            // here; a plaintext one would be the failure ADR-0031 rule 3 names.
            $this->logger->error('Backup refused: no usable recipient key', [
                'message' => $e->getMessage(),
            ]);

            return BackupOutcome::failed($e->getMessage(), null, [$e->getMessage()]);
        }

        $runId = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $this->runs->start($runId, $triggerSource, $now);

        try {
            return $this->produce($runId, $recipients, $settings);
        } catch (Throwable $e) {
            $this->runs->markFailed($runId, $e->getMessage(), gmdate('Y-m-d H:i:s'));
            $this->logger->error('Backup run failed', [
                'run_id' => $runId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return BackupOutcome::failed($e->getMessage(), $runId, [$e->getMessage()]);
        }
    }

    /**
     * @param list<BackupRecipient> $recipients
     * @param array<string, mixed> $settings
     */
    private function produce(string $runId, array $recipients, array $settings): BackupOutcome
    {
        $previous = $this->runs->lastSuccessful();

        // Fails open by policy (ADR-0049 decision 1): an unrecognised table is
        // dumped and reported, because refusing the night's backup over one
        // unknown name is the control-that-looks-like-protection failure. CI is
        // where the same event is red, via the bijection test.
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
        $sealed = BackupSealedBox::seal(
            $sql,
            array_map(static fn (BackupRecipient $r): array => $r->toSealRecipient(), $recipients),
            $this->appEnv,
        );
        // The plaintext is the whole database; drop the reference as soon as it
        // is sealed rather than holding both copies until the function returns.
        unset($sql);

        $filename = $this->filenameFor($runId);
        $bytes = $this->writeAtomically($filename, $sealed);
        $sha256 = hash('sha256', $sealed);
        unset($sealed);

        $finishedAt = gmdate('Y-m-d H:i:s');
        $fingerprints = array_map(static fn (BackupRecipient $r): string => $r->fingerprint(), $recipients);

        foreach ($recipients as $recipient) {
            $isNew = $this->keys->recordUse($recipient->fingerprint(), $recipient->label, $finishedAt);

            // Audited from the run rather than from a form, because there is no
            // form: recipient keys are added by editing `config.php`, which is
            // not a place that can write an audit row. The first archive sealed
            // to a key is the earliest moment the application can know it
            // exists, so the observation *is* the record (pattern-016).
            if ($isNew) {
                $this->audit->log(
                    AuditAction::BACKUP_KEY_ADDED,
                    EntityType::BACKUP_KEY,
                    $recipient->fingerprint(),
                    null,
                    ['label' => $recipient->label],
                );
            }
        }

        $this->runs->markLocal(
            $runId,
            $filename,
            $bytes,
            $sha256,
            $fingerprints,
            $result->manifest,
            $finishedAt,
        );

        $drift = $this->manifestDrift($previous, $result->manifest);
        $findings = $this->findingsFor($result->unclassifiedTables, $drift);

        $pruned = $this->prune($settings, $findings);

        return BackupOutcome::written(
            $runId,
            $filename,
            $bytes,
            $findings,
            $drift,
            $result->unclassifiedTables,
            $pruned,
        );
    }

    /**
     * Did the set of tables change since the last archive?
     *
     * Storing a manifest answers *"what was in that archive?"* after the fact.
     * Comparing it answers *"did the shape of the database change last night?"*
     * while somebody can still act on it — which is the runtime half of the
     * drift guard whose CI half is the bijection test (#699). A table that
     * appears, disappears or moves between classes is reportable in its own
     * right: it means a migration ran, and a migration nobody expected is worth
     * a look before the next one.
     *
     * Row *counts* are deliberately not compared. They change every day, by
     * design, and an alarm that fires every day is one nobody reads.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, int> $manifest
     * @return list<string>
     */
    private function manifestDrift(?array $previous, array $manifest): array
    {
        if ($previous === null || ($previous['table_manifest'] ?? null) === null) {
            return [];
        }

        $before = json_decode((string) $previous['table_manifest'], true);
        if (!is_array($before)) {
            return [];
        }

        $drift = [];

        foreach (array_diff(array_keys($manifest), array_keys($before)) as $added) {
            $drift[] = sprintf('table "%s" is new since the previous archive', $added);
        }

        foreach (array_diff(array_keys($before), array_keys($manifest)) as $gone) {
            $drift[] = sprintf('table "%s" was in the previous archive and is not in this one', $gone);
        }

        return $drift;
    }

    /**
     * @param list<string> $unclassified
     * @param list<string> $drift
     * @return list<string>
     */
    private function findingsFor(array $unclassified, array $drift): array
    {
        $findings = [];

        if ($unclassified !== []) {
            $findings[] = sprintf(
                'Backed up %d table(s) nobody has classified (%s). They were included as full '
                . 'data, which is the safer guess — but somebody has to decide, in '
                . 'TableClassification::MAP.',
                count($unclassified),
                implode(', ', $unclassified)
            );
        }

        foreach ($drift as $change) {
            $findings[] = 'Backup manifest changed: ' . $change . '.';
        }

        return $findings;
    }

    /**
     * Remove archives past their age, then past the total byte cap, oldest
     * first. The row survives both.
     *
     * The byte cap is a **refusal**, not a best effort: if pruning everything
     * eligible still leaves the directory over the cap, that is recorded as a
     * finding rather than resolved by deleting the newest archives. A full
     * webspace quota breaks logging and mandate storage, neither of which is
     * the backup's to break — but deleting the only recent backup to stay under
     * a number would be worse than reporting it.
     *
     * @param array<string, mixed> $settings
     * @param list<string> $findings appended to in place
     */
    private function prune(array $settings, array &$findings): int
    {
        $archives = $this->runs->unprunedArchives();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ((int) $settings['local_retention_days'] * 86400));
        $cap = (int) $settings['local_max_bytes'];
        $prunedAt = gmdate('Y-m-d H:i:s');

        $pruned = 0;
        $keep = [];

        foreach ($archives as $archive) {
            if ((string) $archive['started_at'] < $cutoff) {
                $this->removeArchive((string) $archive['id'], (string) $archive['filename'], $prunedAt);
                $pruned++;
                continue;
            }

            $keep[] = $archive;
        }

        // Oldest first, so the byte cap takes the archive whose loss costs least.
        $total = array_sum(array_map(static fn (array $a): int => (int) $a['bytes'], $keep));
        foreach ($keep as $i => $archive) {
            if ($total <= $cap) {
                break;
            }

            // Never the newest: an installation whose single archive is over the
            // cap must end up reported, not empty.
            if ($i === count($keep) - 1) {
                break;
            }

            $this->removeArchive((string) $archive['id'], (string) $archive['filename'], $prunedAt);
            $total -= (int) $archive['bytes'];
            $pruned++;
        }

        if ($total > $cap) {
            $findings[] = sprintf(
                'Backups occupy %s bytes, over the %s byte cap, and pruning further would leave '
                . 'the club with no recent archive. Raise the cap, shorten retention, or find '
                . 'the webspace more room — a full quota breaks logging and mandate storage.',
                number_format($total),
                number_format($cap)
            );
        }

        return $pruned;
    }

    private function removeArchive(string $runId, string $filename, string $prunedAt): void
    {
        $path = $this->backupDirectory . '/' . $filename;

        // A file already gone is pruned as far as anyone cares — an operator
        // deleting one by hand must not leave a row that says it is still there.
        if (is_file($path) && !@unlink($path)) {
            $this->logger->warning('Could not remove a pruned backup archive', ['path' => $path]);

            return;
        }

        $this->runs->markPruned($runId, $prunedAt);
    }

    /**
     * `.part` → `fsync` → `rename()`, so the directory never shows a partial
     * archive. Directory mode 0700, outside the document root (ADR-0031
     * decision 2) — an archive under it would be a URL the day `.htaccess`
     * stops being honoured, which has already happened once (#383).
     */
    private function writeAtomically(string $filename, string $contents): int
    {
        if (!is_dir($this->backupDirectory) && !@mkdir($this->backupDirectory, 0700, true)
            && !is_dir($this->backupDirectory)) {
            throw new \RuntimeException(
                'Cannot create the backup directory ' . $this->backupDirectory . '.'
            );
        }

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

    private function filenameFor(string $runId): string
    {
        return sprintf('clubbar-%s-%s%s', gmdate('Ymd-His'), substr($runId, 0, 8), self::EXTENSION);
    }

    /** @return string|null the reason to skip, or null to go ahead */
    private function tooSoonToRunAgain(): ?string
    {
        $last = $this->runs->lastStartedAt();
        if ($last === null) {
            return null;
        }

        $elapsed = time() - strtotime($last . ' UTC');
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
