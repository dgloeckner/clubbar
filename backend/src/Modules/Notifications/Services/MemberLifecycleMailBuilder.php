<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\MemberCardMail;
use App\Modules\Notifications\Mail\MemberEmailChangeMail;
use App\Shared\Mail\MailMessage;

/**
 * The four notices a member gets about their own record (ADR-0051), rendered
 * at send time (ADR-0038 rule 5).
 *
 * Claimed by kind rather than by subject, unlike {@see AdminSecurityMailBuilder}.
 * `MailSubject::MEMBER` is shared with {@see DeckelStatementMailBuilder}, which
 * reads the row's `dedup_key` as a statement period — and
 * {@see MailContentRegistry} takes the *first* builder that claims a kind, with
 * no complaint about a second. A subject-wide `supports()` here would therefore
 * shadow the Deckelauszug or be shadowed by it depending on the order in
 * `ServiceFactory`, silently, and a statement would render as a welcome or
 * throw on a period key that is really the string `welcome`.
 *
 * **The recipient is never re-derived**, for the reason `AdminSecurityMailBuilder`
 * gives about its own: by the time the drain runs, `members.email` holds the
 * *new* address, and the whole point of `MEMBER_EMAIL_CHANGED` is to reach the
 * old one. The snapshot column is where it lives, and reading it uniformly for
 * all four keeps the one that matters from being a special case somebody later
 * "tidies up".
 *
 * The member row is still read, for the first name. A member who is gone
 * throws rather than sending an anonymous greeting into a mailbox — though in
 * practice erasure supersedes these rows before the drain sees them
 * (ADR-0029, #408).
 */
class MemberLifecycleMailBuilder implements MailContentBuilder
{
    public function __construct(
        private MembersRepository $membersRepository,
        private MailConfigService $mailConfigService,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * The kinds this builder owns.
     *
     * A named constant so the "claims exactly these" test can assert against
     * the set rather than restate it — a new member kind added to
     * {@see MailKind} without a branch in {@see build()} should fail loudly,
     * and listing it here without rendering it would hide that.
     */
    private const KINDS = [
        MailKind::MEMBER_WELCOME,
        MailKind::MEMBER_CARD_REPLACED,
        MailKind::MEMBER_EMAIL_CHANGED,
        MailKind::MEMBER_EMAIL_ACTIVATED,
    ];

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the member behind the row is gone. The FK
     *         cascades, so this means the row was deleted by hand.
     * @throws \InvalidArgumentException On a kind this builder does not own.
     */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        $kind = MailKind::from((string) $outboxRow['kind']);
        $memberId = (string) $outboxRow['subject_id'];
        $language = MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null));

        $member = $this->membersRepository->findMailRecipients([$memberId])[$memberId] ?? null;
        if ($member === null) {
            throw new \RuntimeException(
                sprintf('Member %s is gone; refusing to build a lifecycle notice for it', $memberId)
            );
        }

        // The snapshot, never members.email — see the class comment.
        $recipient = trim((string) ($outboxRow['recipient'] ?? ''));
        $firstName = self::firstName($member);
        $branding = $this->mailConfigService->getConfig()->toBranding();

        return match ($kind) {
            MailKind::MEMBER_WELCOME,
            MailKind::MEMBER_CARD_REPLACED => MemberCardMail::render(
                kind: $kind,
                recipientAddress: $recipient,
                firstName: $firstName,
                language: $language,
                branding: $branding,
            ),
            MailKind::MEMBER_EMAIL_CHANGED,
            MailKind::MEMBER_EMAIL_ACTIVATED => MemberEmailChangeMail::render(
                kind: $kind,
                recipientAddress: $recipient,
                firstName: $firstName,
                // The moment of the change: the enqueue happens in the same
                // call that writes it, so the row's own timestamp is it.
                changedAt: (string) ($outboxRow['queued_at'] ?? ''),
                language: $language,
                branding: $branding,
            ),
            default => throw new \InvalidArgumentException(
                sprintf('%s is not a member lifecycle notice', $kind->value)
            ),
        };
    }

    /**
     * The name to greet by, or null for the generic greeting.
     *
     * First name alone, which is the register {@see \App\Modules\Notifications\Mail\MailStrings}
     * sets for every member-facing message. An anonymized member has none, and
     * `MailTextBody::greeting()` handles that.
     *
     * @param array<string,mixed> $member
     */
    private static function firstName(array $member): ?string
    {
        $name = trim((string) ($member['first_name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
