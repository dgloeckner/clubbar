<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * One message, ready to hand to a transport.
 *
 * **Multipart is mandatory** (#401, and the reasoning in the FRGS mail design
 * doc): every message carries an HTML part *and* a text part that says the same
 * things. The constructor refuses an empty text part rather than letting a
 * template author discover the rule in review — a text part reading "see the
 * HTML version" is the failure this is meant to prevent, and no type can catch
 * that one, but the empty case is by far the common one.
 *
 * The class holds no rendering logic. Content lives in the templates (#404),
 * and nothing is stored: the drain renders from settlement data at send time
 * (ADR-0038 rule 5).
 */
final readonly class MailMessage
{
    /**
     * @param array<string,string> $embeddedImages CID => absolute file path
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $html,
        public string $text,
        public ?string $toName = null,
        public array $embeddedImages = [],
    ) {
        if (trim($to) === '') {
            throw new \InvalidArgumentException('MailMessage needs a recipient address');
        }
        if (trim($subject) === '') {
            throw new \InvalidArgumentException('MailMessage needs a subject');
        }
        if (trim($html) === '') {
            throw new \InvalidArgumentException('MailMessage needs an HTML part');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException(
                'MailMessage needs a text part — multipart is mandatory, and the text part must carry the same statements as the HTML part'
            );
        }
    }
}
