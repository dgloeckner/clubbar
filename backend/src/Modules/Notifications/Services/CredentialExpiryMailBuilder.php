<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\CredentialExpiryDataDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\CredentialExpiryMail;
use App\Modules\Notifications\Mail\MailFormat;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Mail\MailMessage;
use App\Shared\Security\CredentialLifecycle;

/**
 * Renders a credential expiry warning at send time (#438, ADR-0038 rule 5).
 *
 * Claims both expiry kinds, because they are one message about two subjects —
 * see {@see CredentialExpiryMail} for why that is one template rather than two.
 *
 * The **tier** comes from the `dedup_key`, which is the only place it is
 * recorded: `warnAdmins()` writes it as `occasion:adminUserId`, and the occasion
 * is what {@see CredentialExpiryNotifier::occasion()} produced. A row whose key
 * names no tier — hand-written, or from a future occasion format — is not
 * guessed at; it throws, and the drain records that against the message.
 *
 * The **days remaining** are recomputed here rather than carried, which is the
 * rule ADR-0038 makes for every builder and which pays off visibly in this one:
 * a queue drained an hour after it was filled would otherwise say "7 days" for
 * as long as the row survived retries. Recomputing means the number in the mail
 * is true when the mail is sent.
 *
 * That has one consequence worth naming: a credential **rotated between enqueue
 * and drain** renders against its *new* expiry and so goes out saying the key is
 * fine for another year. Odd, and better than the alternatives — dropping the
 * row silently loses a message somebody may be waiting on, and holding the old
 * numbers sends a warning that was already answered. A tick is fifteen minutes;
 * the window is small and its worst outcome is a reassuring email.
 */
class CredentialExpiryMailBuilder implements MailContentBuilder
{
    public function __construct(
        private EncryptionKeysRepository $encryptionKeysRepository,
        private TerminalsRepository $terminalsRepository,
        private AdminUsersRepository $adminUsersRepository,
        private MailConfigService $mailConfigService,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::KEY_EXPIRY_WARNING
            || $kind === MailKind::TERMINAL_TOKEN_EXPIRY_WARNING;
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the credential the row points at is gone,
     *         or the row names no tier. `subject_id` is polymorphic and carries
     *         no foreign key, so the first is reachable — and a warning about a
     *         credential that no longer exists must not be invented around the
     *         gap.
     */
    public function build(array $outboxRow): MailMessage
    {
        $kind = MailKind::from((string) $outboxRow['kind']);
        $subjectId = (string) $outboxRow['subject_id'];

        $tier = CredentialExpiryNotifier::tierFromDedupKey((string) ($outboxRow['dedup_key'] ?? ''));
        if ($tier === null || !in_array($tier, CredentialLifecycle::WARNING_TIERS, true)) {
            throw new \RuntimeException(sprintf(
                'Cannot build a credential expiry warning: %s names no known warning tier',
                (string) ($outboxRow['dedup_key'] ?? ''),
            ));
        }

        $language = MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null));

        [$credential, $name, $expiresAt] = $kind === MailKind::KEY_EXPIRY_WARNING
            ? $this->encryptionKey($subjectId)
            : $this->terminal($subjectId);

        return CredentialExpiryMail::render(new CredentialExpiryDataDto(
            language: $language,
            // The row's snapshot, never re-read from admin_users: it is the
            // record of who was written to, and the address may have changed
            // since — the same rule every other builder follows.
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            branding: $this->mailConfigService->getConfig()->toBranding(),
            credential: $credential,
            name: $name,
            tierDays: $tier,
            // Recomputed at send time, and floored at zero: a row that sat in
            // the queue past the deadline should read "0 days", not a negative
            // number that renders as "in -2 days".
            daysLeft: max(0, CredentialLifecycle::daysUntilExpiry($expiresAt) ?? $tier),
            expiresOn: MailFormat::date($expiresAt, $language),
        ));
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function encryptionKey(string $keyId): array
    {
        $key = $this->encryptionKeysRepository->findById($keyId);

        if ($key === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a key expiry warning: encryption key %s no longer exists',
                $keyId,
            ));
        }

        // `key_identifier` is the public label the dashboard already shows.
        // Nothing else about the key is read — not the public key, not a
        // fingerprint — and that is the whole content rule for this message.
        return ['key', (string) $key['key_identifier'], $key['expires_at'] ?? null];
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function terminal(string $terminalId): array
    {
        $terminal = $this->terminalsRepository->findById($terminalId);

        if ($terminal === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a terminal token expiry warning: terminal %s no longer exists',
                $terminalId,
            ));
        }

        return ['terminal', (string) $terminal['name'], $terminal['token_expires_at'] ?? null];
    }

    /** @param array<string,mixed> $outboxRow */
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
}
