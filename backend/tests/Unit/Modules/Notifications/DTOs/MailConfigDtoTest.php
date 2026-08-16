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

    /* ──────────────────── The two operational dials ──────────────────── */

    public function test_the_dials_read_back_what_the_row_declared(): void
    {
        $dto = MailConfigDto::fromRow([
            'sender_address' => 'bar@example.org',
            'drain_batch_size' => 40,
            'drain_budget_seconds' => 20,
        ]);

        $this->assertSame(40, $dto->drainBatchSize);
        $this->assertSame(20, $dto->drainBudgetSeconds);
        $this->assertSame(20, $dto->toArray()['drain_budget_seconds']);
    }

    /**
     * A row written before migration 040 has no budget column at all, and one
     * from a partial restore can read as 0. Both are the same situation: nothing
     * was declared. A zero-second budget would stop a run before it claimed
     * anything while looking perfectly configured — the one failure mode a
     * wall-clock dial must not have, exactly as for the batch size beside it.
     */
    public function test_a_missing_or_zero_budget_falls_back_rather_than_stopping_every_run(): void
    {
        foreach ([null, 0, '', '0'] as $value) {
            $dto = MailConfigDto::fromRow([
                'sender_address' => 'bar@example.org',
                'drain_budget_seconds' => $value,
            ]);

            $this->assertSame(
                MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS,
                $dto->drainBudgetSeconds,
                'an undeclared budget must fall back, not be honoured as zero'
            );
        }

        $this->assertSame(
            MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS,
            MailConfigDto::fromRow(['sender_address' => 'bar@example.org'])->drainBudgetSeconds
        );
    }

    /**
     * A budget above the ceiling is a run that gets *killed* rather than one
     * that stops — and a killed run leaves rows claimed for the stale window to
     * hand back, which is how one announcement is delivered twice. So a value
     * past the bound is brought back down rather than trusted (#473).
     */
    public function test_a_budget_past_the_ceiling_is_clamped_and_not_honoured(): void
    {
        $dto = MailConfigDto::fromRow([
            'sender_address' => 'bar@example.org',
            'drain_budget_seconds' => 3600,
        ]);

        $this->assertSame(MailConfigDto::MAX_DRAIN_BUDGET_SECONDS, $dto->drainBudgetSeconds);
    }

    /**
     * The default has to be reachable inside the tightest trigger timeout this
     * project knows of — an external HTTP scheduler's 30 seconds — and the
     * ceiling inside the most generous, IONOS's 60-second cron cap.
     */
    public function test_the_shipped_budget_bounds_fit_the_triggers_that_fire_the_drain(): void
    {
        $this->assertLessThan(30, MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS);
        $this->assertLessThan(60, MailConfigDto::MAX_DRAIN_BUDGET_SECONDS);
        $this->assertGreaterThanOrEqual(
            MailConfigDto::MIN_DRAIN_BUDGET_SECONDS,
            MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS
        );
    }

    /**
     * A fresh row (or one that predates migration 034) has no rotation on
     * record — the file fallback in config.php is what CronController falls
     * back to, and this is what tells the admin panel there is nothing to
     * show but a "not yet rotated" state (#473).
     */
    public function test_no_secret_rotation_reads_as_not_configured(): void
    {
        $dto = MailConfigDto::fromRow(['sender_address' => 'bar@example.org']);

        $this->assertNull($dto->cronSecretHash);
        $this->assertNull($dto->cronSecretRotatedAt);
        $payload = $dto->toArray();
        $this->assertFalse($payload['cron_secret_configured']);
        $this->assertNull($payload['cron_secret_rotated_at']);
    }

    /**
     * The hash is never part of the API payload — only whether one exists.
     * There is no operator value in a hash once past the comparison it exists
     * for, so there is no reason to widen a secret's exposure by returning it.
     */
    public function test_a_rotated_secret_reports_configured_but_never_the_hash(): void
    {
        $dto = MailConfigDto::fromRow([
            'sender_address' => 'bar@example.org',
            'cron_secret_hash' => hash('sha256', 'some-secret'),
            'cron_secret_rotated_at' => '2026-08-15 12:00:00',
        ]);

        $payload = $dto->toArray();
        $this->assertTrue($payload['cron_secret_configured']);
        $this->assertSame('2026-08-15 12:00:00', $payload['cron_secret_rotated_at']);
        $this->assertArrayNotHasKey('cron_secret_hash', $payload);
        $this->assertStringNotContainsString(hash('sha256', 'some-secret'), json_encode($payload));
    }
}
