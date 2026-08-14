<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\DrainResultDto;
use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Shared\Config\PhpRuntime;
use App\Shared\Logging\Logger;
use App\Shared\Process\FileLock;
use App\Shared\Mail\InvalidMailDsnException;
use App\Shared\Mail\MailSendResult;
use App\Shared\Mail\MailTransport;
use App\Shared\Mail\MailTransportFactory;

/**
 * The only thing in this system that sends mail (ADR-0038 rule 3).
 *
 * Claim → render → send → mark, in a loop, until the queue is empty or the run
 * is out of time. Two triggers reach it — `bin/cron.php` and the URL fallback
 * route — and it behaves identically for both; the source is recorded, never
 * branched on.
 *
 * ### Why the loop is bounded twice
 *
 * The batch size bounds how many rows are claimed at once, and the wall-clock
 * budget bounds the whole run. Both are needed and they bound different things:
 * a batch of twenty-five is a reasonable amount of work to hold a claim over,
 * while the budget exists because *the host decides how long a process may
 * live* and we do not get told the number. Under the URL trigger that is a
 * gateway read timeout — which, as ADR-0038 spells out, is not
 * `max_execution_time` and cannot be raised from code; under CLI it is however
 * long the panel is prepared to let a cron job run. Exceeding either is a run
 * killed mid-batch.
 *
 * A run that stops on its budget is not a failure and is not reported as one.
 * The rows it did not reach are released back to the queue and the next tick
 * takes them, which is exactly what a queue is for.
 *
 * ### Why an unsendable configuration claims nothing
 *
 * If there is no transport, or no sender address, the run stops before
 * claiming. Claiming would burn an attempt on every queued message, and three
 * ticks later the whole queue would be `failed` — with a `last_error` blaming
 * SMTP for what is a missing line in `config.php`. The queue must survive a
 * misconfiguration and drain once it is fixed.
 *
 * The heartbeat is still written in that case: the scheduler genuinely ran, and
 * the install gate (#405) asks whether it ever has, not whether it achieved
 * anything.
 */
class DrainService
{
    /**
     * Messages claimed per round.
     *
     * Twenty-five is a claim held for a few seconds on a slow SMTP server, and
     * a settlement for a club of this size is one or two rounds. Larger batches
     * do not go faster — the transport is serial either way — they only widen
     * the window in which a killed run leaves rows claimed.
     */
    public const DEFAULT_BATCH_SIZE = 25;

    /**
     * Wall-clock budget for one run, in seconds.
     *
     * Fifty, because the number that actually matters is the host's gateway
     * read timeout on the URL trigger, it is commonly sixty, and it is not
     * visible from here. Stopping ten seconds early with the queue intact beats
     * being killed mid-send.
     */
    public const DEFAULT_BUDGET_SECONDS = 50;

    /**
     * The `flock` file, in the data directory's `storage/`.
     *
     * Named here rather than at each caller because both triggers must agree on
     * it: a CLI cron and a URL cron locking two different files would give the
     * overlap the lock exists to prevent.
     */
    public const LOCK_FILENAME = 'mail-drain.lock';

    /** The one lock both triggers take. */
    public static function lockIn(string $storageDir): FileLock
    {
        return new FileLock(rtrim($storageDir, '/') . '/' . self::LOCK_FILENAME);
    }

    public function __construct(
        private NotificationsService $notificationsService,
        private SettlementMailBuilder $mailBuilder,
        private MailTransportFactory $mailTransportFactory,
        private MailConfigService $mailConfigService,
        private CronHeartbeatRepository $cronHeartbeatRepository,
        private Logger $logger,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
    ) {}

    /**
     * Drain what is due, then stamp the heartbeat.
     *
     * Never throws. A drain is triggered by a scheduler nobody is watching, and
     * an exception escaping into a crontab is a mail nobody reads; every
     * failure is recorded on the row it belongs to or in the log.
     *
     * @param int|null $batchSize     Overrides the configured batch size for this run
     * @param int|null $budgetSeconds Overrides the configured wall-clock budget
     */
    public function run(
        DrainSource $source,
        ?int $batchSize = null,
        ?int $budgetSeconds = null,
    ): DrainResultDto {
        $startedAt = microtime(true);
        $batchSize = max(1, $batchSize ?? $this->batchSize);
        $budgetSeconds = max(1, $budgetSeconds ?? $this->budgetSeconds);

        $transport = $this->resolveTransport();

        if ($transport === null) {
            // Nothing claimed, nothing burnt. The queue waits for the
            // configuration rather than being spent against it.
            $result = DrainResultDto::idle($source, microtime(true) - $startedAt);
            $this->recordHeartbeat($result);

            return $result;
        }

        $sender = $this->mailConfigService->getConfig()->toSender();

        $claimed = $sent = $retrying = $failed = $skipped = 0;
        $budgetExhausted = false;

        while (true) {
            if (microtime(true) - $startedAt >= $budgetSeconds) {
                $budgetExhausted = true;
                break;
            }

            $batch = $this->notificationsService->claimBatch($batchSize);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $index => $row) {
                if (microtime(true) - $startedAt >= $budgetSeconds) {
                    $budgetExhausted = true;
                    // Hand back what this run will not get to, so the rows are
                    // due again on the next tick rather than sitting claimed
                    // until the stale window expires.
                    $this->release(array_slice($batch, $index));
                    break 2;
                }

                $claimed++;

                $message = null;
                try {
                    $message = $this->mailBuilder->build($row);
                } catch (\Throwable $e) {
                    // The settlement behind the row is gone, or the kind has no
                    // content yet. Neither becomes true later, so this is
                    // permanent — and it is a data problem, not a mail one.
                    $skipped++;
                    $this->notificationsService->recordResult(
                        (string) $row['id'],
                        MailSendResult::permanentFailure('Cannot render message: ' . $e->getMessage()),
                    );
                    $this->logger->error('Drain could not render a queued message', [
                        'outbox_id' => $row['id'] ?? null,
                        'settlement_id' => $row['settlement_id'] ?? null,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }

                try {
                    $sendResult = $transport->send($message, $sender);
                } catch (\Throwable $e) {
                    // A transport is contractually not allowed to throw, but one
                    // that does must not take the rest of the batch with it —
                    // and "unknown" is a reason to try again, not to give up.
                    $sendResult = MailSendResult::transientFailure(
                        'Transport threw ' . get_class($e) . ': ' . $e->getMessage()
                    );
                }

                $status = $this->notificationsService->recordResult((string) $row['id'], $sendResult);

                match (true) {
                    $status === MailStatus::SENT => $sent++,
                    // Back to pending with a backoff: greylisting, and it is
                    // ordinary operation rather than an error.
                    $status === MailStatus::PENDING => $retrying++,
                    default => $failed++,
                };
            }
        }

        $result = new DrainResultDto(
            source: $source,
            claimed: $claimed,
            sent: $sent,
            retrying: $retrying,
            failed: $failed,
            skipped: $skipped,
            budgetExhausted: $budgetExhausted,
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->recordHeartbeat($result);

        if ($claimed > 0) {
            $this->logger->info('Mail drain finished', $result->toArray() + ['transport' => $transport->describe()]);
        }

        return $result;
    }

    /**
     * The transport, or null when this installation cannot send at all.
     *
     * Both halves are required and the distinction is not worth making here:
     * a message with no transport and a message with no From address are
     * equally unsendable, and both are reported by the self-check with far more
     * detail than a cron run could add.
     */
    private function resolveTransport(): ?MailTransport
    {
        if (!$this->mailConfigService->getConfig()->isComplete()) {
            $this->logger->warning(
                'Mail drain skipped: no sender address configured. Set it under Settings → Mail.'
            );

            return null;
        }

        try {
            $status = $this->mailTransportFactory->status();
            if (!$status->configured) {
                $this->logger->warning(
                    'Mail drain skipped: no mail.dsn configured. Announcements stay queued until one is set.'
                );

                return null;
            }

            return $this->mailTransportFactory->create();
        } catch (InvalidMailDsnException $e) {
            $this->logger->error('Mail drain skipped: mail.dsn is unusable', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function release(array $rows): void
    {
        foreach ($rows as $row) {
            $this->notificationsService->releaseClaim((string) $row['id']);
        }
    }

    /**
     * Every run leaves a heartbeat, including one that sent nothing.
     *
     * The PHP version and the missing-extension list are the drain's own, not
     * the web request's — see {@see PhpRuntime}. On mass hosting those are
     * routinely different interpreters, and when the queue does not move that
     * difference is the first thing worth ruling out.
     */
    private function recordHeartbeat(DrainResultDto $result): void
    {
        try {
            $this->cronHeartbeatRepository->record(
                $result->source,
                $result->sent,
                $result->failed,
                PhpRuntime::version(),
                PhpRuntime::missingExtensionsSummary(),
            );
        } catch (\Throwable $e) {
            // A heartbeat that cannot be written must not lose the mail that
            // was already sent — the run happened either way.
            $this->logger->error('Could not write cron heartbeat', ['message' => $e->getMessage()]);
        }
    }
}
