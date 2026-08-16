<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\DrainResultDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\CronInterval;
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
 * anything. The *external* heartbeat, on the other hand, is told this is a
 * failure — see below.
 *
 * ### Two heartbeats, and why they are not the same thing
 *
 * `cron_heartbeat` is a row in this installation's own database, and it answers
 * "did a run happen?" for the install gate and the self-check. It cannot raise
 * an alarm: reading it requires the application to be reachable and somebody to
 * look.
 *
 * {@see HeartbeatPinger} is the alarm, and it is external precisely so it does
 * not depend on any of that (ADR-0038 rule 6). Both are written by every run,
 * and they say different things on purpose — an idle run with no transport
 * stamps the local heartbeat (the scheduler works) *and* pings `/fail` (nothing
 * can be sent).
 */
class DrainService
{
    /**
     * Messages claimed per round when nothing else says.
     *
     * The normal source is `mail_config.drain_batch_size` (default 100), which
     * a club on a stricter relay can lower without editing a file. This
     * constant is the floor under a configuration that is missing or
     * unreadable, and the environment variable still overrides both.
     *
     * Twenty-five is a claim held for a few seconds on a slow SMTP server, and
     * a settlement for a club of this size is one or two rounds. Larger batches
     * do not go faster — the transport is serial either way — they only widen
     * the window in which a killed run leaves rows claimed.
     */
    public const DEFAULT_BATCH_SIZE = 25;

    /**
     * Wall-clock budget for one run, in seconds, when nothing else says.
     *
     * The number that actually matters is the timeout of whatever is triggering
     * the run — a gateway read timeout on the URL fallback, the panel's cron
     * cap on the CLI one — and it is not visible from here. Stopping early with
     * the queue intact beats being killed mid-send, because a killed run leaves
     * its rows claimed and the stale window hands them back to the *next* run:
     * a message that was already delivered is offered to the transport a second
     * time.
     *
     * That asymmetry is why this dropped from fifty to twenty-five in #473. The
     * normal source is now `mail_config.drain_budget_seconds`, which a club
     * whose scheduler times out sooner can lower without editing a file; this
     * constant is the floor under a configuration that is missing or
     * unreadable, so it is sized against the *tightest* trigger this project
     * knows of (an external scheduler's 30 seconds) rather than the most
     * generous (IONOS's 60).
     */
    public const DEFAULT_BUDGET_SECONDS = MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS;

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

    /**
     * @param int|null $batchSize     `null` means "take it from mail_config"; a
     *                                number is an environment-level override
     * @param int|null $budgetSeconds Same layering, same reason (#473)
     */
    public function __construct(
        private NotificationsService $notificationsService,
        private MailContentRegistry $mailContent,
        private MailTransportFactory $mailTransportFactory,
        private MailConfigService $mailConfigService,
        private CronHeartbeatRepository $cronHeartbeatRepository,
        private HeartbeatPinger $heartbeatPinger,
        private Logger $logger,
        private ?int $batchSize = null,
        private ?int $budgetSeconds = null,
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

        // Before anything else, so a run that hangs leaves a start with no
        // finish — which is a different picture from a cron that never fired,
        // and has a different remedy.
        $this->heartbeatPinger->start();

        try {
            return $this->drain($source, $startedAt, $batchSize, $budgetSeconds);
        } catch (\Throwable $e) {
            // `run()` does not throw: it is called from a crontab nobody reads.
            // But the alarm has to hear about it, or an installation whose
            // database went away looks exactly like a healthy idle one.
            $this->logger->error('Mail drain aborted', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $this->heartbeatPinger->fail(HeartbeatPinger::REASON_RUN_ABORTED);

            // Not `idle()`. An aborted run and an idle one print the same
            // counters — all zero — and the difference between them is the
            // difference between "nothing was due" and "the queue has stopped
            // and some of it may already have been claimed and left claimed".
            // The caller has to be able to tell, because on the command line
            // it is the only thing there is to read.
            return DrainResultDto::aborted(
                $source,
                get_class($e) . ': ' . $e->getMessage(),
                microtime(true) - $startedAt,
            );
        }
    }

    private function drain(
        DrainSource $source,
        float $startedAt,
        ?int $batchSize,
        ?int $budgetSeconds,
    ): DrainResultDto {
        $config = $this->mailConfigService->getConfig();
        // Precedence: the caller's flag, then the environment override, then
        // the club-editable setting. Each layer exists for a different person —
        // somebody debugging by hand, a host that has to pin the value outside
        // the database, and the treasurer whose relay is stricter than average.
        $batchSize = max(1, $batchSize ?? $this->batchSize ?? $config->drainBatchSize);
        // Same three layers for the budget (#473). Its floor is one second
        // rather than the column's ten: a caller passing `--budget 1` on the
        // command line is asking for a single round, and refusing them would
        // only mean re-deriving the same bound in `bin/cron.php`.
        $budgetSeconds = max(1, $budgetSeconds ?? $this->budgetSeconds ?? $config->drainBudgetSeconds);
        $interval = $config->cronInterval;

        $transport = $this->resolveTransport();

        if ($transport === null) {
            // Retention does not wait for the mail server. Pruning deletes what
            // has *already* been delivered, so it is neither helped nor blocked
            // by there being somewhere to send to — and an installation whose
            // SMTP has been wrong for a month must not also have stopped
            // erasing addresses it no longer needs (#408).
            $pruned = $this->notificationsService->pruneDelivered();

            // Nothing claimed, nothing burnt. The queue waits for the
            // configuration rather than being spent against it.
            $result = new DrainResultDto(
                source: $source,
                pruned: $pruned,
                durationSeconds: microtime(true) - $startedAt,
            );
            $this->recordHeartbeat($result);
            // The scheduler works and nothing can leave the host — the exact
            // state a liveness-only check would report as green.
            $this->heartbeatPinger->fail(
                HeartbeatPinger::REASON_TRANSPORT_UNAVAILABLE,
                $this->queueCounts(),
            );

            return $result;
        }

        $sender = $config->toSender();

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
                    $message = $this->mailContent->build($row);
                } catch (\Throwable $e) {
                    // The settlement behind the row is gone, or the kind has no
                    // content yet. Neither becomes true later, so this is
                    // permanent — and it is a data problem, not a mail one.
                    $skipped++;
                    $this->notificationsService->recordResult(
                        $row,
                        MailSendResult::permanentFailure('Cannot render message: ' . $e->getMessage()),
                        $interval,
                    );
                    $this->logger->error('Drain could not render a queued message', [
                        'outbox_id' => $row['id'] ?? null,
                        'kind' => $row['kind'] ?? null,
                        'subject_id' => $row['subject_id'] ?? null,
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

                $status = $this->notificationsService->recordResult($row, $sendResult, $interval);

                match (true) {
                    $status === MailStatus::SENT => $sent++,
                    // Back to pending with a backoff: greylisting, and it is
                    // ordinary operation rather than an error.
                    $status === MailStatus::PENDING => $retrying++,
                    default => $failed++,
                };
            }
        }

        // Retention, at the tail of the run and only when there is time left for
        // it (#408). The scheduler is the only unattended process this system
        // has, so it is the only thing that can carry out the pruning ADR-0029
        // asks for — and sending comes first: a run that spent its budget
        // delivering has done the more urgent half, and the rows it did not
        // prune are ninety days old and can wait for the next tick.
        $pruned = $budgetExhausted ? 0 : $this->notificationsService->pruneDelivered();

        $result = new DrainResultDto(
            source: $source,
            claimed: $claimed,
            sent: $sent,
            retrying: $retrying,
            failed: $failed,
            skipped: $skipped,
            pruned: $pruned,
            budgetExhausted: $budgetExhausted,
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->recordHeartbeat($result);
        $this->reportOutcome($result, $interval);

        if ($claimed > 0) {
            $this->logger->info('Mail drain finished', $result->toArray() + ['transport' => $transport->describe()]);
        }

        return $result;
    }

    /**
     * Tell the external monitor how this run went (ADR-0038 rule 6).
     *
     * The distinction that matters: a run that failed to deliver to one
     * recipient is a **success** here. That message is a `failed` row the
     * Kassenwart can see and act on, and an alarm that fires on every typo'd
     * address is one somebody switches off. What earns `/fail` is the queue not
     * moving — a message that has been *due* for three scheduler ticks with
     * nothing taking it, which is precisely the state a cron that starts
     * reliably and achieves nothing produces.
     *
     * Checked after the run rather than before, so a run that has just cleared
     * the backlog does not alarm about the backlog it cleared.
     */
    private function reportOutcome(DrainResultDto $result, CronInterval $interval): void
    {
        if (!$this->heartbeatPinger->isConfigured()) {
            return;
        }

        $counts = $this->queueCounts() + ['sent_this_run' => $result->sent];

        $oldestDue = $this->notificationsService->oldestDueAt();
        $stalled = QueueHealth::queueStalled(
            $oldestDue === null ? null : new \DateTimeImmutable($oldestDue),
            new \DateTimeImmutable(),
            $interval,
        );

        if ($stalled) {
            $this->heartbeatPinger->fail(HeartbeatPinger::REASON_QUEUE_STALLED, $counts);

            return;
        }

        $this->heartbeatPinger->success($counts);
    }

    /**
     * Counts, and nothing but counts — see {@see HeartbeatPinger}: no address
     * and no name may leave this host through the monitor.
     *
     * @return array<string,int>
     */
    private function queueCounts(): array
    {
        try {
            $counts = $this->notificationsService->queueCounts();
        } catch (\Throwable $e) {
            // A ping with no counts still carries the signal that matters.
            return [];
        }

        return [
            'pending' => (int) ($counts[MailStatus::PENDING->value] ?? 0),
            'failed' => (int) ($counts[MailStatus::FAILED->value] ?? 0),
            'sent' => (int) ($counts[MailStatus::SENT->value] ?? 0),
        ];
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
