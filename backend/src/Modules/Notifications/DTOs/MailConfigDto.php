<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailSender;

final readonly class MailConfigDto
{
    public function __construct(
        public string $senderName,
        public string $senderAddress,
        public ?string $replyToAddress,
        public string $headerStyle,
        public string $footerOrgName,
        public ?string $footerAddressLine,
        public ?string $websiteUrl,
        public ?string $logoUrl,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            senderName: (string) ($row['sender_name'] ?? ''),
            senderAddress: (string) ($row['sender_address'] ?? ''),
            replyToAddress: self::nullIfBlank($row['reply_to_address'] ?? null),
            headerStyle: (string) ($row['header_style'] ?? MailLayout::DEFAULT_HEADER_STYLE),
            footerOrgName: (string) ($row['footer_org_name'] ?? ''),
            footerAddressLine: self::nullIfBlank($row['footer_address_line'] ?? null),
            websiteUrl: self::nullIfBlank($row['website_url'] ?? null),
            logoUrl: self::nullIfBlank($row['logo_url'] ?? null),
        );
    }

    /**
     * Can this configuration address an envelope at all?
     *
     * The sender address is the one field with no workable default: the
     * display name can fall back to the instance name and the footer to the
     * club name, but nothing can invent a mailbox. An install that never set
     * it is reported by the self-check rather than sending From: <>.
     */
    public function isComplete(): bool
    {
        return $this->senderAddress !== '';
    }

    public function toSender(): MailSender
    {
        return new MailSender(
            address: $this->senderAddress,
            name: $this->senderName !== '' ? $this->senderName : $this->footerOrgName,
            replyTo: $this->replyToAddress,
        );
    }

    /**
     * @param array<string,string> $footerLinks Label => URL, supplied by the caller
     *                                          because the labels are translated (#404)
     */
    public function toBranding(array $footerLinks = []): MailBranding
    {
        return new MailBranding(
            orgName: $this->footerOrgName,
            addressLine: $this->footerAddressLine,
            baseUrl: $this->websiteUrl,
            logoSrc: $this->logoUrl,
            headerStyle: $this->headerStyle,
            footerLinks: $footerLinks,
        );
    }

    public function toArray(): array
    {
        return [
            'sender_name' => $this->senderName,
            'sender_address' => $this->senderAddress,
            'reply_to_address' => $this->replyToAddress,
            'header_style' => $this->headerStyle,
            'footer_org_name' => $this->footerOrgName,
            'footer_address_line' => $this->footerAddressLine,
            'website_url' => $this->websiteUrl,
            'logo_url' => $this->logoUrl,
            'is_complete' => $this->isComplete(),
        ];
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);
        return $value === '' ? null : $value;
    }
}
