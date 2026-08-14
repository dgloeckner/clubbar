<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use App\Shared\Logging\Logger;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport as SymfonyTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The real sender: `symfony/mailer` behind the project's own interface.
 *
 * The wrapper is not ceremony. It is what keeps three things out of the drain:
 * the DSN, Symfony's exception hierarchy, and the transient/permanent judgement
 * that decides whether a failure earns a retry.
 *
 * The transport is built lazily, on the first send. A drain tick that claims
 * nothing must not open a connection, and `Transport::fromDsn()` on a malformed
 * DSN throws where nobody is catching.
 */
final class SymfonyMailerTransport implements MailTransport
{
    private ?TransportInterface $transport = null;

    public function __construct(
        private MailDsn $dsn,
        private Logger $logger,
    ) {}

    public function send(MailMessage $message, MailSender $sender): MailSendResult
    {
        try {
            $email = $this->compose($message, $sender);
            $sentMessage = $this->transport()->send($email);

            return MailSendResult::sent($sentMessage?->getMessageId());
        } catch (TransportExceptionInterface $e) {
            $transient = self::isTransient($e);

            $this->logger->warning('Mail delivery failed', [
                'transient' => $transient,
                'transport' => $this->dsn->describe(),
                'error' => $e->getMessage(),
            ]);

            return $transient
                ? MailSendResult::transientFailure($e->getMessage())
                : MailSendResult::permanentFailure($e->getMessage());
        } catch (\Throwable $e) {
            // A malformed address, an unreadable embedded image, a DSN that
            // only fails on construction. None of these get better by waiting.
            $this->logger->error('Mail could not be composed or dispatched', [
                'transport' => $this->dsn->describe(),
                'error' => $e->getMessage(),
            ]);

            return MailSendResult::permanentFailure($e->getMessage());
        }
    }

    public function describe(): string
    {
        return $this->dsn->describe();
    }

    private function compose(MailMessage $message, MailSender $sender): Email
    {
        $email = (new Email())
            ->from(new Address($sender->address, $sender->name))
            ->to($message->toName === null
                ? new Address($message->to)
                : new Address($message->to, $message->toName))
            ->subject($message->subject)
            // Text first, then HTML: the order is what an RFC 2046 multipart
            // client reads as "prefer the last part it understands".
            ->text($message->text)
            ->html($message->html);

        if ($sender->replyTo !== null && $sender->replyTo !== '') {
            $email->replyTo(new Address($sender->replyTo));
        }

        foreach ($message->embeddedImages as $cid => $path) {
            $email->embedFromPath($path, (string) $cid);
        }

        return $email;
    }

    private function transport(): TransportInterface
    {
        return $this->transport ??= SymfonyTransport::fromDsn($this->dsn->raw);
    }

    /**
     * Is this worth another attempt?
     *
     * SMTP says so in the first digit: 4xx is "not now", 5xx is "no". Symfony
     * carries the server's reply in the exception code where it has one, and
     * zero where the failure happened below SMTP — a refused connection, a TLS
     * handshake, a DNS lookup. Those are the ones most worth retrying, so an
     * absent code counts as transient.
     *
     * Greylisting is the case this exists for: a 4xx on the first attempt is
     * the receiving MTA working as designed, not an error (ADR-0038).
     */
    private static function isTransient(TransportExceptionInterface $e): bool
    {
        $code = $e->getCode();

        if ($code >= 500 && $code < 600) {
            return false;
        }

        return true;
    }
}
