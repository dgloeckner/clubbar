<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * The one configuration value that selects a mail transport (ADR-0038, rule 2).
 *
 * A single DSN covers SMTP, the host's local MTA and — later — an HTTP API
 * bridge, so the treasurer configures exactly one field. It holds an SMTP
 * password, so it lives in `config.php` in the data directory next to the DB
 * password and the TOTP key (ADR-0031 decision 2) and is deliberately not
 * admin-editable.
 *
 * This class parses and validates; it never connects. Validation catches the
 * failures that are cheap to catch — a typo'd scheme, a missing host — and
 * says so in a sentence the self-check can print. Everything else (wrong
 * password, blocked port, host down) can only be learned by sending, and is
 * reported per message by the drain instead.
 */
final readonly class MailDsn
{
    /**
     * `null://` is Symfony's discard transport. It is listed as supported on
     * purpose: it is the one way to say "accept mail and drop it" explicitly,
     * which is what a staging copy of a production database wants. An *absent*
     * DSN means something different — see MailTransportFactory.
     */
    public const SUPPORTED_SCHEMES = ['smtp', 'smtps', 'sendmail', 'native', 'null'];

    private function __construct(
        public string $scheme,
        public ?string $host,
        public ?int $port,
        public string $raw,
    ) {}

    /**
     * @throws InvalidMailDsnException when the value cannot be used as a DSN
     */
    public static function parse(string $dsn): self
    {
        $dsn = trim($dsn);

        if (!str_contains($dsn, '://')) {
            throw InvalidMailDsnException::emptyScheme($dsn);
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme'])) {
            throw InvalidMailDsnException::unparseable($dsn);
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::SUPPORTED_SCHEMES, true)) {
            throw InvalidMailDsnException::unsupportedScheme($scheme);
        }

        // No separate "smtp:// needs a host" check: PHP's parse_url already
        // refuses an empty authority (`smtp://`, `smtp:///queue`, `smtp://:587`
        // all return false), so such a check could never fire and would only
        // read as a guarantee this class does not actually make.
        return new self($scheme, $parts['host'] ?? null, $parts['port'] ?? null, $dsn);
    }

    /**
     * The DSN with its password replaced, safe to log or show in the admin UI.
     *
     * A DSN is a secret precisely because of the part this removes, and a
     * self-check row that cannot be shown is a self-check row nobody reads.
     */
    public function redacted(): string
    {
        return self::redactValue($this->raw);
    }

    public static function redactValue(string $dsn): string
    {
        return (string) preg_replace('#(://[^:/@]*):[^@]*@#', '$1:***@', $dsn);
    }

    /** A one-line description for the self-check: "smtp to mail.example.org:587". */
    public function describe(): string
    {
        if ($this->host === null) {
            return $this->scheme;
        }

        return $this->port === null
            ? sprintf('%s to %s', $this->scheme, $this->host)
            : sprintf('%s to %s:%d', $this->scheme, $this->host, $this->port);
    }
}
