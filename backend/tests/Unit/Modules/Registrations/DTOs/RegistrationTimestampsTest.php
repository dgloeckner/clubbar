<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\DTOs;

use App\Modules\Registrations\DTOs\PendingRegistrationDto;
use App\Modules\Registrations\DTOs\SelfRegistrationSettingsDto;
use PHPUnit\Framework\TestCase;

/**
 * Self-registration's instants, as the review screen has to read them.
 *
 * The columns hold UTC (`Shared\Time\Utc`); a response that does not say so is
 * read by the browser as local time. On the review list that is not only a
 * clock being wrong: `submitted_at` is printed as a *date*, so a submission
 * made late in the evening is filed under the wrong day (#365).
 */
class RegistrationTimestampsTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private static function pending(array $overrides = []): array
    {
        return PendingRegistrationDto::fromRow(array_merge([
            'id' => '33333333-3333-4333-8333-333333333333',
            'first_name' => 'Lena',
            'last_name' => 'Brandt',
            'email' => 'lena@example.org',
            'phone' => null,
            'date_of_birth' => '1998-04-02',
            'preferred_language' => 'de',
            'account_holder_name' => null,
            'mandate_reference' => 'abc',
            'iban_last4' => '3000',
            'bank_name' => 'Sparkasse',
            'privacy_notice_url' => 'https://club.example/Anmeldung.pdf',
            'privacy_notice_shown_at' => '2026-08-31 22:15:00',
            'submitted_at' => '2026-08-31 22:15:04',
            'expires_at' => '2026-09-30 22:15:04',
        ], $overrides))->toArray();
    }

    public function test_a_pending_registration_labels_its_instants_utc(): void
    {
        $array = self::pending();

        $this->assertSame('2026-08-31T22:15:00Z', $array['privacy_notice_shown_at']);
        $this->assertSame('2026-08-31T22:15:04Z', $array['submitted_at']);
        $this->assertSame('2026-09-30T22:15:04Z', $array['expires_at']);
    }

    /** A calendar day is not an instant, and must not grow a zone. */
    public function test_the_date_of_birth_stays_a_calendar_day(): void
    {
        $this->assertSame('1998-04-02', self::pending()['date_of_birth']);
    }

    public function test_the_settings_payload_labels_the_rotation_instant_utc(): void
    {
        $settings = new SelfRegistrationSettingsDto(
            enabled: true,
            disabledReason: null,
            hasSecret: true,
            secretRotatedAt: '2026-03-14 08:00:00',
            documentUrl: 'https://club.example/Anmeldung.pdf',
            retentionDays: 30,
        );

        $this->assertSame('2026-03-14T08:00:00Z', $settings->toArray()['secret_rotated_at']);
    }

    /** No poster has been printed yet, and the payload says null rather than now. */
    public function test_a_never_rotated_secret_reports_null(): void
    {
        $settings = new SelfRegistrationSettingsDto(
            enabled: false,
            disabledReason: 'no_secret',
            hasSecret: false,
            secretRotatedAt: null,
            documentUrl: null,
            retentionDays: 30,
        );

        $this->assertNull($settings->toArray()['secret_rotated_at']);
    }
}
