<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Backups\Services\BackupSchedule;
use App\Modules\Backups\Services\BackupStatusCheck;
use App\Modules\Notifications\DTOs\BackupHealthScanResultDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Shared\Logging\Logger;
use App\Shared\Security\SecurityFinding;
use DateTimeImmutable;

/**
 * Tells whoever holds the server that the backup job has stopped working,
 * without waiting for them to open the admin panel (#693, ADR-0049).
 *
 * ## Why this rides the *mail* tick and not the backup job
 *
 * This is the whole design, and getting it the other way round would produce a
 * feature that cannot report the failure it exists for.
 *
 * The failure most likely to happen — the epic's own risk table says so — is
 * **the backup cron was never added to the hosting panel**, or was dropped in a
 * tariff migration. A notice sent *by the backup job* is silent in exactly that
 * case: there is no job to send it. So the observation has to hang off a
 * scheduled path that is already known to run.
 *
 * That path is the mail drain. ADR-0038 made a scheduler mandatory rather than
 * optional — it is the only sending path, the install gate (#405) refuses to
 * finalize a direct debit without one, and the heartbeat notices when it stops.
 * An installation that can send mail at all therefore has a tick, and this rides
 * it, beside the two scans already there. {@see CredentialExpiryNotifier} made
 * the same argument first, for the same reason.
 *
 * ## One definition of "broken"
 *
 * The conditions are not re-derived here. {@see BackupStatusCheck} already
 * decides what counts as never-run, stale, archive-less or over the cap, and it
 * feeds both the security self-check and — through {@see BackupSchedule} — the
 * panel's banner. A mail that disagreed with the row an admin opens the panel to
 * read would be worse than either being wrong alone.
 *
 * So this class contributes no judgement of its own. It asks that class what is
 * failing, and its only decisions are *whether to say anything* and *how often*.
 *
 * ## Nothing on success, and at most once a day
 *
 * A recipient who receives "backups are fine" fifty times has learned to file
 * the fifty-first unread. So a healthy installation queues nothing at all, and
 * silence means the condition does not hold.
 *
 * When something *is* wrong, the tick is every fifteen minutes and the problem
 * lasts until somebody fixes it — so the `dedup_key` carries the date and the
 * unique index does the rest. Nothing here selects to find out whether it has
 * already warned: it offers the message and counts what the database accepted,
 * which is both faster and correct under two overlapping ticks, where a
 * lookup-then-insert would have both passes find nothing and both insert.
 *
 * ## What it does not do
 *
 * **No storage provider.** Every row it reads comes from the backup directory
 * and the journal. The same constraint {@see BackupStatusCheck} asserts on its
 * constructor applies here for a different reason: this runs inside the cron
 * tick whose real job is draining the queue, and a Graph call that hangs for
 * two minutes on a throttled tenant would delay the club's announcements.
 *
 * **Never throws.** The caller is that same tick, and a scan that could not read
 * a directory must not stop the announcements from going out.
 */
class BackupHealthNotifier
{
    /**
     * The `dedup_key` prefix, so a key read in the admin panel is
     * self-describing rather than a bare date beside a UUID.
     *
     * Length matters: `AdminNotifier::warnAdmins()` builds the key as
     * `occasion:adminUserId` into a VARCHAR(64), and an admin id is 36
     * characters — so the occasion has 27 to work with. `stale:` + a
     * `YYYY-MM-DD` date is 16, with eleven spare.
     */
    public const OCCASION_PREFIX = 'stale:';

    public function __construct(
        private BackupStatusCheck $backupStatusCheck,
        private BackupSchedule $backupSchedule,
        private AdminNotifier $adminNotifier,
        private MailConfigService $mailConfigService,
        private Logger $logger,
    ) {}

    /**
     * @param DateTimeImmutable|null $now Passed in, never read from the clock —
     *                                    the testability seam for the dedup day.
     */
    public function run(?DateTimeImmutable $now = null): BackupHealthScanResultDto
    {
        $now ??= new DateTimeImmutable();

        try {
            // Same gate, same reason as the credential scan: NullTransport
            // records a *permanent failure*, so every warning queued on an
            // installation with no mail configured would land in the
            // Notifications page as a red row. Better to queue nothing.
            if (!$this->mailConfigService->canSend()) {
                return BackupHealthScanResultDto::nothingDue('mail not configured');
            }

            // Backups off is a legitimate choice (ADR-0049 decision 2), not a
            // fault to nag about — and `BackupStatusCheck` returns no rows at
            // all in that state, so this is really only saying so out loud.
            if (!$this->backupSchedule->isConfigured()) {
                return BackupHealthScanResultDto::nothingDue('backups not configured');
            }

            $failing = $this->failingRowIds();

            // The desirable outcome, and the common one. Silence has to mean
            // "the condition does not hold" or the first real warning arrives
            // in an inbox that has learned to filter this sender.
            if ($failing === []) {
                return new BackupHealthScanResultDto();
            }

            $result = $this->adminNotifier->warnAdmins(
                MailKind::BACKUP_HEALTH_WARNING,
                // The installation itself: there is no backup row to point at,
                // and ADR-0049 decision 8 is why there never will be.
                '1',
                self::occasion($now),
            );

            if ($result->queued > 0) {
                $this->logger->warning('Backup health warning queued', [
                    // The row ids, not the observed strings: those name
                    // directories and sizes, and this line is read in a log a
                    // support conversation may quote.
                    'failing' => $failing,
                    'queued' => $result->queued,
                ]);
            }

            return new BackupHealthScanResultDto(
                failingRows: count($failing),
                queued: $result->queued,
                alreadyQueued: $result->alreadyQueued,
                adminsWithoutEmail: count($result->withoutEmail),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Backup health scan failed', ['error' => $e->getMessage()]);

            return BackupHealthScanResultDto::nothingDue('scan failed: ' . $e->getMessage());
        }
    }

    /**
     * The self-check rows reading `fail` right now.
     *
     * `fail` only — deliberately not `unknown`. The one row that can be unknown
     * is the off-site copy, and it is unknown precisely when the journal's
     * bounded window has rolled past the last upload on an installation that
     * has been running for a long time. Mailing about that would send a club
     * chasing a transport that works.
     *
     * @return list<string>
     */
    private function failingRowIds(): array
    {
        $ids = [];

        foreach ($this->backupStatusCheck->findings() as $finding) {
            if ($finding->status === SecurityFinding::FAIL) {
                $ids[] = $finding->id;
            }
        }

        return $ids;
    }

    /**
     * The day, as it appears in the `dedup_key`.
     *
     * The date and nothing narrower: the tick is every fifteen minutes and a
     * broken backup stays broken until somebody acts, so anything finer would
     * queue ninety-six mails a day about one missing cron entry.
     *
     * The date and nothing *wider*, either — a problem that is still there
     * tomorrow is worth saying again, because the first mail may have arrived
     * on the Friday of a holiday weekend.
     */
    public static function occasion(DateTimeImmutable $now): string
    {
        return self::OCCASION_PREFIX . $now->format('Y-m-d');
    }
}
