<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Mail\InvalidMailDsnException;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;
use App\Shared\Mail\MailSendResult;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Services\AuditService;

/**
 * "Send me a test mail" — the one diagnostic that opens a socket from a request
 * (#407).
 *
 * ### The rule this sits next to, and why it is not broken
 *
 * ADR-0038 rule 3 says there is exactly one sending path: the scheduler drains
 * the outbox, and nothing else sends. That rule is about **queued messages** —
 * a settlement's announcements must not leave from inside an HTTP request,
 * because a gateway timeout mid-loop produces a settlement whose announcement
 * state cannot be reconstructed, and because the queue *is* the record of what
 * the club committed to.
 *
 * None of that applies here. This message is not queued and never will be, it
 * carries no member data, there is nothing to reconstruct if the request dies,
 * and its entire value is the error text coming back **while the admin is still
 * looking at the screen**. Queuing it would produce the worst of both: the
 * admin waits an hour for a diagnosis, and a `mail_outbox` row exists that
 * corresponds to nothing anybody was promised.
 *
 * Two boundaries keep that argument honest, and both are enforced rather than
 * documented:
 *
 * 1. **The recipient is the requesting admin's own address**, read from the
 *    session's admin id — never from the request body. Otherwise this endpoint
 *    is an authenticated open relay, and "send arbitrary mail from the club's
 *    domain" is a considerably better prize than anything else in the panel.
 * 2. **The content is fixed.** No template, no member, no settlement, nothing
 *    interpolated from a request. A test mail cannot be turned into a message
 *    to somebody about something.
 *
 * `DrainService::run()` still has exactly two callers, and this class is not
 * one of them — the review check for a second *queue* sender holds.
 */
class TestMailService
{
    public function __construct(
        private MailConfigService $mailConfigService,
        private MailTransportFactory $mailTransportFactory,
        private AdminUsersRepository $adminUsersRepository,
        private AuditService $auditService,
    ) {}

    /**
     * Send the requesting admin a test mail and report what happened.
     *
     * Never throws: every failure mode here is something the admin is asking
     * about, so it is an answer rather than an exception. A 500 would replace
     * the diagnosis with the absence of one.
     *
     * @return array{sent:bool,recipient:?string,transport:?string,error:?string}
     */
    public function sendTo(string $adminUserId): array
    {
        $admin = $this->adminUsersRepository->findById($adminUserId);
        $recipient = trim((string) ($admin['email'] ?? ''));

        if ($recipient === '') {
            return self::failure(null, null, 'Your admin account has no email address, so there is nowhere to send a test.');
        }

        $config = $this->mailConfigService->getConfig();
        if (!$config->isComplete()) {
            return self::failure($recipient, null,
                'No sender address is configured. Set one under Settings → Mail — nothing can invent a mailbox the club owns.');
        }

        try {
            $status = $this->mailTransportFactory->status();
            if (!$status->configured) {
                return self::failure($recipient, null,
                    'No mail.dsn is configured, so this installation has no transport. It lives in config.php next to '
                    . 'the database password (ADR-0031) and is not editable here.');
            }

            $transport = $this->mailTransportFactory->create();
        } catch (InvalidMailDsnException $e) {
            return self::failure($recipient, null, 'mail.dsn is unusable: ' . $e->getMessage());
        }

        $result = $this->send($transport->send(self::message($recipient, $config->toBranding()), $config->toSender()));

        $this->auditService->log(
            action: AuditAction::MAIL_TEST_SENT,
            entityType: EntityType::MAIL_CONFIG,
            entityId: '1',
            // The address is the admin's own and is already in `admin_users`;
            // what makes the entry worth keeping is *that* a socket was opened
            // from a request, by whom, and whether it worked.
            newValues: ['sent' => $result['sent'], 'transport' => $transport->describe()],
            adminUserId: $adminUserId,
        );

        return $result + ['recipient' => $recipient, 'transport' => $transport->describe()];
    }

    /** @return array{sent:bool,error:?string} */
    private function send(MailSendResult $result): array
    {
        return [
            'sent' => $result->sent,
            // The transport's own words, unedited. A test mail's whole purpose
            // is the server's answer — "authentication failed", "connection
            // refused", "relay access denied" each point somewhere different,
            // and a friendly summary would erase the difference.
            'error' => $result->sent ? null : ($result->error ?? 'unknown error'),
        ];
    }

    /**
     * @return array{sent:bool,recipient:?string,transport:?string,error:?string}
     */
    private static function failure(?string $recipient, ?string $transport, string $error): array
    {
        return ['sent' => false, 'recipient' => $recipient, 'transport' => $transport, 'error' => $error];
    }

    /**
     * Fixed content, in German only.
     *
     * Not translated on purpose: this is read once, by the person who just
     * pressed the button, and it exists to prove the layout and the transport
     * work rather than to say anything. Wiring it to `MailStrings` would put a
     * translation key in front of every future maintainer for a message whose
     * text nobody will ever read twice.
     */
    private static function message(string $recipient, \App\Shared\Mail\MailBranding $branding): MailMessage
    {
        $subject = 'Testnachricht aus der Vereinsbar';

        $html = MailLayout::document($branding, [
            'title' => $subject,
            'preview' => 'Der Mailversand dieser Installation funktioniert.',
            'lang' => 'de',
            'content' => MailLayout::contentStart()
                . MailLayout::eyebrow('Testnachricht')
                . MailLayout::title('Der Mailversand funktioniert')
                . MailLayout::lede(
                    'Diese Nachricht wurde aus den Mail-Einstellungen heraus verschickt. Sie bestätigt, dass der '
                    . 'konfigurierte Mailserver erreichbar ist und Nachrichten dieser Installation annimmt.'
                )
                . MailLayout::paragraph(
                    'Damit Vorabankündigungen tatsächlich rausgehen, muss zusätzlich der Scheduler laufen: '
                    . 'die Warteschlange wird ausschließlich von ihm geleert.'
                )
                . MailLayout::contentEnd(),
        ]);

        $text = "Testnachricht\n\n"
            . "Der Mailversand funktioniert.\n\n"
            . "Diese Nachricht wurde aus den Mail-Einstellungen heraus verschickt. Sie bestaetigt, dass der "
            . "konfigurierte Mailserver erreichbar ist und Nachrichten dieser Installation annimmt.\n\n"
            . "Damit Vorabankuendigungen tatsaechlich rausgehen, muss zusaetzlich der Scheduler laufen: "
            . "die Warteschlange wird ausschliesslich von ihm geleert.\n";

        return new MailMessage(
            to: $recipient,
            subject: $subject,
            html: $html,
            text: $text,
        );
    }
}
