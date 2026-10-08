<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Notifications\DTOs\BackupHealthScanResultDto;
use App\Modules\Notifications\DTOs\CredentialExpiryScanResultDto;
use App\Modules\Notifications\DTOs\CreditLimitDigestScanResultDto;
use App\Modules\Notifications\DTOs\PeriodicEnqueueResultDto;
use App\Modules\Notifications\DTOs\PreDrainStepDto;
use App\Modules\Notifications\Services\BackupHealthNotifier;
use App\Modules\Notifications\Services\CredentialExpiryNotifier;
use App\Modules\Notifications\Services\CreditLimitDigestNotifier;
use App\Modules\Notifications\Services\PeriodicEnqueueService;
use App\Modules\Notifications\Services\PreDrainTasks;
use App\Modules\Registrations\Repositories\RegistrationAttemptsRepository;
use App\Modules\Registrations\Services\RegistrationsService;
use App\Modules\Terminals\DTOs\AnomalyScanResultDto;
use App\Modules\Terminals\Services\TerminalAnomalyDetector;
use App\Shared\Logging\Logger;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The one list of pre-drain steps both scheduler triggers run (#975).
 *
 * The URL trigger used to run only the anomaly scan, so the property worth
 * pinning is that *every* step runs, in the order `bin/cron.php` always had,
 * and that one step failing neither stops the rest nor stays silent.
 */
class PreDrainTasksTest extends TestCase
{
    private LoginAttemptsRepository&MockObject $loginAttempts;
    private LoginAttemptsRepository&MockObject $terminalAuthAttempts;
    private RegistrationsService&MockObject $registrations;
    private RegistrationAttemptsRepository&MockObject $registrationAttempts;
    private TerminalAnomalyDetector&MockObject $anomalyDetector;
    private PeriodicEnqueueService&MockObject $periodicEnqueue;
    private CreditLimitDigestNotifier&MockObject $creditLimitDigest;
    private CredentialExpiryNotifier&MockObject $credentialExpiry;
    private BackupHealthNotifier&MockObject $backupHealth;
    private Logger&MockObject $logger;

    protected function setUp(): void
    {
        $this->loginAttempts = $this->createMock(LoginAttemptsRepository::class);
        $this->terminalAuthAttempts = $this->createMock(LoginAttemptsRepository::class);
        $this->registrations = $this->createMock(RegistrationsService::class);
        $this->registrationAttempts = $this->createMock(RegistrationAttemptsRepository::class);
        $this->anomalyDetector = $this->createMock(TerminalAnomalyDetector::class);
        $this->periodicEnqueue = $this->createMock(PeriodicEnqueueService::class);
        $this->creditLimitDigest = $this->createMock(CreditLimitDigestNotifier::class);
        $this->credentialExpiry = $this->createMock(CredentialExpiryNotifier::class);
        $this->backupHealth = $this->createMock(BackupHealthNotifier::class);
        $this->logger = $this->createMock(Logger::class);

        $this->anomalyDetector->method('run')->willReturn(new AnomalyScanResultDto());
        $this->periodicEnqueue->method('run')->willReturn(new PeriodicEnqueueResultDto(period: '2026-10', queued: 3));
        $this->creditLimitDigest->method('run')->willReturn(new CreditLimitDigestScanResultDto());
        $this->credentialExpiry->method('run')->willReturn(new CredentialExpiryScanResultDto());
        $this->backupHealth->method('run')->willReturn(new BackupHealthScanResultDto());
    }

    private function tasks(): PreDrainTasks
    {
        return new PreDrainTasks(
            $this->loginAttempts,
            $this->terminalAuthAttempts,
            $this->registrations,
            $this->registrationAttempts,
            $this->anomalyDetector,
            $this->periodicEnqueue,
            $this->creditLimitDigest,
            $this->credentialExpiry,
            $this->backupHealth,
            $this->logger,
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-08 05:00:00', new DateTimeZone('UTC'));
    }

    public function test_every_step_runs_in_the_order_cron_php_always_had(): void
    {
        $steps = $this->tasks()->run($this->now());

        $this->assertSame(
            [
                'auth attempt pruning',
                'registration purge',
                'terminal anomaly scan',
                'statement enqueue',
                'credit limit digest scan',
                'credential expiry scan',
                'backup health scan',
            ],
            array_map(static fn (PreDrainStepDto $s): string => $s->step, $steps),
        );
        $this->assertSame([], array_filter($steps, static fn (PreDrainStepDto $s): bool => $s->failed()));
    }

    /**
     * The step #975 is about: the statement enqueue is given the tick's own
     * instant and the period an operator named, and its summary is reported.
     */
    public function test_the_statement_enqueue_receives_the_instant_and_the_named_period(): void
    {
        $now = $this->now();
        $this->periodicEnqueue = $this->createMock(PeriodicEnqueueService::class);
        $this->periodicEnqueue->expects($this->once())
            ->method('run')
            ->with($now, '2026-10')
            ->willReturn(new PeriodicEnqueueResultDto(period: '2026-10', queued: 3));

        $steps = $this->tasks()->run($now, '2026-10');

        $this->assertSame(['Deckel statements: period=2026-10 queued=3 already_queued=0 skipped=0'], $steps[3]->lines);
    }

    public function test_a_failing_step_is_logged_and_the_others_still_run(): void
    {
        $this->periodicEnqueue = $this->createMock(PeriodicEnqueueService::class);
        $this->periodicEnqueue->method('run')->willThrowException(new \RuntimeException('table gone'));

        $this->backupHealth = $this->createMock(BackupHealthNotifier::class);
        $this->backupHealth->expects($this->once())->method('run')->willReturn(new BackupHealthScanResultDto());

        // Not only on stderr, which most hosting panels discard: a failing scan
        // has to reach the application log, or it is as silent as a missing one.
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Scheduler step failed', $this->callback(
                static fn (array $ctx): bool => $ctx['step'] === 'statement enqueue' && $ctx['message'] === 'table gone'
            ));

        $steps = $this->tasks()->run($this->now());

        $this->assertCount(7, $steps);
        $this->assertSame('table gone', $steps[3]->error);
        $this->assertSame([], $steps[3]->lines);
    }

    public function test_quiet_steps_say_nothing_and_busy_ones_raise_an_alert(): void
    {
        $this->credentialExpiry = $this->createMock(CredentialExpiryNotifier::class);
        $this->credentialExpiry->method('run')->willReturn(new CredentialExpiryScanResultDto(queued: 1));
        $this->registrations->method('purgeExpired')->willReturn(2);

        $steps = $this->tasks()->run($this->now());

        $this->assertSame([], $steps[0]->lines, 'nothing pruned, nothing to print');
        $this->assertSame(['Purged 2 expired registration(s).'], $steps[1]->lines);
        $this->assertNull($steps[2]->alert, 'no anomaly opened');
        $this->assertStringStartsWith('Credential expiry warnings queued: ', (string) $steps[5]->alert);
    }

    public function test_attempt_rows_are_pruned_a_day_behind_the_tick(): void
    {
        $this->loginAttempts->expects($this->once())->method('pruneOlderThan')->with('2026-10-07 05:00:00');
        $this->terminalAuthAttempts->expects($this->once())->method('pruneOlderThan')->with('2026-10-07 05:00:00');
        $this->registrationAttempts->expects($this->once())->method('pruneOlderThan')->with('2026-10-07 05:00:00');

        $this->tasks()->run($this->now());
    }
}
