<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use App\Shared\Logging\Logger;

/**
 * The transport for an installation with no `mail.dsn`.
 *
 * It sends nothing, logs a warning and never throws — the same shape
 * `LlmClientFactory` uses for absent LLM configuration, so a fresh install
 * runs rather than crashing on a feature it has not configured yet. It is also
 * the test default, which is how the promise "no test opens a socket" is kept.
 *
 * **It reports a permanent failure, not a success.** Two rejected alternatives:
 *
 * - Returning `sent` would put a timestamp on a message nobody received, and
 *   that timestamp is the club's own proof that a member was announced to.
 *   Writing it falsely is worse than any error state.
 * - Returning a *transient* failure would retry forever, quietly, on a
 *   condition no amount of retrying can fix.
 *
 * So the row goes to `failed` carrying the reason, the self-check shows the
 * transport as unconfigured (ADR-0038 layer M3), and the retry button (#407)
 * is there for once a DSN exists.
 */
final class NullTransport implements MailTransport
{
    private const REASON = 'no mail transport configured (mail.dsn is unset in config.php)';

    public function __construct(private Logger $logger) {}

    public function send(MailMessage $message, MailSender $sender): MailSendResult
    {
        $this->logger->warning('Mail not sent: ' . self::REASON, [
            'subject' => $message->subject,
        ]);

        return MailSendResult::permanentFailure(self::REASON);
    }

    public function describe(): string
    {
        return 'none (mail.dsn unset)';
    }
}
