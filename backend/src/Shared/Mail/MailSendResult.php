<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * What happened to one message.
 *
 * A transport returns this rather than throwing, because the caller that
 * matters — the drain (#403) — has to record every outcome and carry on with
 * the rest of the batch. One member's dead mailbox must not stop the other
 * forty-six announcements.
 *
 * The distinction that earns its keep is `transient`. Greylisting is ordinary
 * operation, not an error: many receiving MTAs reject the first attempt on
 * purpose and expect a second one ~15 minutes later. A transient failure buys a
 * backoff and another go; a permanent one goes to `failed` with its reason
 * visible to the Kassenwart, who decides whether to pick up the phone
 * (ADR-0038 rule 6).
 */
final readonly class MailSendResult
{
    private function __construct(
        public bool $sent,
        public ?string $messageId,
        public ?string $error,
        public bool $transient,
    ) {}

    public static function sent(?string $messageId = null): self
    {
        return new self(true, $messageId, null, false);
    }

    /** Try again later: greylisting, a 4xx, a reset connection, a host that is down right now. */
    public static function transientFailure(string $error): self
    {
        return new self(false, null, $error, true);
    }

    /** Do not try again: a rejected recipient, a refused relay, an unusable configuration. */
    public static function permanentFailure(string $error): self
    {
        return new self(false, null, $error, false);
    }
}
