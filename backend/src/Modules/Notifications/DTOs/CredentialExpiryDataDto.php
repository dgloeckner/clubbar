<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * What a credential expiry warning says (#438, ADR-0036).
 *
 * Four facts and nothing else: **which credential, which tier, act before this
 * date, and here is what "act" means.** That list is the issue's own
 * specification of the content, and the omissions are the design.
 *
 * No key material and no token, obviously — but the rule is stricter than
 * "obviously", because the near-miss is what a warning about a secret naturally
 * reaches for. The key is named by its `key_identifier`, which is the public
 * label the dashboard already shows; the terminal is named by the name an admin
 * gave it. Neither the public key, nor a fingerprint, nor any part of a token
 * hash appears, and the tests assert their absence rather than trusting the
 * template to have kept omitting them.
 */
final readonly class CredentialExpiryDataDto
{
    /**
     * @param string $credential  `key` or `terminal` — a label for a translation
     *                            key, not a domain enum: the mail module renders
     *                            both from one template and neither module owns
     *                            the pairing.
     * @param string $name        The key identifier, or the terminal's name.
     * @param int    $tierDays    The tier that fired: 90, 30 or 7.
     * @param int    $daysLeft    Whole days until expiry as of rendering. Not the
     *                            same number as the tier and deliberately shown
     *                            beside it — the tier says which warning this is,
     *                            this says how long is actually left.
     * @param string $expiresOn   The deadline, already formatted for the reader.
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        public string $credential,
        public string $name,
        public int $tierDays,
        public int $daysLeft,
        public string $expiresOn,
    ) {}
}
