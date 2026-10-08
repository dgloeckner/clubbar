<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Notifications\DTOs\PreDrainStepDto;
use App\Modules\Registrations\Repositories\RegistrationAttemptsRepository;
use App\Modules\Registrations\Services\RegistrationsService;
use App\Modules\Terminals\Services\TerminalAnomalyDetector;
use App\Shared\Logging\Logger;
use DateTimeImmutable;

/**
 * Everything a scheduler tick does before it drains the outbox (#975).
 *
 * There are two triggers — `bin/cron.php` and the URL route
 * ({@see \App\Modules\Notifications\Controllers\CronController}) — and
 * ADR-0038 rule 3 promises they behave the same. For the drain they always
 * did, because both call {@see DrainService}. Everything *before* the drain
 * lived in `cron.php` alone, and the URL trigger ran only the anomaly scan:
 * an installation scheduled by URL never queued a Deckelauszug, a credit limit
 * digest, a credential or backup warning, and never purged an expired
 * registration. Nothing said so — the drain ran, the heartbeat stayed green,
 * and there was simply no mail to send. This class is the one list both
 * triggers run, so a step added later cannot reach only one of them.
 *
 * Order matters in one direction only: every step that queues mail runs before
 * the drain, so a warning raised by this tick leaves on this tick. Among the
 * steps the order is the one `cron.php` always had.
 *
 * Each step is isolated. Whatever is wrong with one scan, the others still run
 * and the club's announcements still go out, so a step that throws is caught
 * here, reported in its result — and written to the application log. That last
 * part is new: `cron.php` used to put a failing step on stderr only, which most
 * hosting panels discard, so a broken scan was as silent as a missing one.
 */
class PreDrainTasks
{
    /** How long rate-limit attempt rows are kept — outlives every lock window in use (15 minutes). */
    private const ATTEMPT_RETENTION_SECONDS = 86400;

    public function __construct(
        private LoginAttemptsRepository $loginAttempts,
        private LoginAttemptsRepository $terminalAuthAttempts,
        private RegistrationsService $registrations,
        private RegistrationAttemptsRepository $registrationAttempts,
        private TerminalAnomalyDetector $anomalyDetector,
        private PeriodicEnqueueService $periodicEnqueue,
        private CreditLimitDigestNotifier $creditLimitDigest,
        private CredentialExpiryNotifier $credentialExpiry,
        private BackupHealthNotifier $backupHealth,
        private Logger $logger,
    ) {}

    /**
     * Run every step, in order, and say what each did.
     *
     * @param string|null $statementPeriod `--period` from the command line; the
     *                                     URL trigger never names one.
     *
     * @return list<PreDrainStepDto>
     */
    public function run(DateTimeImmutable $now, ?string $statementPeriod = null): array
    {
        $attemptCutoff = date('Y-m-d H:i:s', $now->getTimestamp() - self::ATTEMPT_RETENTION_SECONDS);

        return [
            // Neither attempt table has any other path back to empty: a probed
            // account's rows and every scanner's bad bearer token would
            // otherwise stay forever.
            $this->step('auth attempt pruning', function () use ($attemptCutoff): PreDrainStepDto {
                $login = $this->loginAttempts->pruneOlderThan($attemptCutoff);
                $terminal = $this->terminalAuthAttempts->pruneOlderThan($attemptCutoff);

                return new PreDrainStepDto(
                    'auth attempt pruning',
                    $login > 0 || $terminal > 0 ? ["Pruned auth attempts: {$login} login, {$terminal} terminal."] : [],
                );
            }),

            // ADR-0052 decision 10. The line says how many went, never who they
            // were: a purge log naming the people it deleted would be a copy of
            // the data outliving the deletion.
            $this->step('registration purge', function () use ($attemptCutoff): PreDrainStepDto {
                $purged = $this->registrations->purgeExpired();
                $pruned = $this->registrationAttempts->pruneOlderThan($attemptCutoff);

                $lines = [];
                if ($purged > 0) {
                    $lines[] = "Purged {$purged} expired registration(s).";
                }
                if ($pruned > 0) {
                    $lines[] = "Pruned {$pruned} registration attempt(s).";
                }

                return new PreDrainStepDto('registration purge', $lines);
            }),

            // ADR-0041.
            $this->step('terminal anomaly scan', function () use ($now): PreDrainStepDto {
                $scan = $this->anomalyDetector->run($now->getTimestamp());

                return new PreDrainStepDto(
                    'terminal anomaly scan',
                    ['Terminal anomaly scan: ' . $scan->summary()],
                    $scan->opened > 0 ? 'Terminal anomalies detected: ' . $scan->summary() : null,
                );
            }),

            // ADR-0039 decision 1.
            $this->step('statement enqueue', function () use ($now, $statementPeriod): PreDrainStepDto {
                $result = $this->periodicEnqueue->run($now, $statementPeriod);

                return new PreDrainStepDto('statement enqueue', ['Deckel statements: ' . $result->summary()]);
            }),

            // ADR-0047.
            $this->step('credit limit digest scan', function () use ($now): PreDrainStepDto {
                $digest = $this->creditLimitDigest->run($now);

                return new PreDrainStepDto('credit limit digest scan', ['Credit limit digest: ' . $digest->summary()]);
            }),

            // ADR-0036 / #438.
            $this->step('credential expiry scan', function () use ($now): PreDrainStepDto {
                $expiry = $this->credentialExpiry->run($now);

                return new PreDrainStepDto(
                    'credential expiry scan',
                    ['Credential expiry scan: ' . $expiry->summary()],
                    $expiry->queued > 0 ? 'Credential expiry warnings queued: ' . $expiry->summary() : null,
                );
            }),

            // #693. On the mail tick rather than the backup cron, because a
            // notice sent by the backup job is silent when that job was never
            // scheduled — and this tick is the mandatory one (ADR-0038).
            $this->step('backup health scan', function () use ($now): PreDrainStepDto {
                $health = $this->backupHealth->run($now);

                return new PreDrainStepDto(
                    'backup health scan',
                    ['Backup health scan: ' . $health->summary()],
                    $health->queued > 0 ? 'Backup health warnings queued: ' . $health->summary() : null,
                );
            }),
        ];
    }

    /**
     * @param callable(): PreDrainStepDto $body
     */
    private function step(string $name, callable $body): PreDrainStepDto
    {
        try {
            return $body();
        } catch (\Throwable $e) {
            $this->logger->error('Scheduler step failed', [
                'step' => $name,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return new PreDrainStepDto($name, error: $e->getMessage());
        }
    }
}
