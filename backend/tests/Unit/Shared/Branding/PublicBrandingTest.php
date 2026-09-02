<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Branding;

use App\Shared\Branding\PublicBranding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What may become an `img src` on a page an anonymous phone opens.
 *
 * `mail_config.logo_url` is admin-written and read back onto a page served from
 * this origin, so the narrowing is not a formality: a value can also arrive by
 * a restore or a direct UPDATE, neither of which passed the panel's validation.
 */
final class PublicBrandingTest extends TestCase
{
    #[DataProvider('displayableUrls')]
    public function test_a_fetchable_logo_is_passed_through(string $value): void
    {
        self::assertSame($value, PublicBranding::displayableLogo($value));
    }

    /** @return array<string, array{string}> */
    public static function displayableUrls(): array
    {
        return [
            'https' => ['https://club.example/logo.png'],
            'http' => ['http://club.example/logo.png'],
            'uppercase scheme' => ['HTTPS://club.example/logo.png'],
            'same-origin path' => ['/assets/club-logo.png'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_anything_else_answers_null(?string $value): void
    {
        self::assertNull(PublicBranding::displayableLogo($value));
    }

    /** @return array<string, array{string|null}> */
    public static function refusedUrls(): array
    {
        return [
            'unset' => [null],
            'blank' => ['   '],
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='],
            // An absolute URL wearing a path's clothes: `//evil.example/x.png`
            // resolves against the page's scheme and is not same-origin at all.
            'protocol-relative' => ['//evil.example/logo.png'],
            'a cid reference, which only a mail client can resolve' => ['cid:club-logo'],
        ];
    }

    public function test_an_unbranded_club_says_so_rather_than_inventing_a_name(): void
    {
        $branding = new PublicBranding();

        self::assertSame(['club_name' => null, 'logo_url' => null], $branding->toArray());
    }

    public function test_the_wire_shape_is_the_two_keys_the_page_reads(): void
    {
        $branding = new PublicBranding('Ruderclub Musterstadt', 'https://club.example/logo.png');

        self::assertSame([
            'club_name' => 'Ruderclub Musterstadt',
            'logo_url' => 'https://club.example/logo.png',
        ], $branding->toArray());
    }
}
