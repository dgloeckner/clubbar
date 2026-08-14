<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * The seam between "what to send" and "how it leaves the host" (ADR-0038 rule 2).
 *
 * Mirrors the LlmClientInterface / VisionClientInterface shape already in the
 * codebase: one interface, a factory that reads configuration, and a null
 * implementation so an unconfigured install degrades instead of failing.
 *
 * Implementations must not throw. Every outcome is a MailSendResult, because
 * the drain records outcomes for a whole batch and a thrown exception loses the
 * rest of it.
 */
interface MailTransport
{
    public function send(MailMessage $message, MailSender $sender): MailSendResult;

    /**
     * A short description of where mail goes, for the self-check and the
     * cron log — never containing the password.
     */
    public function describe(): string;
}
