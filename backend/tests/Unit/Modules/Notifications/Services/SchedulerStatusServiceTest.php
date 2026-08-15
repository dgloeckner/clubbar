<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\SchedulerStatusService;
use App\Shared\Config\AppConfig;
use App\Shared\Exceptions\SchedulerNotVerifiedException;
use PHPUnit\Framework\TestCase;

/**
 * The gate's own behaviour (#405): what it answers, what it refuses, and what
 * it tells the admin to do about it.
 */
class SchedulerStatusServiceTest extends TestCase
{
    private ?string $originalCronSecret = null;

    private function service(
        ?array $row,
        ?string $cronSecret = null,
        CronInterval $declared = CronInterval::HOURLY,
    ): SchedulerStatusService {
        $heartbeat = $this->createMock(CronHeartbeatRepository::class);
        $heartbeat->method('get')->willReturn($row);
        $heartbeat->method('hasEverRun')->willReturn($row !== null && ($row['last_run_at'] ?? null) !== null);

        // AppConfig reads the environment; CRON_SECRET decides whether the URL
        // trigger is mounted, and therefore whether it may be offered at all.
        // The empty string rather than `unset()`: `Env::get()` consults `$_ENV`
        // ahead of `getenv()`, and the dev container exports a secret of its
        // own — unsetting the key here would let the container's value decide
        // what this test observes.
        $_ENV['CRON_SECRET'] = $cronSecret ?? '';

        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('getConfig')->willReturn(self::config($declared));
        // A mock overrides every method, concrete ones included — so
        // cronSecretConfigured() needs its own stub rather than inheriting
        // real MailConfigService's derivation from getConfig() (#473).
        $mailConfig->method('cronSecretConfigured')->willReturn($cronSecret !== null);

        return new SchedulerStatusService($heartbeat, new AppConfig(), $mailConfig);
    }

    /** The declared interval is the only field these tests care about. */
    private static function config(CronInterval $interval): MailConfigDto
    {
        return new MailConfigDto(
            senderName: 'Club',
            senderAddress: 'bar@example.org',
            replyToAddress: null,
            headerStyle: 'paper',
            footerOrgName: 'Club',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
            cronInterval: $interval,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCronSecret = $_ENV['CRON_SECRET'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalCronSecret === null) {
            unset($_ENV['CRON_SECRET']);
        } else {
            $_ENV['CRON_SECRET'] = $this->originalCronSecret;
        }
        parent::tearDown();
    }

    public function test_an_installation_with_no_recorded_run_is_not_verified(): void
    {
        $service = $this->service(['last_run_at' => null, 'source' => null]);

        $this->assertFalse($service->isVerified());
        $this->assertFalse($service->status()->verified);
    }

    /**
     * A row that is absent entirely — a partial restore, or one somebody
     * deleted — reads the same way as an unstamped one. Both mean "no run has
     * been seen", and neither may be mistaken for a healthy installation.
     */
    public function test_a_missing_heartbeat_row_is_not_verified(): void
    {
        $this->assertFalse($this->service(null)->isVerified());
    }

    public function test_a_recorded_run_verifies_the_installation(): void
    {
        $service = $this->service([
            'last_run_at' => '2026-08-14 09:15:00',
            'source' => 'cli',
            'sent' => 3,
            'failed' => 0,
            'php_version' => '8.3.33',
            'missing_extensions' => '',
        ]);

        $status = $service->status();

        $this->assertTrue($status->verified);
        $this->assertSame('2026-08-14 09:15:00', $status->lastRunAt);
        $this->assertSame('cli', $status->source);
        $this->assertSame(3, $status->lastSent);
        $this->assertSame('8.3.33', $status->phpVersion);
        $this->assertSame([], $status->missingExtensions);
    }

    /**
     * The gate keys on *ever*, not *recently*. A scheduler that died months ago
     * still lets the treasurer collect: refusing the collection at the moment
     * it is needed is the worse failure, and a stale heartbeat is #406's alarm.
     */
    public function test_a_stale_heartbeat_still_counts_as_verified(): void
    {
        $service = $this->service(['last_run_at' => '2024-01-01 00:00:00', 'source' => 'cli']);

        $this->assertTrue($service->isVerified());
        $service->assertVerified();
    }

    public function test_assertVerified_refuses_with_the_cause_and_the_remedy(): void
    {
        $service = $this->service(['last_run_at' => null]);

        try {
            $service->assertVerified();
            $this->fail('Expected SchedulerNotVerifiedException');
        } catch (SchedulerNotVerifiedException $e) {
            $this->assertSame('scheduler_not_verified', $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatusCode());
            $this->assertStringContainsString('seven days', $e->getMessage(), 'the cause');
            $this->assertStringContainsString($service->cliCommand(), $e->getMessage(), 'the remedy');
            $this->assertStringContainsString(
                (string) SchedulerStatusService::RECOMMENDED_INTERVAL_MINUTES,
                $e->getMessage(),
                'and how often to run it',
            );
        }
    }

    /** An absolute path, because a hosting panel's cron form has no working directory. */
    public function test_the_cli_command_names_the_entrypoint_absolutely(): void
    {
        $command = $this->service(null)->cliCommand();

        $this->assertStringStartsWith('php /', $command);
        $this->assertStringEndsWith('/backend/bin/cron.php', $command);
    }

    /**
     * Without `cron.secret` the route answers 404, so offering the URL would be
     * instructions for something that is not mounted.
     */
    public function test_no_url_is_offered_when_the_trigger_is_switched_off(): void
    {
        $this->assertNull($this->service(null)->drainUrl());
    }

    public function test_the_url_is_offered_when_the_trigger_is_configured(): void
    {
        $url = $this->service(null, 'a-secret')->drainUrl();

        $this->assertNotNull($url);
        $this->assertStringEndsWith('/api/cron/drain', $url);
        $this->assertStringNotContainsString('a-secret', $url, 'the secret is a credential, not part of the address');
    }

    /**
     * `null` and `''` are different statements: a run that predates migration
     * 027 never checked, one that recorded an empty string checked and found
     * nothing missing. Collapsing them would turn "unknown" into "fine".
     */
    public function test_missing_extensions_distinguishes_unknown_from_none(): void
    {
        $this->assertNull(
            $this->service(['last_run_at' => '2026-01-01 00:00:00', 'missing_extensions' => null])->status()->missingExtensions
        );
        $this->assertSame(
            [],
            $this->service(['last_run_at' => '2026-01-01 00:00:00', 'missing_extensions' => ''])->status()->missingExtensions
        );
        $this->assertSame(
            ['openssl', 'intl'],
            $this->service(['last_run_at' => '2026-01-01 00:00:00', 'missing_extensions' => 'openssl, intl'])->status()->missingExtensions
        );
    }

    /* ────────────── The declared interval, and what is observed ────────────── */

    /**
     * Both halves are needed (ADR-0039 decision 5). The declaration is what the
     * first run has to schedule retries from, before anything has been
     * measured; the observation is the only thing that catches a crontab that
     * says hourly and fires daily.
     */
    public function test_two_runs_an_hour_apart_agree_with_an_hourly_declaration(): void
    {
        $status = $this->service([
            'previous_run_at' => '2026-08-15 10:00:00',
            'last_run_at' => '2026-08-15 11:00:00',
        ])->status();

        $this->assertSame('hourly', $status->declaredInterval);
        $this->assertSame('hourly', $status->observedInterval);
        $this->assertFalse($status->intervalDisagrees);
    }

    public function test_declared_hourly_while_runs_arrive_daily_is_surfaced_not_a_green_light(): void
    {
        $status = $this->service([
            'previous_run_at' => '2026-08-14 11:00:00',
            'last_run_at' => '2026-08-15 11:00:00',
        ])->status();

        $this->assertSame('hourly', $status->declaredInterval);
        $this->assertSame('daily', $status->observedInterval);
        $this->assertTrue($status->intervalDisagrees);
    }

    /**
     * Reported, never acted on: a declaration that turns out to be wrong is a
     * fact about the host somebody has to go and fix in a hosting panel, and
     * silently re-deriving it would hide exactly that.
     */
    public function test_the_declaration_is_not_overridden_by_what_is_observed(): void
    {
        $status = $this->service([
            'previous_run_at' => '2026-08-14 11:00:00',
            'last_run_at' => '2026-08-15 11:00:00',
        ])->status();

        $this->assertSame('hourly', $status->declaredInterval, 'the declaration stands until an admin changes it');
    }

    public function test_a_first_run_has_nothing_to_compare_against(): void
    {
        $status = $this->service(['previous_run_at' => null, 'last_run_at' => '2026-08-15 11:00:00'])->status();

        $this->assertNull($status->observedInterval);
        $this->assertFalse($status->intervalDisagrees);
    }

    /**
     * A daily installation whose cron fires hourly is not a problem: every
     * threshold downstream becomes conservative, so the alarm is late rather
     * than wrong.
     */
    public function test_a_scheduler_faster_than_declared_is_not_a_disagreement(): void
    {
        $status = $this->service(
            ['previous_run_at' => '2026-08-15 10:00:00', 'last_run_at' => '2026-08-15 11:00:00'],
            declared: CronInterval::DAILY,
        )->status();

        $this->assertSame('daily', $status->declaredInterval);
        $this->assertSame('hourly', $status->observedInterval);
        $this->assertFalse($status->intervalDisagrees);
    }

    public function test_the_status_serialises_the_setup_instructions_for_the_banner(): void
    {
        $payload = $this->service(['last_run_at' => null], 'a-secret')->status()->toArray();

        $this->assertFalse($payload['verified']);
        $this->assertNull($payload['last_run_at']);
        $this->assertStringEndsWith('/backend/bin/cron.php', $payload['setup']['cli_command']);
        $this->assertStringEndsWith('/api/cron/drain', $payload['setup']['drain_url']);
        $this->assertSame('hourly', $payload['declared_interval']);
        $this->assertNull($payload['observed_interval']);
        $this->assertFalse($payload['interval_disagrees']);
        $this->assertSame(
            SchedulerStatusService::RECOMMENDED_INTERVAL_MINUTES,
            $payload['setup']['recommended_interval_minutes'],
        );
    }
}
