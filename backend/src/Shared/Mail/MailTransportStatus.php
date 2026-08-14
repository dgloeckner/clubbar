<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * What the self-check prints about mail configuration (ADR-0038 layer M3).
 *
 * Deliberately separate from building a transport: the diagnosis surface must
 * be able to say "your DSN is wrong, here is why" without the attempt to build
 * a transport throwing in the middle of rendering a page.
 */
final readonly class MailTransportStatus
{
    private function __construct(
        public bool $configured,
        public bool $valid,
        public string $summary,
        public ?string $error,
    ) {}

    public static function unconfigured(): self
    {
        return new self(false, false, 'not configured (mail.dsn unset — no mail is sent)', null);
    }

    public static function ok(MailDsn $dsn): self
    {
        return new self(true, true, $dsn->describe(), null);
    }

    public static function invalid(string $error): self
    {
        return new self(true, false, 'misconfigured', $error);
    }
}
