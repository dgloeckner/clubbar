<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Enums\MailStatus;
use App\Shared\Security\SecurityFinding;
use App\Shared\Security\SecuritySelfCheck;
use DateTimeImmutable;

/**
 * The diagnosis surface behind the alarm (#406, ADR-0038 rule 6 layer L3).
 *
 * The heartbeat says *something is wrong*. It cannot say what, because it is
 * deliberately outside this application — that is the whole point of it. These
 * rows are what the treasurer opens afterwards, and without them an alarm just
 * produces a phone call.
 *
 * ### Measured, never intended
 *
 * The same rule the rest of the self-check follows (ADR-0031 decision 3): every
 * row reports what was *observed*, and a row that could not be measured is
 * `unknown` rather than a pass. So "a transport is configured" is not a green
 * row for "mail works" — it is a green row for "a DSN parses and a sender
 * address exists", which is all this process can see without opening a socket.
 * What proves the chain works is the drain having run and the queue having
 * moved, which is the next two rows.
 *
 * ### Why nothing here is `fail`
 *
 * {@see SecurityFinding::FAIL} is reserved for "observed to be broken in a way
 * that exposes credentials or member data". A scheduler that stopped is
 * serious, and it is not that. Warnings that cannot be told apart from leaks
 * are warnings people learn to skim.
 *
 * Separate from {@see SecuritySelfCheck} rather than inside it because that
 * class is dependency-free by contract — `install.php` loads it by path before
 * Composer's autoloader exists — and these rows need the database.
 */
class MailDeliveryCheck
{
    public function __construct(
        private MailConfigService $mailConfigService,
        private SchedulerStatusService $schedulerStatusService,
        private NotificationsService $notificationsService,
    ) {}

    /**
     * @param DateTimeImmutable|null $now Threaded rather than injected: there is
     *                                    no clock abstraction here, and the
     *                                    tests need to state a timeline
     * @return list<SecurityFinding>
     */
    public function findings(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $status = $this->schedulerStatusService->status();
        $interval = $this->mailConfigService->getConfig()->cronInterval;
        $lastRunAt = self::parse($status->lastRunAt);

        return [
            $this->transportFinding(),
            $this->schedulerFinding($status->lastRunAt, $lastRunAt, $now, $interval),
            $this->intervalFinding($status->declaredInterval, $status->observedInterval, $status->intervalDisagrees),
            ...$this->queueFindings($now),
        ];
    }

    /**
     * Both halves of "can this installation address an envelope at all": a DSN
     * that parses, and a sender address to put on it. Reported as one row
     * because either one missing has the same effect, and two amber rows for
     * one answer is how a report stops being read.
     */
    private function transportFinding(): SecurityFinding
    {
        $label = 'Outgoing mail has a transport and a sender';
        $transport = $this->mailConfigService->transportStatus();
        $config = $this->mailConfigService->getConfig();

        if (!$transport->configured) {
            return SecurityFinding::warn(
                'mail_transport',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                'no mail.dsn is configured',
                'Nothing this installation queues can leave the host. Announcements are not lost — they stay '
                . '`pending` and go out once a transport exists — but every collection they announce is being '
                . 'made without the seven-day notice Nutzungsordnung § 7 Abs. 3 promises. Set mail.dsn in '
                . 'config.php.'
            );
        }

        if (!$transport->valid) {
            return SecurityFinding::warn(
                'mail_transport',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                'mail.dsn is unusable: ' . ($transport->error ?? 'unknown reason'),
                'The drain refuses to claim anything while the DSN cannot be built, which is deliberate — '
                . 'claiming would spend every queued message\'s attempts against a configuration error. Fix the '
                . 'DSN in config.php; the queue drains by itself afterwards.'
            );
        }

        if (!$config->isComplete()) {
            return SecurityFinding::warn(
                'mail_transport',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                $transport->summary . ', but no sender address is set',
                'A transport with no From address cannot send. Set the sender address under Settings → Mail; '
                . 'nothing else can invent a mailbox the club owns.'
            );
        }

        return SecurityFinding::pass(
            'mail_transport',
            SecuritySelfCheck::CATEGORY_DELIVERY,
            $label,
            $transport->summary . ', from ' . $config->senderAddress
        );
    }

    /**
     * Has the scheduler run, and recently enough for the interval it claims?
     *
     * "Ever" and "recently" are different questions with different owners: the
     * first gates finalize (#405) and the second is an operational warning that
     * never re-closes the gate. Refusing a collection at the moment the
     * treasurer needs it is the worse failure.
     */
    private function schedulerFinding(
        ?string $rawLastRun,
        ?DateTimeImmutable $lastRunAt,
        DateTimeImmutable $now,
        CronInterval $interval,
    ): SecurityFinding {
        $label = 'The mail scheduler is running';

        if ($rawLastRun === null) {
            return SecurityFinding::warn(
                'mail_scheduler_run',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                'no run has ever been observed',
                'The queue has exactly one sending path and nothing is driving it, so direct-debit settlements '
                . 'are refused until a run has been recorded (ADR-0038 rule 7). The admin banner shows the '
                . 'command for this installation; schedule it in your hosting panel.'
            );
        }

        if ($lastRunAt === null) {
            return SecurityFinding::unknown(
                'mail_scheduler_run',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                'the recorded run timestamp could not be read',
                'The heartbeat row holds a value this build cannot parse. Run the drain by hand once — it '
                . 'rewrites the row.'
            );
        }

        $observed = sprintf('last run %s (%s)', $rawLastRun, self::humanGap($now, $lastRunAt));

        if (QueueHealth::schedulerStopped($lastRunAt, $now, $interval)) {
            return SecurityFinding::warn(
                'mail_scheduler_run',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                $observed,
                sprintf(
                    'Nothing has run for more than two %s ticks, so the scheduler has most likely stopped. '
                    . 'Settlements are still allowed — the gate lifts permanently after the first run and does '
                    . 'not re-close — but announcements queued now will not go out until it is running again. '
                    . 'Check the cron entry in the hosting panel, and whether the PHP binary it names still '
                    . 'exists.',
                    $interval->value
                )
            );
        }

        return SecurityFinding::pass('mail_scheduler_run', SecuritySelfCheck::CATEGORY_DELIVERY, $label, $observed);
    }

    /**
     * What the installation declared against what its scheduler actually does.
     *
     * The same class of mismatch `php_version` is recorded to expose, and it
     * matters for the same reason: everything downstream — the retry ladder,
     * both stall thresholds — is measured in ticks of the declared interval, so
     * a declaration that is wrong makes every one of those numbers describe a
     * machine that does not exist.
     */
    private function intervalFinding(string $declared, ?string $observed, bool $disagrees): SecurityFinding
    {
        $label = 'The scheduler fires as often as it was declared to';

        if ($observed === null) {
            return SecurityFinding::unknown(
                'mail_scheduler_interval',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                "declared {$declared}; not enough runs to compare",
                'Two consecutive scheduled runs are needed before the real interval can be observed. This row '
                . 'answers itself once the scheduler has ticked twice.'
            );
        }

        if ($disagrees) {
            return SecurityFinding::warn(
                'mail_scheduler_interval',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $label,
                "declared {$declared}, observed {$observed}",
                'Retry backoff and the stall alarm are both measured in ticks of the declared interval, so a '
                . 'declaration faster than reality makes retries land on ticks that never come and the alarm '
                . 'fire early. Either schedule the cron more often, or set the interval under Settings → Mail '
                . 'to what the host actually offers. The declaration is never overridden automatically — this '
                . 'is a fact about the host that somebody has to decide about.'
            );
        }

        return SecurityFinding::pass(
            'mail_scheduler_interval',
            SecuritySelfCheck::CATEGORY_DELIVERY,
            $label,
            "declared {$declared}, observed {$observed}"
        );
    }

    /**
     * The queue itself: is anything overdue, and how much has been given up on?
     *
     * Two rows, because they call for different actions. An overdue message is
     * an infrastructure problem — something is due and nothing takes it. A
     * `failed` one is a message that was tried, rejected and closed; it needs a
     * human to look at the address, not a scheduler to be fixed.
     *
     * @return list<SecurityFinding>
     */
    private function queueFindings(DateTimeImmutable $now): array
    {
        $interval = $this->mailConfigService->getConfig()->cronInterval;
        $counts = $this->notificationsService->queueCounts();
        $pending = (int) ($counts[MailStatus::PENDING->value] ?? 0);
        $failed = (int) ($counts[MailStatus::FAILED->value] ?? 0);

        $rawOldestDue = $this->notificationsService->oldestDueAt();
        $oldestDue = self::parse($rawOldestDue);

        $backlogLabel = 'No message is waiting past its due time';

        if ($pending === 0) {
            $backlog = SecurityFinding::pass(
                'mail_queue_backlog',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $backlogLabel,
                'the queue is empty'
            );
        } elseif ($oldestDue !== null && QueueHealth::queueStalled($oldestDue, $now, $interval)) {
            $backlog = SecurityFinding::warn(
                'mail_queue_backlog',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $backlogLabel,
                sprintf('%d pending, oldest due %s (%s)', $pending, $rawOldestDue, self::humanGap($now, $oldestDue)),
                'Something has been due for three scheduler ticks and nothing has taken it. That is the '
                . 'signature of a scheduler that runs and achieves nothing — a CLI PHP without the extensions '
                . 'the transport needs is the usual cause, and the row above records which interpreter ran. '
                . 'Run the drain by hand from a shell to see the error directly.'
            );
        } else {
            $backlog = SecurityFinding::pass(
                'mail_queue_backlog',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $backlogLabel,
                sprintf('%d pending, none overdue', $pending)
            );
        }

        $failedLabel = 'No message has been given up on';

        $failedFinding = $failed === 0
            ? SecurityFinding::pass('mail_queue_failed', SecuritySelfCheck::CATEGORY_DELIVERY, $failedLabel, 'none')
            : SecurityFinding::warn(
                'mail_queue_failed',
                SecuritySelfCheck::CATEGORY_DELIVERY,
                $failedLabel,
                sprintf('%d failed', $failed),
                'These were attempted and rejected — a wrong address is by far the most common reason, and no '
                . 'amount of retrying fixes one. Open the settlement and read each failure\'s reason: the '
                . 'remedy is to correct the member\'s address, or to tell them another way. Best effort means '
                . 'visible, not chased.'
            );

        return [$backlog, $failedFinding];
    }

    private static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** "3 hours ago" — enough to read a row without doing arithmetic. */
    private static function humanGap(DateTimeImmutable $now, DateTimeImmutable $then): string
    {
        $seconds = $now->getTimestamp() - $then->getTimestamp();

        if ($seconds < 0) {
            return 'in the future';
        }

        if ($seconds < 3600) {
            return sprintf('%d minutes ago', intdiv($seconds, 60));
        }

        if ($seconds < 172800) {
            return sprintf('%d hours ago', intdiv($seconds, 3600));
        }

        return sprintf('%d days ago', intdiv($seconds, 86400));
    }
}
