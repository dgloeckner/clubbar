<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\DTOs\QueuedMailDto;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Enums\MailSubject;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Settlements\Repositories\SettlementAnnouncementsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Logging\Logger;
use App\Shared\Enums\EntityType;
use App\Shared\Http\ListQuery;
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
        private SettlementAnnouncementsRepository $settlementAnnouncementsRepository,
        private Logger $logger,
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
     *
     * The **claimed row** is the argument rather than its id, because a delivery
     * is two writes and the second one needs to know what was delivered: the
     * queue row is marked `sent`, and — for a member's money mail — a
     * `settlement_announcements` row records that the member was announced to
     * (#408). The queue row is pruned ninety days later; that one is not, and it
     * is what the settlement detail reads once the queue row is gone.
     *
     * @param array<string,mixed> $row A claimed `mail_outbox` row.
     */
    public function recordResult(array $row, MailSendResult $result, CronInterval $interval): MailStatus
    {
        $outboxId = (string) $row['id'];

        if ($result->sent) {
            $sentAt = $this->mailOutboxRepository->markSent($outboxId, $result->messageId);
            $this->recordAnnouncement($row, $sentAt);

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
     * Leave the durable trace of a delivered announcement (#408, ADR-0029).
     *
     * Only for mail addressed to a member **about a settlement**. An expiry
     * warning is about a credential and goes to an admin — there is no
     * settlement for it to be evidence against, and no promise it discharges.
     *
     * ### Both halves of that sentence are conditions, and for a while only one
     * of them was checked
     *
     * `addressesMember()` alone was sufficient while every member-addressed
     * kind happened to be about a settlement. `MailKind::DECKEL_STATEMENT`
     * (ADR-0039) is the first that is not: its `subject_id` is the member. A
     * delivered Deckelauszug therefore tried to write a member id into
     * `settlement_announcements.settlement_id` under a `kind` the column's enum
     * does not have — and MariaDB's truncation warning surfaced as a
     * `PDOException` that aborted the **whole drain run**, after the message
     * had already gone out. One statement left per tick and the rest of the
     * queue stayed claimed, which looks from the outside like a queue that
     * simply is not moving.
     *
     * That is the same trap `MailKind::addressesMember()` documents one level
     * up, sprung in the place that consumed it. The condition now asks what
     * this record actually is: proof that a member was announced to about a
     * settlement. A statement announces nothing and proves nothing anyone will
     * need — ADR-0039 decision 6 prunes its queue row at ninety days for that
     * exact reason.
     *
     * A missing `sent_at` means the queue row vanished between the mark and the
     * read, which on a live installation means somebody deleted it by hand. It
     * is logged rather than thrown: the message *was* sent, and failing the
     * drain over a lost bookkeeping row would turn one missing record into a
     * batch that never went out. The write itself is now held to that same
     * promise — see below.
     *
     * @param array<string,mixed> $row
     */
    private function recordAnnouncement(array $row, ?string $sentAt): void
    {
        $kind = MailKind::tryFrom((string) ($row['kind'] ?? ''));
        $memberId = (string) ($row['member_id'] ?? '');
        $subjectId = (string) ($row['subject_id'] ?? '');

        if ($kind === null || !$kind->addressesMember() || $memberId === '' || $subjectId === '') {
            return;
        }

        // The second half of "addressed to a member about a settlement".
        if ($kind->subjectType() !== MailSubject::SETTLEMENT) {
            return;
        }

        if ($sentAt === null) {
            $this->logger->warning('Sent mail left no announcement record', [
                'outbox_id' => $row['id'] ?? null,
                'kind' => $kind->value,
                'subject_id' => $subjectId,
            ]);

            return;
        }

        try {
            $this->settlementAnnouncementsRepository->record($subjectId, $memberId, $kind, $sentAt);
        } catch (\Throwable $e) {
            // The message is already delivered and already marked `sent`. The
            // docblock above promises that a lost bookkeeping row does not take
            // the batch with it; without this, only the *missing sent_at* case
            // kept that promise and a refused INSERT broke it — which is
            // precisely how the bug described above escalated from "one wrong
            // row" to "the queue stopped".
            $this->logger->error('Delivered mail left no announcement record', [
                'outbox_id' => $row['id'] ?? null,
                'kind' => $kind->value,
                'subject_id' => $subjectId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /* ─────────────────────── Erasure and retention (#408) ─────────────────────── */

    /**
     * Take a member's address out of the queue, as part of erasing them.
     *
     * **Must be called inside the offboarding transaction.** ADR-0029 is
     * explicit that the outbox is covered "or neither": an anonymisation that
     * commits `members.email = NULL` and then fails here has not erased the
     * address, it has moved it somewhere nobody looks.
     *
     * Two writes, in this order. Pending rows are withdrawn first — a message
     * queued for somebody being erased is not going out — and then every row's
     * address is cleared, the withdrawn ones included.
     *
     * @return array{superseded: int, cleared: int}
     */
    public function eraseMember(string $memberId): array
    {
        $superseded = $this->mailOutboxRepository->supersedePendingForMember($memberId);
        $cleared = $this->mailOutboxRepository->eraseMemberRecipients($memberId);

        if ($superseded > 0 || $cleared > 0) {
            $this->logger->info('Mail outbox erased for member', [
                'member_id' => $memberId,
                'superseded' => $superseded,
                'cleared' => $cleared,
            ]);
        }

        return ['superseded' => $superseded, 'cleared' => $cleared];
    }

    /**
     * Drop delivered rows whose retention window has passed (#408).
     *
     * ADR-0029 refuses an unattended sweep over *accounting records*, because no
     * scheduled job can know whether the Ablaufhemmung under § 147 Abs. 3 S. 5
     * AO still runs. That objection does not reach here, and the ADR says so:
     * this deletes queue rows the scheduler has already delivered, and the
     * retention-tier fact they carried — that the member was announced to — was
     * copied to `settlement_announcements` at the moment of delivery and is not
     * touched.
     *
     * Per kind, because the window is per kind, and bounded per kind so one
     * enormous backlog cannot starve the others of their pass.
     *
     * @param int|null $now Unix timestamp; injected by the tests so an age can be
     *                      stated rather than waited for.
     * @return int Rows deleted across all kinds.
     */
    public function pruneDelivered(?int $now = null): int
    {
        $now ??= time();
        $deleted = 0;

        foreach (MailKind::cases() as $kind) {
            $deleted += $this->mailOutboxRepository->pruneSent(
                $kind,
                MailRetention::cutoffFor($kind, $now),
                MailRetention::PRUNE_BATCH,
            );
        }

        if ($deleted > 0) {
            $this->logger->info('Pruned delivered mail from the outbox', ['deleted' => $deleted]);
        }

        return $deleted;
    }

    /**
     * A page of the queue, for the admin panel's Notifications list (#407).
     *
     * @param array<string,mixed> $filters `kind`, `status`, `subject_id`, `search`
     * @return array{items: list<QueuedMailDto>, total: int}
     */
    public function search(array $filters, ListQuery $query): array
    {
        $rows = $this->mailOutboxRepository->search(
            $filters,
            $query->perPage,
            $query->offset,
            $query->sortKey,
            $query->sortOrder,
        );

        return [
            'items' => array_map(QueuedMailDto::fromRow(...), $rows),
            'total' => $this->mailOutboxRepository->countMatching($filters),
        ];
    }

    /**
     * Put one failed message back in the queue — the only state change the UI
     * is allowed to make (ADR-0038 rule 4).
     *
     * It changes state and does not orchestrate time: the row becomes due, and
     * the scheduler sends it whenever it next runs. There is deliberately
     * nothing here that reports *when* that will be, because the honest answer
     * is "at the next tick", and a UI that promised better would be inventing a
     * second sending path in the reader's head.
     *
     * Audited: retrying is a decision a person made about a member's
     * announcement, and it is the one queue transition with a human behind it.
     *
     * @return QueuedMailDto|null The row as it now stands, or null when it was
     *         not retryable — a `sent` row, a `superseded` one, or no row at all
     */
    public function retry(string $outboxId, string $adminUserId): ?QueuedMailDto
    {
        if (!$this->mailOutboxRepository->resetToPending($outboxId)) {
            return null;
        }

        $row = $this->mailOutboxRepository->findById($outboxId);
        if ($row === null) {
            return null;
        }

        $dto = QueuedMailDto::fromRow($row);

        $this->auditService->log(
            action: AuditAction::MAIL_RETRIED,
            entityType: $dto->kind->subjectType()->auditEntityType(),
            entityId: $dto->subjectId,
            newValues: ['kind' => $dto->kind->value, 'outbox_id' => $dto->id, 'member_id' => $dto->memberId],
            adminUserId: $adminUserId,
        );

        return $dto;
    }

    /** One queued message, or null. */
    public function find(string $outboxId): ?QueuedMailDto
    {
        $row = $this->mailOutboxRepository->findById($outboxId);

        return $row === null ? null : QueuedMailDto::fromRow($row);
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
     * Every queued message about one settlement, key, terminal or admin
     * account, as raw rows.
     *
     * @return list<array<string,mixed>>
     */
    public function findBySubjectId(string $subjectId): array
    {
        return $this->mailOutboxRepository->findBySubjectId($subjectId);
    }

    /**
     * The same, as the DTO the settlement detail renders (#407).
     *
     * Goes through {@see search()} rather than `findBySubjectId()` so the rows
     * arrive with the member name joined — the breakdown lists members, and a
     * row that could only say "member 4f3a…" would send the reader back to the
     * member list to find out whose announcement failed.
     *
     * The limit is a guard rather than a page: a settlement queues one message
     * per collected member plus, at most, one cancellation notice each, so a
     * club that exceeded this would have bigger surprises waiting.
     *
     * @return list<QueuedMailDto>
     */
    public function findQueuedFor(string $subjectId, int $limit = 2000): array
    {
        return array_map(
            QueuedMailDto::fromRow(...),
            $this->mailOutboxRepository->search(['subject_id' => $subjectId], $limit, 0, 'queued_at', 'asc'),
        );
    }

    /* ──────────────── Operational mail, addressed to an admin ──────────────── */

    /*
     * `warnAdmins()` used to live here and now lives in {@see AdminNotifier}.
     *
     * The split is about dependencies rather than size. This service needs
     * `MembersRepository` for the money mail, which reaches the IBAN sealed box
     * and its required key — so a caller that only wanted to tell the admins
     * something had to satisfy the bank-details configuration first. ADR-0043's
     * issuance notice is the first such caller, and it surfaced as a terminal
     * service that could not be constructed in a run with no bank details
     * anywhere near it.
     *
     * Deliberately not left behind as a forwarding method. A shim that only
     * exists so old call sites keep compiling is how "NotificationsService warns
     * admins" stays true in everyone's head long after it stopped being where
     * the behaviour lives.
     */

    /**
     * A member's card was assigned: greet them, or tell them the old card has
     * stopped working (ADR-0051).
     *
     * Which of the two it is is read from the transition wherever the
     * transition can say — `$replacesExistingCard` is true when a card was
     * already on file, and a card replacing another is a replacement whatever
     * this queue happens to hold. The one ambiguous case is a card cleared and
     * later reassigned, which looks exactly like a first assignment: there the
     * welcome is attempted and a refused insert is what reports that the member
     * has been greeted before. That is `UNIQUE (kind, subject_id, dedup_key)`
     * answering rather than a `SELECT`, so two overlapping requests cannot both
     * decide they are the first.
     *
     * Reading the transition first is not only tidier — it is what keeps the
     * common replacement independent of {@see MailRetention}. A welcome pruned
     * at ninety days would otherwise turn every later replacement back into a
     * greeting.
     *
     * Best effort, and never a gate. It queues; it does not send (ADR-0038
     * rule 3), and the card assignment it announces is already committed.
     *
     * @return MailKind|null What was queued, or null when nothing was — no
     *         address on file, or the notice was already there.
     */
    public function notifyMemberCard(
        string $memberId,
        string $recipient,
        MailLanguage $language,
        bool $replacesExistingCard,
        ?string $actorAdminUserId = null,
    ): ?MailKind {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return null;
        }

        if (!$replacesExistingCard) {
            $queued = $this->mailOutboxRepository->enqueue(MailRequestDto::forMemberOccasion(
                kind: MailKind::MEMBER_WELCOME,
                memberId: $memberId,
                recipient: $recipient,
                language: $language,
                // A constant, not a moment: a member is welcomed once, and this
                // is the key that says so.
                occasion: self::WELCOME_OCCASION,
            ));

            if ($queued) {
                $this->auditMemberNotice(MailKind::MEMBER_WELCOME, $memberId, self::WELCOME_OCCASION, $actorAdminUserId);

                return MailKind::MEMBER_WELCOME;
            }
        }

        // Either the card genuinely replaces one, or the welcome came back as a
        // duplicate and therefore says this member has been greeted already.
        $occasion = 'replaced:' . time();
        $queued = $this->mailOutboxRepository->enqueue(MailRequestDto::forMemberOccasion(
            kind: MailKind::MEMBER_CARD_REPLACED,
            memberId: $memberId,
            recipient: $recipient,
            language: $language,
            occasion: $occasion,
        ));

        if (!$queued) {
            return null;
        }

        $this->auditMemberNotice(MailKind::MEMBER_CARD_REPLACED, $memberId, $occasion, $actorAdminUserId);

        return MailKind::MEMBER_CARD_REPLACED;
    }

    /**
     * A member's address moved: tell both ends of the move (ADR-0051).
     *
     * Two messages, because they answer two different questions for two
     * different readers, and only one of them can be asked of each.
     *
     * The copy to the **former** address is the member half of
     * {@see MailKind::ADMIN_EMAIL_CHANGED} and exists for the same reason: it
     * is the one channel through which a change the member did not ask for
     * reaches them. A member has no session to be stolen, so the likely cause
     * is a Kassenwart editing the wrong row rather than an attacker — the same
     * failure, duller and more probable. Its recipient cannot be derived at
     * send time, because by then `members.email` holds the new address; it is
     * frozen into the row's snapshot here, which is the guarantee that column
     * exists to give.
     *
     * The copy to the **new** address is the only thing in this system that
     * ever checks an address exists. #362 made one mandatory because § 7 Abs. 3
     * is a promise, and then trusted it; this is the message whose bounce says
     * otherwise, months before a collection depends on it, in a place somebody
     * looks.
     *
     * `$occasion` is the moment rather than a tier, because two moves of one
     * member's address are two separate things to be told about — including a
     * move back to an address used before. Unix seconds: `forMemberOccasion()`
     * writes the occasion straight into a VARCHAR(64), and while that leaves
     * room here, the pair below appends to it and a formatted timestamp buys
     * nothing a stamp does not.
     *
     * Best effort, and never a gate. The change is already committed.
     *
     * @return list<MailKind> What was actually queued, in send order.
     */
    public function notifyMemberAddressChange(
        string $memberId,
        ?string $formerEmail,
        ?string $newEmail,
        MailLanguage $language,
        string $occasion,
        ?string $actorAdminUserId = null,
    ): array {
        $queued = [];

        foreach (
            [
                [MailKind::MEMBER_EMAIL_CHANGED, trim((string) $formerEmail), 'former'],
                [MailKind::MEMBER_EMAIL_ACTIVATED, trim((string) $newEmail), 'current'],
            ] as [$kind, $recipient, $end]
        ) {
            // A member who had no address before this move has no former end to
            // write to, and the move that clears one has no current end. Either
            // is ordinary rather than an error.
            if ($recipient === '') {
                continue;
            }

            // The two copies share a moment and would otherwise share a dedup
            // key; the end distinguishes them. Not the address itself, which is
            // already in `recipient` and has no business being in an index too.
            $dedup = $occasion . ':' . $end;

            if (!$this->mailOutboxRepository->enqueue(MailRequestDto::forMemberOccasion(
                kind: $kind,
                memberId: $memberId,
                recipient: $recipient,
                language: $language,
                occasion: $dedup,
            ))) {
                continue;
            }

            $this->auditMemberNotice($kind, $memberId, $dedup, $actorAdminUserId);
            $queued[] = $kind;
        }

        return $queued;
    }

    /** The dedup key that makes a welcome fire at most once per member. */
    private const WELCOME_OCCASION = 'welcome';

    /**
     * One audit line per queued member notice, filed the way every other
     * enqueue is (ADR-0013).
     *
     * The address is deliberately absent from the payload. It is already on the
     * outbox row, where erasure can reach it; a copy in `audit_log` would be a
     * third place a member's address lives and the one place ADR-0029 scrubs
     * only by entity id.
     */
    private function auditMemberNotice(
        MailKind $kind,
        string $memberId,
        string $occasion,
        ?string $actorAdminUserId,
    ): void {
        $this->auditService->log(
            action: AuditAction::MAIL_ENQUEUED,
            entityType: $kind->subjectType()->auditEntityType(),
            entityId: $memberId,
            newValues: ['kind' => $kind->value, 'occasion' => $occasion],
            adminUserId: $actorAdminUserId,
        );
    }

    /**
     * Tell an address that it is no longer the login for the account it used
     * to be — sent *to the former address*, which is what makes it useful.
     *
     * Every other admin-addressed kind resolves its recipient from
     * `admin_users` at send time. This one cannot: the row already holds the
     * new address by the time this is called, and the new address is precisely
     * the one that does not need telling. The former address is passed in and
     * frozen into `mail_outbox.recipient`.
     *
     * `$occasion` is the moment of the change rather than a tier, because two
     * changes of the same account's address are two separate things to be told
     * about — including a change back to an address used before. That trades a
     * little idempotency for delivery, and the trade is the right way round:
     * this announces a change that is already committed, not one that a retry
     * could duplicate.
     *
     * Best effort, and never a gate. It queues; it does not send (ADR-0038
     * rule 3), an install with no `mail.dsn` discards it at the transport, and
     * the credential change it describes has already happened.
     */
    public function notifyFormerAddress(
        string $adminUserId,
        string $formerEmail,
        string $occasion,
        ?string $actorAdminUserId = null,
    ): bool {
        $formerEmail = trim($formerEmail);
        if ($formerEmail === '') {
            return false;
        }

        $admin = $this->adminUsersRepository->findById($adminUserId);

        $queued = $this->mailOutboxRepository->enqueue(MailRequestDto::forAdmin(
            kind: MailKind::ADMIN_EMAIL_CHANGED,
            subjectId: $adminUserId,
            adminUserId: $adminUserId,
            recipient: $formerEmail,
            language: MailLanguage::fromPreferred($admin['locale'] ?? null),
            occasion: $occasion,
        ));

        if ($queued) {
            $this->auditService->log(
                action: AuditAction::MAIL_ENQUEUED,
                entityType: MailKind::ADMIN_EMAIL_CHANGED->subjectType()->auditEntityType(),
                entityId: $adminUserId,
                newValues: ['kind' => MailKind::ADMIN_EMAIL_CHANGED->value, 'occasion' => $occasion],
                adminUserId: $actorAdminUserId,
            );
        }

        return $queued;
    }
}
