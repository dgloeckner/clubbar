<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailSubject;
use App\Modules\Notifications\Mail\AdminEmailChangedMail;
use App\Shared\Mail\MailMessage;

/**
 * Security notices about an admin's own account, rendered at send time
 * (ADR-0038 rule 5).
 *
 * Registered for the whole `ADMIN_USER` subject rather than for one kind, so
 * the next notice about an admin account lands as a branch here instead of a
 * second builder for the same subject — the shape `SettlementMailBuilder`
 * already uses for its three kinds.
 *
 * What is *not* re-derived is the recipient. Every other builder may re-read a
 * contact address; this one must not, and the reason is the whole point of the
 * message: `admin_users.email` already holds the **new** address by the time
 * the drain runs. The former address survives only in the outbox row's
 * `recipient` snapshot, and that is where it is read from.
 *
 * The account itself is still looked up — for the display name, and to confirm
 * the subject exists. A missing one throws rather than inventing a message
 * around the gap.
 */
class AdminSecurityMailBuilder implements MailContentBuilder
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private MailConfigService $mailConfigService,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind->subjectType() === MailSubject::ADMIN_USER;
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the admin account behind the row is gone.
     *         The FK cascades, so this means the row was deleted by hand.
     * @throws \InvalidArgumentException On an `ADMIN_USER` kind this builder
     *         has no content for.
     */
    public function build(array $outboxRow): MailMessage
    {
        $kind = MailKind::from((string) $outboxRow['kind']);
        $adminUserId = (string) $outboxRow['subject_id'];
        $language = MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null));

        $admin = $this->adminUsersRepository->findById($adminUserId);
        if (!$admin) {
            throw new \RuntimeException(
                sprintf('Admin user %s is gone; refusing to build a security notice for it', $adminUserId)
            );
        }

        // The snapshot, never admin_users.email — see the class comment.
        $recipient = trim((string) ($outboxRow['recipient'] ?? ''));

        return match ($kind) {
            MailKind::ADMIN_EMAIL_CHANGED => AdminEmailChangedMail::render(
                recipientAddress: $recipient,
                recipientName: $admin['display_name'] ?? null,
                changedAt: (string) ($outboxRow['queued_at'] ?? ''),
                language: $language,
                branding: $this->mailConfigService->getConfig()->toBranding(),
            ),
            default => throw new \InvalidArgumentException(
                sprintf('%s has no content builder yet', $kind->value)
            ),
        };
    }
}
