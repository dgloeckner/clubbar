<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Branding;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Shared\Branding\PublicBrandingProvider;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Reading the two rows a club has already filled in — and surviving their
 * absence, because branding decorates a page that must still work without it.
 */
final class PublicBrandingProviderTest extends TestCase
{
    public function test_it_reads_the_name_and_the_mark_the_mail_already_uses(): void
    {
        $branding = $this->provider('Ruderclub Musterstadt', ['logo_url' => 'https://club.example/logo.png'])->get();

        self::assertSame('Ruderclub Musterstadt', $branding->clubName);
        self::assertSame('https://club.example/logo.png', $branding->logoUrl);
    }

    public function test_a_club_with_no_logo_still_gets_its_name(): void
    {
        $branding = $this->provider('Ruderclub Musterstadt', ['logo_url' => null])->get();

        self::assertSame('Ruderclub Musterstadt', $branding->clubName);
        self::assertNull($branding->logoUrl);
    }

    /** The narrowing {@see \App\Shared\Branding\PublicBranding::displayableLogo()} does, applied here. */
    public function test_a_logo_no_browser_should_be_pointed_at_is_dropped(): void
    {
        $branding = $this->provider('Ruderclub Musterstadt', ['logo_url' => 'javascript:alert(1)'])->get();

        self::assertNull($branding->logoUrl);
    }

    /**
     * An installation that never opened the mail screen, or a restore caught
     * mid-flight. The applicant's form renders under the neutral header rather
     * than turning into a 500.
     */
    public function test_a_missing_mail_config_row_is_not_an_error(): void
    {
        $branding = $this->provider('Ruderclub Musterstadt', null)->get();

        self::assertSame('Ruderclub Musterstadt', $branding->clubName);
        self::assertNull($branding->logoUrl);
    }

    public function test_an_unreadable_mail_config_is_not_an_error_either(): void
    {
        $mailConfig = $this->createMock(MailConfigRepository::class);
        $mailConfig->method('getConfig')->willThrowException(new PDOException('no such table: mail_config'));

        $instance = $this->createMock(InstanceConfigService::class);
        $instance->method('getInstanceName')->willReturn('Ruderclub Musterstadt');

        $branding = (new PublicBrandingProvider($instance, $mailConfig))->get();

        self::assertSame('Ruderclub Musterstadt', $branding->clubName);
        self::assertNull($branding->logoUrl);
    }

    /**
     * A blank name is not a name. The page's own neutral fallback is better
     * than a masthead with an empty line where the club should be.
     */
    public function test_a_blank_instance_name_answers_null(): void
    {
        $branding = $this->provider('   ', [])->get();

        self::assertNull($branding->clubName);
    }

    /** @param array<string, mixed>|null $mailConfigRow */
    private function provider(string $instanceName, ?array $mailConfigRow): PublicBrandingProvider
    {
        $instance = $this->createMock(InstanceConfigService::class);
        $instance->method('getInstanceName')->willReturn($instanceName);

        $mailConfig = $this->createMock(MailConfigRepository::class);
        $mailConfig->method('getConfig')->willReturn($mailConfigRow);

        return new PublicBrandingProvider($instance, $mailConfig);
    }
}
