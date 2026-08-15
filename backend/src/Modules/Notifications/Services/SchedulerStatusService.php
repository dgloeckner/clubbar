<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\SchedulerStatusDto;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Shared\Config\AppConfig;
use App\Shared\Exceptions\SchedulerNotVerifiedException;

/**
 * The scheduler is a prerequisite, not a fallback layer (#405).
 *
 * There is exactly one sending path — the drain (#403) — and an installation
 * without a working scheduler cannot keep Nutzungsordnung § 7 Abs. 3's promise
 * that every collection is announced seven days ahead. So it must not quietly
 * operate as if it could. This class is the one place that decides whether it
 * may, and the one place that says what to do about it.
 *
 * ### Why "ever", not "recently"
 *
 * The gate keys on `last_run_at IS NOT NULL` and never re-closes. A scheduler
 * that worked in March and died in June leaves a stale heartbeat, and a stale
 * heartbeat still lets the treasurer collect: refusing a collection at the
 * moment it is needed is a worse failure than announcing late, and the member's
 * eight-week no-questions refund stands either way. Ongoing health is #406's
 * alarm. Worth revisiting if it turns out schedulers die more often than
 * expected.
 *
 * ### Why the instructions live here
 *
 * Three surfaces ask the same question — the installer's prerequisite step, the
 * admin banner, and the refusal a blocked finalize returns — and all three have
 * to print the same command for *this* installation. Assembled separately, two
 * of them eventually name a path that does not exist on this host.
 */
class SchedulerStatusService
{
    /**
     * What the setup instructions recommend, in minutes.
     *
     * The lower bound of ADR-0038's 5–15 range is not always available: the
     * minimum interval a hosting panel offers is tariff-dependent, and fifteen
     * minutes is the one every tariff in the supported set can do. Nothing in
     * the announcement path is sensitive to it — the seven-day distance is
     * guaranteed at finalize, so the drain has a week of slack.
     */
    public const RECOMMENDED_INTERVAL_MINUTES = 15;

    /** Where the drain's CLI entrypoint sits, relative to the document root. */
    public const CLI_ENTRYPOINT = 'backend/bin/cron.php';

    /** The URL trigger's path, mounted only when `cron.secret` is configured. */
    public const DRAIN_PATH = '/api/cron/drain';

    public function __construct(
        private CronHeartbeatRepository $cronHeartbeatRepository,
        private AppConfig $config,
        private MailConfigService $mailConfigService,
    ) {}

    /** Has a drain run ever been observed on this installation? */
    public function isVerified(): bool
    {
        return $this->cronHeartbeatRepository->hasEverRun();
    }

    /**
     * Refuse the operation that would make a promise this installation cannot
     * keep.
     *
     * The message names the cause and the remedy in full, because it is read
     * by whoever pressed the button — the treasurer, who is also the person who
     * can go and schedule the cron.
     *
     * @throws SchedulerNotVerifiedException
     */
    public function assertVerified(): void
    {
        if ($this->isVerified()) {
            return;
        }

        throw new SchedulerNotVerifiedException(sprintf(
            'Announcement emails cannot be sent yet: no scheduled run of the mail drain has ever been '
            . 'observed on this installation. Every collection has to be announced by email at least seven '
            . 'days in advance, and the scheduler is what sends those announcements — so this settlement is '
            . 'refused rather than collected unannounced. Schedule "%s" to run every %d minutes in your '
            . 'hosting panel, then finalize again; the block lifts as soon as the first run has been recorded.',
            $this->cliCommand(),
            self::RECOMMENDED_INTERVAL_MINUTES,
        ));
    }

    public function status(): SchedulerStatusDto
    {
        $row = $this->cronHeartbeatRepository->get();
        $lastRunAt = $row['last_run_at'] ?? null;

        // Declared by the admin, observed from the gap between the last two
        // runs. Both halves are needed: the first run has to schedule a
        // sensible retry before anything has been measured, and only
        // observation catches a crontab that says hourly and fires daily
        // (ADR-0039 decision 5).
        $declared = $this->mailConfigService->getConfig()->cronInterval;
        $observed = QueueHealth::observedInterval(
            self::toDateTime($row['previous_run_at'] ?? null),
            self::toDateTime($lastRunAt),
        );

        return new SchedulerStatusDto(
            verified: $lastRunAt !== null,
            lastRunAt: $lastRunAt === null ? null : (string) $lastRunAt,
            source: isset($row['source']) && $row['source'] !== null ? (string) $row['source'] : null,
            lastSent: isset($row['sent']) ? (int) $row['sent'] : null,
            lastFailed: isset($row['failed']) ? (int) $row['failed'] : null,
            phpVersion: isset($row['php_version']) && $row['php_version'] !== null ? (string) $row['php_version'] : null,
            missingExtensions: self::parseMissingExtensions($row['missing_extensions'] ?? null),
            cliCommand: $this->cliCommand(),
            drainUrl: $this->drainUrl(),
            recommendedIntervalMinutes: self::RECOMMENDED_INTERVAL_MINUTES,
            declaredInterval: $declared->value,
            observedInterval: $observed?->value,
            intervalDisagrees: QueueHealth::intervalDisagrees($declared, $observed),
        );
    }

    /** A DATETIME as the database hands it back, or null. */
    private static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            // A column that cannot be parsed is the same as one that is not
            // there: nothing to compare, and nothing worth failing a status
            // endpoint over.
            return null;
        }
    }

    /**
     * The line to paste into a panel's cron form.
     *
     * `php` unqualified rather than a guessed `/usr/bin/php`: the CLI binary on
     * shared hosting is routinely a versioned name in a directory this process
     * cannot see, and most panels resolve `php` themselves. Naming the wrong
     * absolute path is worse than naming none — it fails with "not found" and
     * looks like the application's fault.
     */
    public function cliCommand(): string
    {
        return 'php ' . rtrim($this->config->documentRoot, '/') . '/' . self::CLI_ENTRYPOINT;
    }

    /**
     * The URL trigger, or null when it is switched off.
     *
     * No secret at all — panel-rotated or `config.php`'s (#473) — and the
     * route answers 404, so printing the URL would be instructions for
     * something that is not there. The CLI command is always offered; the URL
     * is the fallback for tariffs with no CLI cron.
     */
    public function drainUrl(): ?string
    {
        if (!$this->mailConfigService->cronSecretConfigured()) {
            return null;
        }

        return rtrim($this->config->appUrl, '/') . self::DRAIN_PATH;
    }

    /**
     * `null` predates migration 027, `''` means checked and complete. The two
     * are different statements and the DTO keeps them apart — an empty list is
     * evidence, an absent one is silence.
     *
     * @return list<string>|null
     */
    private static function parseMissingExtensions(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $raw)), static fn (string $p): bool => $p !== '');

        return array_values($parts);
    }
}
