<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupRetention;
use App\Shared\Security\SecurityFinding;
use App\Shared\Security\SecuritySelfCheck;

/**
 * What the backup job has actually *done*, measured from the files it left.
 *
 * ## Why this exists beside `BackupConfigCheck`
 *
 * That class reports what `config.php` says — what the club *intended*. This
 * one reports what happened. Both are needed and they fail differently: a
 * perfect configuration whose cron was dropped in a tariff migration passes
 * every config row and has produced no backup in eight months.
 *
 * **This is what makes the second scheduled job legitimate.** ADR-0038's rule
 * is *"no scheduled path may exist that nothing observes"*, and ADR-0049 leaned
 * on that rule to justify a backup cron separate from the mail drain. The
 * observation is here.
 *
 * ## No provider in the request path
 *
 * **This class takes no {@see \App\Modules\Backups\Transport\BackupTransport}**,
 * and that is a design constraint rather than an omission.
 *
 * The transport is sized for a nightly upload: a 120-second timeout per call
 * (one 3.2 MB chunk) and three retries honouring `Retry-After`, on a Graph API
 * that throttles per tenant. One listing is therefore a token call plus a list
 * call, each of those, at a delay Microsoft chooses — minutes, with no ceiling
 * the caller controls. On the shared hosting this targets, the gateway would
 * kill the request at 30–60 seconds first, so the operator would see a blank
 * error rather than "the remote is unreachable".
 *
 * And the page these rows render on is the *security self-check* — the page
 * whose entire job is telling an admin what is broken. Coupling it to an
 * external provider would mean a tenant outage breaks the page you open to find
 * out why things are broken.
 *
 * So: the nightly job may depend on the provider — it is asynchronous, budgeted
 * and its failure is a finding. A page may not. Every row below reads the
 * backup directory and the journal, and nothing else.
 *
 * ## The archives answer where they can; the journal fills the rest
 *
 * ADR-0049 decision 8: *the archive is the record, the journal is a
 * convenience*. `BackupJournal` reads a bounded tail, so on an installation
 * that has failed nightly for months the window may not reach the last success
 * — which is why "when did a backup last succeed" is answered from the
 * **newest archive on disk** instead ({@see ArchiveDirectory}, which takes the
 * instant from the filename so a copy or a stray `touch` cannot move it), and
 * why a missing upload timestamp is reported as unknown rather than "never".
 *
 * Part of #693, epic #686.
 */
final class BackupStatusCheck
{
    private const CATEGORY = SecuritySelfCheck::CATEGORY_BACKUP;

    /** A nightly job that has not produced an archive in this long is stale. */
    private const STALE_AFTER_HOURS = 48;

    public function __construct(
        private readonly string $backupDirectory,
        private readonly string $configuredRecipientKeys,
        private readonly BackupRetention $retention,
        private readonly ?int $now = null,
    ) {
    }

    /** @return list<SecurityFinding> */
    public function findings(): array
    {
        // Backups off is a legitimate state and the on-switch is the recipient
        // keys (ADR-0049 decision 2). `BackupConfigCheck` already says so in
        // its own row; repeating it here as four more rows would teach the
        // reader to skim the section, so this half stays silent instead.
        if (trim($this->configuredRecipientKeys) === '') {
            return [];
        }

        $archives = (new ArchiveDirectory($this->backupDirectory))->newestFirst();
        $journal = new BackupJournal($this->backupDirectory);

        $everRan = $journal->hasAnyEntry() || $archives !== [];

        if (!$everRan) {
            // The row this whole milestone exists for. Silence and "the cron
            // was never added" are indistinguishable without it.
            return [SecurityFinding::fail(
                'backup_ever_ran',
                self::CATEGORY,
                'Backup job',
                'no backup has ever been observed to run',
                'Recipient keys are configured, so backups are switched on — but nothing has '
                . 'ever executed. The nightly job is almost certainly not in the hosting '
                . "panel's scheduler. The installer's last step prints the exact command."
            )];
        }

        return [
            $this->lastBackupFinding($archives),
            $this->lastUploadFinding($journal),
            $this->retentionFinding($archives),
        ];
    }

    /**
     * From the newest archive, not the journal.
     *
     * The archive **is** the record (ADR-0049 decision 8), and reading it here
     * sidesteps the journal's bounded window: an installation that has failed
     * every night for a month would push its last success out of the window,
     * and "no success in the last 64 KiB of journal" must never be rendered as
     * "no success ever".
     *
     * @param list<array{name: string, bytes: int, at: int}> $archives
     */
    private function lastBackupFinding(array $archives): SecurityFinding
    {
        if ($archives === []) {
            return SecurityFinding::fail(
                'backup_last_run',
                self::CATEGORY,
                'Last backup',
                'the job has run, but no archive exists',
                'Every run is failing before it writes anything. The run log and the journal '
                . 'beside the backup directory name the reason.'
            );
        }

        $newest = $archives[0]['at'];
        $hours = (int) floor(($this->now() - $newest) / 3600);

        if ($hours > self::STALE_AFTER_HOURS) {
            return SecurityFinding::fail(
                'backup_last_run',
                self::CATEGORY,
                'Last backup',
                sprintf('the newest archive is %d hours old', $hours),
                'A nightly job should leave one every day. Either the scheduler stopped or the '
                . 'runs are failing; the journal beside the archives says which.'
            );
        }

        return SecurityFinding::pass(
            'backup_last_run',
            self::CATEGORY,
            'Last backup',
            sprintf('%s (%d hours ago)', gmdate('Y-m-d H:i', $newest) . 'Z', $hours)
        );
    }

    /**
     * Whether anything has left the host, which the archive on disk cannot say.
     *
     * A local archive and an uploaded one look identical in the directory, so
     * this is the one row only the journal can answer — and therefore the one
     * where a missing answer is genuinely *unknown* rather than negative.
     */
    private function lastUploadFinding(BackupJournal $journal): SecurityFinding
    {
        $uploadedAt = $journal->lastUploadedAt();

        if ($uploadedAt === null) {
            return SecurityFinding::unknown(
                'backup_last_upload',
                self::CATEGORY,
                'Last off-site copy',
                'no upload recorded in recent history',
                'Either nothing has been pushed off this host, or the journal has rolled past '
                . 'it. A backup that never leaves the webspace shares the fate of the account '
                . 'it sits on — check that backup.dsn is set and that the run log shows an '
                . 'upload.'
            );
        }

        $hours = (int) floor(($this->now() - $uploadedAt) / 3600);

        if ($hours > self::STALE_AFTER_HOURS) {
            return SecurityFinding::fail(
                'backup_last_upload',
                self::CATEGORY,
                'Last off-site copy',
                sprintf('the last upload was %d hours ago', $hours),
                'Archives are still being written here but are no longer reaching the store, '
                . 'so the off-site copy is ageing out while the local one looks healthy. This '
                . 'is the failure that survives longest unnoticed.'
            );
        }

        return SecurityFinding::pass(
            'backup_last_upload',
            self::CATEGORY,
            'Last off-site copy',
            sprintf('%s (%d hours ago)', gmdate('Y-m-d H:i', $uploadedAt) . 'Z', $hours)
        );
    }

    /**
     * Measured against the cap, not read from it.
     *
     * The cap exists because a webspace that fills stops accepting *everything*
     * — mandate uploads, logs, the next archive — so the backup taking the
     * quota down with it is a failure of the whole installation, not of the
     * backup.
     *
     * @param list<array{name: string, bytes: int, at: int}> $archives
     */
    private function retentionFinding(array $archives): SecurityFinding
    {
        $used = array_sum(array_column($archives, 'bytes'));
        $cap = $this->retention->localMaxBytes;

        if ($used > $cap) {
            return SecurityFinding::fail(
                'backup_local_size',
                self::CATEGORY,
                'Local archive size',
                sprintf('%s over a cap of %s', self::human($used), self::human($cap)),
                'Retention should have aged these out and has not. Check the run log: a prune '
                . 'that cannot delete leaves the quota to fill, and a full webspace stops '
                . 'mandate uploads and logging too.'
            );
        }

        return SecurityFinding::pass(
            'backup_local_size',
            self::CATEGORY,
            'Local archive size',
            sprintf('%s of %s, %d archive(s)', self::human($used), self::human($cap), count($archives))
        );
    }

    private function now(): int
    {
        return $this->now ?? time();
    }

    private static function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.1f GB', $bytes / 1073741824);
        }

        return $bytes >= 1048576
            ? sprintf('%.0f MB', $bytes / 1048576)
            : sprintf('%d bytes', $bytes);
    }
}
