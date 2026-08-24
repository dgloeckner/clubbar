<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\CreditLimitDigestDataDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\DigestCadence;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\CreditLimitDigestMail;
use App\Shared\Mail\MailMessage;

/**
 * Renders the near-limit digest at send time (ADR-0038 rule 5).
 *
 * The queue row carries no content at all — `subject_id` is the singleton
 * credit-limit configuration and `dedup_key` is a window key and a recipient id.
 * Everything the mail says is asked for here, which is what makes the digest
 * describe the day it is *sent* rather than the day it was queued.
 *
 * That is a stronger property here than for the other builders, and it is the
 * reason this one throws for nothing. A settlement announcement whose settlement
 * has vanished must refuse to render, because a wrong announcement is worse than
 * a failed one; a digest whose list has emptied is simply a digest with good
 * news, and {@see CreditLimitDigestMail} says so in a sentence.
 */
class CreditLimitDigestMailBuilder implements MailContentBuilder
{
    public function __construct(
        private CreditLimitDigestService $digestService,
        private MailConfigService $mailConfigService,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::CREDIT_LIMIT_DIGEST;
    }

    /** @param array<string,mixed> $outboxRow */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        return CreditLimitDigestMail::render(new CreditLimitDigestDataDto(
            language: MailLanguage::from((string) ($outboxRow['language'] ?? MailLanguage::German->value)),
            // From the row, never re-read from `admin_users`: it is the
            // snapshot of who was written to, and the address may have moved
            // between the enqueue and the drain.
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            branding: $mailConfig->toBranding(),
            // Read live rather than from the row, because it is used only to
            // tell the reader how often to expect this — and the honest answer
            // to that is the setting as it stands now, not as it stood when a
            // row that has been sitting in the queue was written.
            cadence: $this->cadence(),
            report: $this->digestService->collect(),
        ));
    }

    private function cadence(): DigestCadence
    {
        return $this->mailConfigService->getConfig()->creditLimitDigestCadence;
    }

    /** @param array<string,mixed> $outboxRow */
    private function recipientName(array $outboxRow): ?string
    {
        $adminUserId = $outboxRow['admin_user_id'] ?? null;
        if (!is_string($adminUserId) || $adminUserId === '') {
            return null;
        }

        foreach ($this->adminUsersRepository->findActiveRecipients() as $admin) {
            if ((string) $admin['id'] === $adminUserId) {
                $name = trim((string) ($admin['display_name'] ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }
}
