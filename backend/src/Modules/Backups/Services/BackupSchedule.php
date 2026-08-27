<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

/**
 * The backup job as a *scheduled* job: is it on, has it ever fired, and what
 * does the operator paste into their hosting panel.
 *
 * ## Why this exists beside `BackupStatusCheck`
 *
 * That class reports on the security self-check — a page an admin goes and
 * opens. This one answers the banner that sits on top of *every* page until the
 * job has been seen to run, which is a different audience and a different
 * moment: the volunteer who has the hosting panel open right now.
 *
 * The installer already prints both cron lines side by side, and says why:
 *
 * > A volunteer who sets up one scheduled job in a sitting and is told about the
 * > second in a manual sets up one job; the epic's own risk table names "the
 * > backup cron is never added" as the thing most likely to go wrong.
 *
 * It then tells the operator that the admin panel "shows the same instructions
 * until a run has been seen" — which was true only of the mail drain. This is
 * what makes the sentence true.
 *
 * ## No schedule lives here
 *
 * **Triggering is external.** A hosting panel fires this job; the application
 * never reads a schedule and could not act on one. So there is no cron
 * expression in this class and none in the API payload — `0 3 * * *` is advice
 * we render, it is the same on every installation, and it belongs in the
 * strings the banner draws from, where each language can phrase the sentence
 * around it.
 *
 * What *is* here is the two things a client cannot derive: a command carrying
 * this host's document root, and a URL that exists only when the route behind
 * it is mounted.
 *
 * ## Nothing blocks on this
 *
 * {@see \App\Modules\Notifications\Services\SchedulerStatusService::assertVerified()}
 * refuses a settlement finalize when the mail drain has never run, because an
 * unannounced collection is one the club promised not to make. No such promise
 * rides on a backup, so this class has no assertion half — a missing backup
 * cron is reported and never refused.
 *
 * Part of #693, epic #686.
 */
final class BackupSchedule
{
    /** Where the backup's CLI entrypoint sits, relative to the document root. */
    public const CLI_ENTRYPOINT = 'backend/bin/backup.php';

    /** The URL trigger's path, mounted only when a cron secret is configured. */
    public const TRIGGER_PATH = '/api/cron/backup';

    public function __construct(
        private readonly string $documentRoot,
        private readonly string $appUrl,
        private readonly string $backupDirectory,
        private readonly string $configuredRecipientKeys,
    ) {
    }

    /**
     * Are backups switched on?
     *
     * Configuring a recipient key *is* the on-switch (ADR-0049 decision 2), so
     * this asks the same question {@see BackupService::isConfigured()} asks, of
     * the same value — and deliberately does not hold a `BackupService` to ask
     * it. That object carries the storage transport and the database, and this
     * one is constructed on a page request.
     */
    public function isConfigured(): bool
    {
        return trim($this->configuredRecipientKeys) !== '';
    }

    /**
     * Has a run ever been observed on this installation?
     *
     * Read from the filesystem, never from a table: ADR-0049 decision 8 makes
     * the archive the record, and no backup run history goes into the database.
     */
    public function hasEverRun(): bool
    {
        return self::everRan(
            new BackupJournal($this->backupDirectory),
            (new ArchiveDirectory($this->backupDirectory))->oldestFirst(),
        );
    }

    /**
     * The one rule for "something has happened here", so two readers cannot
     * disagree about it.
     *
     * Takes what each caller already holds rather than reading for itself —
     * {@see BackupStatusCheck} has both in hand for its other rows, and a second
     * directory scan on a page load would be the price of stating the rule
     * twice.
     *
     * Either half is enough. The journal is authoritative for a run that failed
     * before writing anything; an archive covers the opposite case, a directory
     * whose journal was deleted or has rolled past its bounded window.
     *
     * @param list<array{name: string, bytes: int, at: int}> $archives
     */
    public static function everRan(BackupJournal $journal, array $archives): bool
    {
        return $journal->hasAnyEntry() || $archives !== [];
    }

    /**
     * The line to paste into a panel's cron form.
     *
     * `php` unqualified, and the same shape as the drain's, for the same reason
     * {@see \App\Modules\Notifications\Services\SchedulerStatusService::cliCommand()}
     * gives: the CLI binary on shared hosting is routinely a versioned name in a
     * directory this process cannot see, and naming the wrong absolute path is
     * worse than naming none — it fails with "not found" and reads as the
     * application's fault.
     */
    public function cliCommand(): string
    {
        return 'php ' . rtrim($this->documentRoot, '/') . '/' . self::CLI_ENTRYPOINT;
    }

    /**
     * The URL trigger, or null when it would be instructions for a 404.
     *
     * The route is mounted only when a cron secret is configured **and** backups
     * are switched on — see `BackupCronController`, which gates on exactly that
     * pair. Both halves have to hold, and they are known in different places:
     * the keys here, the secret in the mail configuration. So the caller passes
     * the half it holds rather than this class reaching across a module for it,
     * which would put a database read behind a method that otherwise touches
     * only configuration.
     */
    public function triggerUrl(bool $cronSecretConfigured): ?string
    {
        if (!$cronSecretConfigured || !$this->isConfigured()) {
            return null;
        }

        return rtrim($this->appUrl, '/') . self::TRIGGER_PATH;
    }
}
