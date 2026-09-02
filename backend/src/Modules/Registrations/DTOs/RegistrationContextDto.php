<?php

declare(strict_types=1);

namespace App\Modules\Registrations\DTOs;

use App\Shared\Branding\PublicBranding;

/**
 * What the onboarding page needs to know before it renders anything (#781).
 *
 * The page a QR code opens has one decision to make first: form, or the club's
 * paused screen. It cannot make that by trying a submission — by then the
 * applicant has typed a name, a birth date and an IBAN into a form that was
 * never going to be accepted.
 *
 * ## The shape mirrors a refusal on purpose
 *
 * `reason` and `message` are exactly what a refused `POST /registrations`
 * carries, so the page renders an unavailable club the same way whether it
 * learned about it on load or by racing an admin who switched it off mid-form.
 * One rendering path, and no second vocabulary to keep in step.
 *
 * ## What it deliberately does not carry
 *
 * Nothing about members, nothing about how many registrations are pending, and
 * nothing about whether this club already knows the visitor. The endpoint
 * behind it is reached by an anonymous phone, and every field here is club
 * configuration a poster-holder is standing in front of anyway.
 *
 * ## Branding travels with it, and only past the gate
 *
 * The club's name and mark ({@see PublicBranding}) are what let a stranger see
 * whose form is asking for their IBAN — an unbranded one is indistinguishable
 * from a phishing page. They ride this answer rather than a second request
 * because the page has already made one round trip on clubhouse wifi and a
 * header that arrives late is a header that flashes.
 *
 * They are on the answer to a *matching* secret only. A wrong one is still the
 * uniform 404 with no body at all, so nothing here is an oracle: what a
 * poster-holder learns is what the poster already told them.
 */
final readonly class RegistrationContextDto
{
    public function __construct(
        /** Whether the form should be rendered at all. */
        public bool $available,
        /** A `BusinessRuleReason` value when unavailable, so the page can translate it. */
        public ?string $reason,
        /**
         * The club's own words, when it has any — "Beta-Phase schon voll".
         * Written by an admin and rendered as text, never as markup.
         */
        public ?string $message,
        /**
         * The club's published Anmeldung: the Art. 13 notice the page links
         * before any data entry, and the document that gets filled. One URL,
         * both jobs (ADR-0052 decision 6).
         */
        public ?string $documentUrl,
        /** @var list<string> The languages the page may offer. */
        public array $languages,
        /**
         * Who is asking, for the page's masthead and footer.
         *
         * Optional and last: an installation wired without a branding provider
         * still renders a working form, under the neutral header the page falls
         * back to.
         */
        public PublicBranding $branding = new PublicBranding(),
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'reason' => $this->reason,
            'message' => $this->message,
            'document_url' => $this->documentUrl,
            'languages' => $this->languages,
        ] + $this->branding->toArray();
    }
}
