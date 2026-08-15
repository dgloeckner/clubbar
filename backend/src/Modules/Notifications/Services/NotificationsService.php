<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Mail\MailSendResult;
use App\Shared\Services\AuditService;

/**
 * Who gets told what, and when it stops being a decision (ADR-0038).
 *
 * This service only ever *queues*. It opens no socket and starts no
 * transaction: `enqueueForSettlement()` runs inside the caller's, which is what
 * makes "the settlement exists" and "the announcements are queued" the same
 * fact. Sending is the drain's job (#403), and the drain is the only sender.
 *
 * The retry/claim methods live here rather than in #403 because they are
 * properties of the queue, not of whatever process happens to be draining it —
 * a CLI cron and a URL endpoint are two triggers for one behaviour.
 */
class NotificationsService
{
    public function __construct(
        private MailOutboxRepository $mailOutboxRepository,
        private MembersRepository $membersRepository,
        private AuditService $auditService,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    /**
     * Queue one pre-notification per collected member.
     *
     * **Must be called inside the settlement's own transaction.** It is not a
     * side effect of creating a settlement; it is part of creating one. A
     * failure here rolls the settlement back with it, which is the point —
     * a settlement whose announcements are unaccounted for is exactly the state
     * ADR-0038 exists to prevent.
     *
     * Three kinds of member are skipped, and only one of them is a problem:
     *
     * | Skipped | Why | Problem? |
     * |---|---|---|
     * | amount ≤ 0 | Nothing is collected, so there is nothing to announce | No |
     * | no email address | Collected from, and unreachable | **Yes** — reported, never blocking |
     * | already queued | The unique key held; this is a retried finalize | No |
     *
     * @param array<string,int> $amountsByMember Member id => cents this settlement collects
     */
    public function enqueueForSettlement(
        string $settlementId,
        array $amountsByMember,
        string $adminUserId,
        MailKind $kind = MailKind::SEPA_PRENOTIFICATION,
    ): EnqueueResultDto {
        $collectable = [];
        $withoutBalance = [];
        foreach ($amountsByMember as $memberId => $amountCents) {
            if ((int) $amountCents > 0) {
                $collectable[] = (string) $memberId;
            } else {
                $withoutBalance[] = (string) $memberId;
            }
        }

        if ($collectable === []) {
            return new EnqueueResultDto(0, [], $withoutBalance);
        }

        $recipients = $this->membersRepository->findMailRecipients($collectable);

        $queued = 0;
        $withoutEmail = [];
        foreach ($collectable as $memberId) {
            $member = $recipients[$memberId] ?? null;
            $email = trim((string) ($member['email'] ?? ''));

            if ($email === '') {
                $withoutEmail[] = $memberId;
                continue;
            }

            if ($this->mailOutboxRepository->enqueue(MailRequestDto::forMember(
                kind: $kind,
                settlementId: $settlementId,
                memberId: $memberId,
                recipient: $email,
                language: MailLanguage::fromPreferred($member['preferred_language'] ?? null),
            ))) {
                $queued++;
            }
        }

        $result = new EnqueueResultDto($queued, $withoutEmail, $withoutBalance);

        // Audited at enqueue because enqueue is the commitment: this is the
        // record that the club undertook to announce this collection, to these
        // members, at this moment. Whether each message later left the host is
        // queue state (#407), and best effort by decision.
        if ($queued > 0 || $withoutEmail !== []) {
            $this->auditService->log(
                action: AuditAction::MAIL_ENQUEUED,
                entityType: EntityType::SETTLEMENT,
                entityId: $settlementId,
                newValues: ['kind' => $kind->value] + $result->toArray(),
                adminUserId: $adminUserId,
            );
        }

        return $result;
    }

    /**
     * Split a cancelled settlement's announcements on what each member already
     * knows (ADR-0038).
     *
     * An announcement that never went out is superseded — there is nothing to
     * retract. One that did earns a cancellation notice. Telling somebody a
     * collection is called off when they were never told it was coming is worse
     * than saying nothing at all, which is why the two are not the same branch.
     *
     * Runs inside the caller's transaction, like the enqueue.
     */
    public function cancelSettlementNotifications(string $settlementId, string $adminUserId): EnqueueResultDto
    {
        // Read the recipients *before* superseding: after the UPDATE, `sent`
        // rows are still `sent`, but doing it in this order keeps the two
        // statements independent of each other's ordering.
        $alreadyTold = $this->mailOutboxRepository->findMemberIdsWithStatus(
            $settlementId,
            MailKind::SEPA_PRENOTIFICATION,
            MailStatus::SENT,
        );

        $superseded = $this->mailOutboxRepository->supersedePending(
            $settlementId,
            MailKind::SEPA_PRENOTIFICATION,
        );

        if ($superseded > 0) {
            $this->auditService->log(
                action: AuditAction::MAIL_SUPERSEDED,
                entityType: EntityType::SETTLEMENT,
                entityId: $settlementId,
                newValues: ['kind' => MailKind::SEPA_PRENOTIFICATION->value, 'superseded' => $superseded],
                adminUserId: $adminUserId,
            );
        }

        if ($alreadyTold === []) {
            return EnqueueResultDto::empty();
        }

        // The notice goes to the address the announcement went to, and the
        // amount is irrelevant to it — so this enqueue is by member, not by
        // balance. Reusing enqueueForSettlement() would mean inventing an
        // amount just to pass its `> 0` test.
        $recipients = $this->membersRepository->findMailRecipients($alreadyTold);

        $queued = 0;
        $withoutEmail = [];
        foreach ($alreadyTold as $memberId) {
            $member = $recipients[$memberId] ?? null;
            $email = trim((string) ($member['email'] ?? ''));

            if ($email === '') {
                // The address that received the announcement has since been
                // cleared — erased, or corrected to nothing. There is no
                // recipient left to retract to.
                $withoutEmail[] = $memberId;
                continue;
            }

            if ($this->mailOutboxRepository->enqueue(MailRequestDto::forMember(
                kind: MailKind::CANCELLATION_NOTICE,
                settlementId: $settlementId,
                memberId: $memberId,
                recipient: $email,
                language: MailLanguage::fromPreferred($member['preferred_language'] ?? null),
            ))) {
                $queued++;
            }
        }

        $result = new EnqueueResultDto($queued, $withoutEmail);

        if ($queued > 0 || $withoutEmail !== []) {
            $this->auditService->log(
                action: AuditAction::MAIL_ENQUEUED,
                entityType: EntityType::SETTLEMENT,
                entityId: $settlementId,
                newValues: ['kind' => MailKind::CANCELLATION_NOTICE->value] + $result->toArray(),
                adminUserId: $adminUserId,
            );
        }

        return $result;
    }

    /**
     * Take the next due messages. The drain (#403) is the only caller.
     *
     * @return list<array<string,mixed>>
     */
    public function claimBatch(int $limit): array
    {
        return $this->mailOutboxRepository->claimBatch($limit);
    }

    /**
     * Give a claimed message back untouched — the drain ran out of time before
     * reaching it, and nothing was attempted.
     */
    public function releaseClaim(string $outboxId): void
    {
        $this->mailOutboxRepository->releaseClaim($outboxId);
    }

    /**
     * Record what a transport reported for one claimed message.
     *
     * The interval is the caller's because the retry ladder is measured in
     * ticks of *this installation's* scheduler (ADR-0039 decision 5): the drain
     * cannot act between ticks, so a backoff finer than the schedule is a
     * number that describes a machine we do not have. The drain already holds
     * the mail configuration, so it passes the interval down rather than this
     * service loading the row once per message.
     */
    public function recordResult(string $outboxId, MailSendResult $result, CronInterval $interval): MailStatus
    {
        if ($result->sent) {
            $this->mailOutboxRepository->markSent($outboxId, $result->messageId);
            return MailStatus::SENT;
        }

        $attempts = $this->mailOutboxRepository->attemptsFor($outboxId) + 1;
        $retry = RetrySchedule::shouldRetry($result->transient, $attempts);

        return $this->mailOutboxRepository->markFailed(
            $outboxId,
            $result->error ?? 'unknown error',
            $attempts,
            $retry,
            $retry ? RetrySchedule::backoffSeconds($interval, $attempts) : 0,
        );
    }

    /**
     * The earliest moment anything in the queue became due, or null when
     * nothing is pending.
     *
     * The input to the stall alarm and to the self-check's backlog row. It is
     * a due time rather than an age on purpose — see {@see QueueHealth}.
     */
    public function oldestDueAt(): ?string
    {
        return $this->mailOutboxRepository->oldestDueAt();
    }

    /**
     * How many messages sit in each status.
     *
     * @return array<string,int>
     */
    public function queueCounts(): array
    {
        return $this->mailOutboxRepository->countsByStatus();
    }

    /**
     * Every queued message about one settlement, key or terminal — what #407's
     * detail view reads, and what a self-check would show for a credential.
     *
     * @return list<array<string,mixed>>
     */
    public function findBySubjectId(string $subjectId): array
    {
        return $this->mailOutboxRepository->findBySubjectId($subjectId);
    }

    /* ──────────────── Operational mail, addressed to an admin ──────────────── */

    /**
     * Warn whoever runs the club about something, at most once per occasion
     * (#438).
     *
     * The occasion is the point. An expiry warning is computed from a tier the
     * dashboard already recalculates on every request, so "is the key inside
     * the 30-day window?" is true for thirty days running — and a queue that
     * took that at face value would send thirty emails. Passing the tier as the
     * occasion makes `UNIQUE (kind, subject_id, dedup_key)` answer "has this
     * already been said?" for us, which is the idempotent-notification storage
     * #438 says it needs, and a stronger answer than the `logOnceSince` dedup
     * it names as the nearest precedent: that one is a time window, this one is
     * a constraint.
     *
     * Every active admin is written to, and each is deduplicated separately —
     * one admin having already been warned must not silence the others.
     *
     * The caller supplies the tier and nothing else about timing. This service
     * queues; it does not decide when anything is due, and it never sends
     * (ADR-0038 rule 3: the scheduler is the only sender).
     *
     * @param string $occasion What makes this warning distinct from the next one
     *                         about the same subject — a tier such as `30d`.
     */
    public function warnAdmins(
        MailKind $kind,
        string $subjectId,
        string $occasion,
        ?string $actorAdminUserId = null,
    ): EnqueueResultDto {
        if ($kind->addressesMember()) {
            // A member has no way to act on an expiring credential, and telling
            // them one is expiring leaks the state of the club's own security.
            throw new \InvalidArgumentException(
                sprintf('%s is addressed to a member and cannot be sent to admins', $kind->value)
            );
        }

        $queued = 0;
        $withoutEmail = [];

        foreach ($this->adminUsersRepository->findActiveRecipients() as $admin) {
            $email = trim((string) ($admin['email'] ?? ''));
            if ($email === '') {
                $withoutEmail[] = (string) $admin['id'];
                continue;
            }

            if ($this->mailOutboxRepository->enqueue(MailRequestDto::forAdmin(
                kind: $kind,
                subjectId: $subjectId,
                adminUserId: (string) $admin['id'],
                recipient: $email,
                language: MailLanguage::fromPreferred($admin['locale'] ?? null),
                occasion: $occasion,
            ))) {
                $queued++;
            }
        }

        $result = new EnqueueResultDto($queued, $withoutEmail);

        // Audited only when something was actually queued: this runs off a
        // request-time check that fires on every admin page load, and an audit
        // entry per page load would bury the one that matters.
        if ($queued > 0) {
            $this->auditService->log(
                action: AuditAction::MAIL_ENQUEUED,
                entityType: $kind->subjectType()->auditEntityType(),
                entityId: $subjectId,
                newValues: ['kind' => $kind->value, 'occasion' => $occasion] + $result->toArray(),
                adminUserId: $actorAdminUserId,
            );
        }

        return $result;
    }
}
