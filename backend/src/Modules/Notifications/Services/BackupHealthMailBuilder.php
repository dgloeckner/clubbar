<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Backups\Services\BackupStatusCheck;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\BackupHealthDataDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\BackupHealthMail;
use App\Shared\Mail\MailMessage;
use App\Shared\Security\SecurityFinding;

/**
 * Renders the backup health warning at send time (ADR-0038 rule 5).
 *
 * The queue row carries no content at all — `subject_id` is the installation
 * and `dedup_key` is a day and a recipient id. What is wrong is asked for here,
 * which is what makes the mail describe the state it is *sent* in rather than
 * the state it was queued in.
 *
 * That matters more here than for most builders, because the gap is
 * asymmetric: a problem that has grown worse should be reported as it now
 * stands, and a problem that has been *fixed* in the fifteen minutes since the
 * scan should not be reported as a fault at all. {@see BackupHealthMail} handles
 * the second case in a sentence rather than by refusing to render — refusing
 * would leave a red row in the Notifications page for a backup that started
 * working again, which is the wrong lesson to teach a reader about that page.
 *
 * ## Reads the filesystem, never the provider
 *
 * {@see BackupStatusCheck} takes no transport, and this runs inside the drain —
 * the loop that has a wall-clock budget and a club's announcements waiting
 * behind it. A Graph call that blocked for two minutes on a throttled tenant
 * would spend that budget on a message about backups.
 */
class BackupHealthMailBuilder implements MailContentBuilder
{
    public function __construct(
        private BackupStatusCheck $backupStatusCheck,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::BACKUP_HEALTH_WARNING;
    }

    /** @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it. */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        return BackupHealthMail::render(new BackupHealthDataDto(
            language: MailLanguage::fromPreferred($outboxRow['language'] ?? null),
            // From the row, never re-read from `admin_users`: it is the record
            // of who was written to, and the address may have moved between the
            // enqueue and the drain.
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            branding: $mailConfig->toBranding(),
            failing: $this->failingRowIds(),
        ));
    }

    /**
     * The rows reading `fail` right now.
     *
     * `fail` only, matching {@see BackupHealthNotifier} exactly — the two must
     * agree about what "broken" means, or a mail queued for a real problem
     * renders as one that has cleared.
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

    /** @param array<string,mixed> $outboxRow */
    private function recipientName(array $outboxRow): ?string
    {
        $adminUserId = trim((string) ($outboxRow['admin_user_id'] ?? ''));
        if ($adminUserId === '') {
            return null;
        }

        $admin = $this->adminUsersRepository->findById($adminUserId);
        $name = trim((string) ($admin['display_name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
