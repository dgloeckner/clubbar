<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Services;

use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use App\Shared\Utils\Uuid;

/**
 * Mailing the club's registration link to somebody thinking of joining
 * (#821, ADR-0053, UC-A70).
 *
 * ## It is a message, not a credential
 *
 * What is sent is the poster's own URL, verbatim. The secret in it is already
 * printed on a wall the public walks past, so a copy of it in an inbox reaches
 * nobody the wall did not — and there is therefore nothing here to mint, expire,
 * revoke or store. No invitee table, no token, no schema of its own: the outbox
 * row *is* the record that a link was sent, and `mail_outbox.recipient` is the
 * whole of the invitation history (ADR-0052 decision 10 — a queue nobody empties
 * is exactly how personal data about somebody who never joined would accumulate).
 *
 * This class therefore does three things and nothing else: it refuses to send
 * when the club could not answer the link, it queues one row, and it writes an
 * audit entry naming the address.
 *
 * ## Sending is a promise
 *
 * {@see assertClubCanAnswer()} is the substance. A poster has an excuse for
 * going stale — it is paper, printed months ago, and the club cannot recall it.
 * A message composed one second ago has none, and mailing a link to
 * *"Anmeldung ist derzeit nicht möglich"* makes the club look broken to exactly
 * the person it is courting. The gate reuses the switch's own typed reasons, so
 * the admin is told which of the three preconditions is missing and can fix it
 * on the Security & Credentials screen (UC-A69) rather than filing a bug against
 * a button.
 *
 * It is a gate and not a guarantee: the club can switch registration off between
 * this call and the drain, and {@see \App\Modules\Notifications\Services\RegistrationLinkMailBuilder}
 * checks again at send time for exactly that reason.
 *
 * ## Why sending twice sends twice
 *
 * `dedup_key` carries a fresh nonce per send, which switches the outbox's unique
 * index effectively off for this kind. That index exists so a repeating *scan*
 * is idempotent — a digest must not send twice for one window. There is no scan
 * here: a human types an address and clicks send. Clicking again is the intent
 * ("I never got it"), and a key of the bare address would refuse that re-send
 * silently, from the database, behind a 204. The double click is guarded in the
 * UI, which is where the mistake actually happens.
 */
class RegistrationLinkService
{
    public function __construct(
        private SelfRegistrationAdminService $selfRegistration,
        private MailOutboxRepository $outbox,
        private AuditService $audit,
        private Logger $logger,
    ) {}

    /**
     * Queue one Anmeldelink to one address.
     *
     * @throws BusinessRuleException naming the precondition that does not hold
     */
    public function send(string $recipient, ?string $adminUserId): void
    {
        $recipient = trim($recipient);

        $this->assertClubCanAnswer();

        // German, always, and frozen into the row like every other kind's
        // language. There is no club-level default to read (`instance_config`
        // holds the club's name and nothing else), and inventing one as a side
        // effect of this feature was rejected in design — it belongs to #820.
        // The English wording exists in `MailStrings` already, so the day that
        // default lands this is one argument.
        $queued = $this->outbox->enqueue(MailRequestDto::forProspect(
            recipient: $recipient,
            language: MailLanguage::German,
            nonce: Uuid::v4(),
        ));

        if (!$queued) {
            // A UUID collided with itself, or the row was written twice in one
            // transaction. Neither is reachable; saying so beats a silent 204
            // for a message nobody will ever receive.
            throw new \RuntimeException('The Anmeldelink could not be queued');
        }

        // The address is in the entry on purpose (ADR-0053 decision 9): an admin
        // causing the installation to write to a named third party is the shape
        // of everything else in this log, and the address ages out with the log
        // rather than being exempted from it.
        $this->audit->log(
            action: AuditAction::REGISTRATION_LINK_SENT,
            entityType: EntityType::SELF_REGISTRATION,
            entityId: MailRequestDto::SELF_REGISTRATION_SUBJECT_ID,
            newValues: ['recipient' => $recipient, 'kind' => MailKind::REGISTRATION_LINK->value],
            adminUserId: $adminUserId,
        );

        // The address is deliberately absent from the log line. The audit entry
        // is the record that names it, is access-controlled, and ages out; the
        // application log is neither and is read over shoulders.
        $this->logger->info('Anmeldelink queued for a prospective member');
    }

    /**
     * The three preconditions, each refused by name.
     *
     * The same three the availability switch enforces, and deliberately so:
     * "can the club answer a scan right now" has one answer, and a second copy
     * of it that could disagree is how a link gets mailed to a page that refuses
     * it. Ordered from the most likely to be off to the least, so an admin is
     * told the thing they can most plausibly act on first.
     *
     * @throws BusinessRuleException
     */
    private function assertClubCanAnswer(): void
    {
        $settings = $this->selfRegistration->settings();

        if (!$settings->enabled) {
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_DISABLED,
                'Self-registration is switched off; there is no working link to send.',
            );
        }

        if (!$settings->hasSecret) {
            throw new BusinessRuleException(
                BusinessRuleReason::REGISTRATION_NO_SECRET,
                'No poster secret has been generated, so there is no registration link to send.',
            );
        }

        if ($settings->documentUrl === null || trim($settings->documentUrl) === '') {
            throw new BusinessRuleException(
                BusinessRuleReason::DOCUMENT_URL_MISSING,
                'Configure the club document URL before sending a registration link.',
            );
        }
    }
}
