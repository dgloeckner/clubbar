<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\DTOs;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Shared\Mail\MailLayout;
use PHPUnit\Framework\TestCase;

class MailConfigDtoTest extends TestCase
{
    public function test_reads_a_full_row(): void
    {
        $dto = MailConfigDto::fromRow([
            'sender_name' => 'Ruderverein Beispiel',
            'sender_address' => 'bar@example.org',
            'reply_to_address' => 'kassenwart@example.org',
            'header_style' => MailLayout::HEADER_PAPER,
            'footer_org_name' => 'Ruderverein Beispiel e. V.',
            'footer_address_line' => 'Musterweg 35',
            'website_url' => 'https://www.example.org',
            'logo_url' => 'https://www.example.org/logo.png',
        ]);

        $this->assertTrue($dto->isComplete());
        $this->assertSame('kassenwart@example.org', $dto->toSender()->replyTo);
        $this->assertSame('Ruderverein Beispiel', $dto->toSender()->name);
    }

    public function test_a_fresh_install_is_incomplete_rather_than_broken(): void
    {
        // The migration seeds an empty row on purpose: "never configured" has
        // to be a state the self-check can report, not a plausible default that
        // sends from an address nobody owns.
        $dto = MailConfigDto::fromRow([]);

        $this->assertFalse($dto->isComplete());
        $this->assertSame(MailLayout::DEFAULT_HEADER_STYLE, $dto->headerStyle);
    }

    public function test_blank_optional_columns_read_as_null(): void
    {
        $dto = MailConfigDto::fromRow([
            'sender_address' => 'bar@example.org',
            'reply_to_address' => '',
            'footer_address_line' => '   ',
            'website_url' => null,
        ]);

        $this->assertNull($dto->replyToAddress);
        $this->assertNull($dto->footerAddressLine);
        $this->assertNull($dto->websiteUrl);
    }

    public function test_sender_name_falls_back_to_the_club_name(): void
    {
        $dto = MailConfigDto::fromRow([
            'sender_address' => 'bar@example.org',
            'sender_name' => '',
            'footer_org_name' => 'Ruderverein Beispiel e. V.',
        ]);

        $this->assertSame('Ruderverein Beispiel e. V.', $dto->toSender()->name);
    }

    public function test_branding_carries_the_club_identity_into_the_layout(): void
    {
        $dto = MailConfigDto::fromRow([
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'Kanuverein Musterstadt e. V.',
            'website_url' => 'https://www.example.org',
            'header_style' => MailLayout::HEADER_PETROL,
        ]);

        $branding = $dto->toBranding(['Kontakt' => 'https://www.example.org/kontakt']);

        $this->assertSame('Kanuverein Musterstadt e. V.', $branding->orgName);
        $this->assertSame('Kanuverein Musterstadt e. V.', $branding->wordmarkText());
        $this->assertSame(MailLayout::HEADER_PETROL, $branding->headerStyle);
        $this->assertSame(['Kontakt' => 'https://www.example.org/kontakt'], $branding->footerLinks);
    }

    public function test_exposes_completeness_to_the_api(): void
    {
        $payload = MailConfigDto::fromRow(['sender_address' => 'bar@example.org'])->toArray();

        $this->assertArrayHasKey('is_complete', $payload);
        $this->assertTrue($payload['is_complete']);
        // The DSN is never part of this resource — it is a secret in config.php.
        $this->assertArrayNotHasKey('dsn', $payload);
    }
}
