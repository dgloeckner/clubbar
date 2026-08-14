<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * A configured `mail.dsn` that cannot be used.
 *
 * Typed rather than a bare RuntimeException because the self-check renders the
 * reason to a treasurer who cannot read a stack trace (ADR-0038, layer M3):
 * "mail.dsn names an unsupported scheme 'smpt://' — expected smtp, smtps,
 * sendmail, native or null" is actionable; "invalid DSN" is not.
 */
final class InvalidMailDsnException extends \RuntimeException
{
    public static function emptyScheme(string $dsn): self
    {
        return new self(sprintf(
            'mail.dsn is missing a scheme — expected something like smtp://user:pass@host:587, got "%s"',
            MailDsn::redactValue($dsn),
        ));
    }

    public static function unsupportedScheme(string $scheme): self
    {
        return new self(sprintf(
            'mail.dsn names the unsupported scheme "%s" — expected one of: %s',
            $scheme,
            implode(', ', MailDsn::SUPPORTED_SCHEMES),
        ));
    }

    public static function unparseable(string $dsn): self
    {
        return new self(sprintf('mail.dsn could not be parsed: "%s"', MailDsn::redactValue($dsn)));
    }
}
