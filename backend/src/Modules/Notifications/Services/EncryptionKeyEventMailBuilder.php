<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\EncryptionKeyEventMail;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\Mail\MailMessage;

/**
 * Key lifecycle notices — registered, activated, revoked (ADR-0036).
 *
 * A separate builder rather than a branch in {@see CredentialExpiryMailBuilder}
 * because that class claims work by naming two kinds explicitly, not by subject
 * type: these share `MailSubject::ENCRYPTION_KEY` with the expiry warning and
 * still would not reach it. They are also a different sort of message — one
 * reports that somebody acted, the other that time passed.
 */
class EncryptionKeyEventMailBuilder implements MailContentBuilder
{
    private const KINDS = [
        MailKind::ENCRYPTION_KEY_REGISTERED,
        MailKind::ENCRYPTION_KEY_ACTIVATED,
        MailKind::ENCRYPTION_KEY_REVOKED,
    ];

    public function __construct(
        private EncryptionKeysRepository $encryptionKeysRepository,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the key the row points at is gone.
     *         `subject_id` is polymorphic and carries no foreign key, so this
     *         is reachable, and a notice about a key that no longer exists
     *         must not be invented around the gap.
     */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        $kind = MailKind::from((string) $outboxRow['kind']);
        $keyId = (string) $outboxRow['subject_id'];

        $key = $this->encryptionKeysRepository->findById($keyId);
        if ($key === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a key lifecycle notice: encryption key %s no longer exists',
                $keyId,
            ));
        }

        return EncryptionKeyEventMail::render(
            kind: $kind,
            // The row's snapshot, never re-read from admin_users: it is the
            // record of who was written to, and the address may have changed
            // since — the rule every other builder follows.
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            keyIdentifier: (string) $key['key_identifier'],
            fingerprintSha256: (string) $key['fingerprint_sha256'],
            actorLabel: $this->actorLabel($outboxRow),
            // When the notice was raised, not when the drain got to it: a
            // message that sat in the queue overnight still describes
            // something that happened yesterday.
            occurredAt: (string) ($outboxRow['queued_at'] ?? ''),
            language: MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null)),
            branding: $mailConfig->toBranding(),
        );
    }

    private function recipientName(array $outboxRow): ?string
    {
        $adminUserId = $outboxRow['admin_user_id'] ?? null;
        if (!is_string($adminUserId) || $adminUserId === '') {
            return null;
        }

        $admin = $this->adminUsersRepository->findById($adminUserId);
        $name = trim((string) ($admin['display_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Who acted, formatted for the "Ausgeführt von" row — `display name (login)`,
     * or just the login when no display name is set. Empty when the row predates
     * migration 043, or when the actor's admin account was later deleted
     * (`ON DELETE SET NULL`); the template drops the line rather than print one.
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

        $login = trim((string) ($actor['email'] ?? ''));
        if ($login === '') {
            return '';
        }

        $name = trim((string) ($actor['display_name'] ?? ''));

        return $name !== '' ? sprintf('%s (%s)', $name, $login) : $login;
    }
}
