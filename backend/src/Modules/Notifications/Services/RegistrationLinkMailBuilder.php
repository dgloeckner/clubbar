<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\RegistrationLinkMail;
use App\Modules\Registrations\Domain\PosterSecret;
use App\Modules\Registrations\Services\SelfRegistrationAdminService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Mail\MailMessage;

/**
 * The Anmeldelink, rendered at send time (#821, ADR-0053, ADR-0038 rule 5).
 *
 * Claims its kind **by name** rather than by subject, the way
 * {@see MemberLifecycleMailBuilder} does: `MailSubject::SELF_REGISTRATION` has
 * exactly one kind today, and a subject-wide claim is a standing offer to
 * render the next one too — which the registry would accept silently, because
 * it resolves to whichever builder claims a kind first.
 *
 * ## The link is rebuilt here, from the secret the club currently holds
 *
 * Nothing about the link is stored in the queue row. The row says only "send
 * somebody the way in"; *what* the way in is comes from
 * `self_registration_config` at send time, which is the same place the poster
 * reads it from and is what makes the mailed link genuinely the poster's URL
 * rather than a copy that can drift from it.
 *
 * That has one visible consequence, and it is the trade ADR-0053 accepts: a
 * rotation between enqueue and drain sends the *new* secret, and a rotation
 * after delivery kills the link in the reader's inbox exactly as it kills every
 * poster on every wall. Neither is a bug to be worked around — a per-send copy
 * of the secret would mean `self_registration_config` holding a *set* of live
 * secrets, which is precisely the rotation story ADR-0052 refuses to give up.
 *
 * ## A club that cannot answer the link does not send one
 *
 * The send endpoint gates on the same three preconditions before anything is
 * queued, so reaching a failure here means the club changed its mind between
 * enqueue and drain — switched registration off, cleared the document URL, or
 * lost the key that reads the secret back. Every one of those **throws**, and
 * the drain records the failure against the message where a Kassenwart can see
 * it and decide. Sending anyway would put a link to a refusal page in the inbox
 * of somebody the club is courting, which is the outcome the gate exists to
 * prevent and is worse than a queued message that visibly did not go.
 */
class RegistrationLinkMailBuilder implements MailContentBuilder
{
    public function __construct(
        private SelfRegistrationAdminService $registrations,
        /** The installation's public base URL — the origin the SPA is served from. */
        private string $appUrl,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::REGISTRATION_LINK;
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the club can no longer answer the link it
     *         was asked to send.
     */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        // The snapshot, never a re-read: there is nothing to re-read it from.
        // This person has no row anywhere, which is the whole point (ADR-0053).
        $recipient = trim((string) ($outboxRow['recipient'] ?? ''));

        $settings = $this->registrations->settings();
        if (!$settings->enabled) {
            throw new \RuntimeException(
                'Self-registration was switched off after this Anmeldelink was queued; '
                . 'refusing to mail a link to a refusal page'
            );
        }

        try {
            $secret = $this->registrations->currentSecret();
        } catch (BusinessRuleException $e) {
            // No secret, or one the current key cannot open. Both are recorded
            // against the message rather than swallowed: the club can rotate,
            // then re-send from the same screen.
            throw new \RuntimeException(
                'The club has no readable poster secret; refusing to mail a link that opens nothing: '
                . $e->getMessage(),
                previous: $e,
            );
        }

        return RegistrationLinkMail::render(
            recipientAddress: $recipient,
            url: PosterSecret::url($this->appUrl, $secret),
            language: MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null)),
            branding: $mailConfig->toBranding(),
        );
    }
}
