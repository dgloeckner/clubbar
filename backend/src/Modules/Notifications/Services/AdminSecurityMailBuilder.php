<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailSubject;
use App\Modules\Notifications\Mail\AdminEmailChangedMail;
use App\Modules\Notifications\Mail\AdminLifecycleMail;
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
        private AdminUserRolesRepository $adminUserRolesRepository,
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
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
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
                branding: $mailConfig->toBranding(),
            ),
            // The lifecycle notices (ADR-0044) are *about* the account in
            // `subject_id` and addressed to somebody else — every active admin,
            // and the club-level address, which belongs to no account at all.
            // So the recipient's name comes from the row's own recipient rather
            // than from the subject, and is simply absent for the club copy.
            MailKind::ADMIN_ACCOUNT_CREATED => AdminLifecycleMail::renderAccountCreated(
                recipientAddress: $recipient,
                recipientName: $this->recipientName($outboxRow),
                accountLabel: $this->accountLabel($admin),
                roles: $this->adminUserRolesRepository->rolesFor($adminUserId),
                occurredAt: (string) ($outboxRow['queued_at'] ?? ''),
                actorLabel: $this->actorLabel($outboxRow),
                language: $language,
                branding: $this->mailConfigService->getConfig()->toBranding(),
            ),
            MailKind::ADMIN_ROLE_CHANGED => AdminLifecycleMail::renderRoleChanged(
                recipientAddress: $recipient,
                recipientName: $this->recipientName($outboxRow),
                accountLabel: $this->accountLabel($admin),
                roles: $this->adminUserRolesRepository->rolesFor($adminUserId),
                occurredAt: (string) ($outboxRow['queued_at'] ?? ''),
                actorLabel: $this->actorLabel($outboxRow),
                language: $language,
                branding: $this->mailConfigService->getConfig()->toBranding(),
            ),
            default => throw new \InvalidArgumentException(
                sprintf('%s has no content builder yet', $kind->value)
            ),
        };
    }

    /**
     * The account this notice is *about*, as `display name (login)`.
     *
     * The login is always present — it is what the person signs in with, and
     * the only identifier that is unique — so a missing display name degrades
     * to the address rather than to nothing.
     *
     * @param array<string,mixed> $admin
     */
    private function accountLabel(array $admin): string
    {
        $login = trim((string) ($admin['email'] ?? ''));
        $name = trim((string) ($admin['display_name'] ?? ''));

        if ($login === '') {
            return $name;
        }

        return $name !== '' && $name !== $login ? sprintf('%s (%s)', $name, $login) : $login;
    }

    /**
     * Who is being written to, for the greeting — the *recipient's* display
     * name, which is a different account from the subject on every one of these
     * messages except when an admin's own account is the one that changed.
     *
     * Null for the club-level copy: `admin_user_id` is deliberately empty on
     * that row (it is an address the club configured, not an account), so the
     * greeting falls back to the impersonal form rather than inventing a name.
     *
     * @param array<string,mixed> $outboxRow
     */
    private function recipientName(array $outboxRow): ?string
    {
        $recipientId = $outboxRow['admin_user_id'] ?? null;
        if (!is_string($recipientId) || $recipientId === '') {
            return null;
        }

        $recipient = $this->adminUsersRepository->findById($recipientId);
        $name = trim((string) ($recipient['display_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Who acted, as `display name (login)`. Empty when the row carries no
     * actor, or when that admin's account has since been deleted
     * (`ON DELETE SET NULL`, migration 043) — the template drops the row rather
     * than printing a blank one.
     *
     * @param array<string,mixed> $outboxRow
     */
    private function actorLabel(array $outboxRow): string
    {
        $actorId = $outboxRow['actor_admin_user_id'] ?? null;
        if (!is_string($actorId) || $actorId === '') {
            return '';
        }

        $actor = $this->adminUsersRepository->findById($actorId);
        if ($actor === null) {
            return '';
        }

        return $this->accountLabel($actor);
    }
}
