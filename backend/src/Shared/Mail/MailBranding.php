<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * Everything the layout needs that is specific to the deploying club.
 *
 * The template this is ported from belongs to one club and says so in
 * constants — club name, postal address, website, the paths its webfonts and
 * its coat of arms are served from. Club Bar is deployed by more than one club
 * (ADR-0034), so all of it arrives here as data, from `mail_config` and the
 * instance branding.
 *
 * `baseUrl` is the only field with a second job: it is also where the webfonts
 * are fetched from. Where no site serves them, the fallback stacks carry the
 * look (a serif for headings, a grotesque for body text) and nothing breaks.
 */
final readonly class MailBranding
{
    /**
     * @param string      $orgName      Legal club name for the footer
     * @param string|null $addressLine  Postal address, one line
     * @param string|null $baseUrl      Public site URL; also the webfont origin
     * @param string|null $logoSrc      URL or `cid:` reference; null renders the wordmark alone
     * @param string      $headerStyle  One of MailLayout::HEADER_*
     * @param array<string,string> $footerLinks Label => URL
     */
    public function __construct(
        public string $orgName,
        public ?string $addressLine = null,
        public ?string $baseUrl = null,
        public ?string $logoSrc = null,
        public string $headerStyle = MailLayout::HEADER_PAPER,
        public array $footerLinks = [],
        public ?string $wordmark = null,
        public ?string $wordmarkSub = null,
    ) {}

    /** The wordmark falls back to the club name when none is set separately. */
    public function wordmarkText(): string
    {
        return $this->wordmark ?? $this->orgName;
    }
}
