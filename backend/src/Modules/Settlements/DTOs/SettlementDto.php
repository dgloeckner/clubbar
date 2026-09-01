<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Modules\Notifications\DTOs\QueuedMailDto;
use App\Modules\Settlements\Domain\BlockedReason;
use App\Modules\Settlements\Domain\CancellationGate;
use App\Modules\Settlements\Domain\ReversalGate;
use App\Modules\Settlements\Domain\SettlementReference;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Enums\SettlementStatus;

final readonly class SettlementDto
{
    public function __construct(
        public string $id,
        public SettlementMethod $method,
        public string $settlementDate,
        public string $executionDate,
        public ?string $periodStart,
        public ?string $periodEnd,
        public int $totalAmountCents,
        public int $memberCount,
        public bool $isCancelled,
        public ?string $cancelledAt,
        public ?string $exportedAt,
        public ?string $notes,
        public array $items,
        public string $createdAt,
        public ?string $createdByAdminId,
        public ?string $createdByAdminName,
        public int $transactionCount = 0,
        public ?string $transactionDateMin = null,
        public ?string $transactionDateMax = null,
        public ?string $submittedAt = null,
        public ?string $submittedByAdminId = null,
        /**
         * Whether an Undo would still be accepted (#81). Derived from the same
         * CancellationGate the service throws from, so the button the admin
         * sees and the answer the API gives cannot drift apart.
         */
        public bool $isCancellable = false,
        /**
         * Why not, when it is not — shown instead of a bare disabled button.
         * Carries the code the admin panel translates alongside the English
         * sentence (#757); serialised as three keys, see {@see toArray()}.
         */
        public ?BlockedReason $cancellationBlockedReason = null,
        /**
         * Where this settlement stands (ruling #148 §6). Derived from
         * `is_cancelled`, the reversal rows, `submitted_at` and `exported_at`
         * on every read — there is no status column, so nothing can drift.
         */
        public SettlementStatus $status = SettlementStatus::DRAFT,
        /** The mirror of `is_cancellable`: money moved, so it can come back. */
        public bool $isReversible = false,
        public ?BlockedReason $reversalBlockedReason = null,
        /** How many of this settlement's members have been reversed. */
        public int $reversedMemberCount = 0,
        /**
         * The reversal events themselves, when the caller asked for one
         * settlement. Empty in list responses, which do not join them.
         *
         * @var list<SettlementReversalDto>
         */
        public array $reversals = [],
        /**
         * What was queued to announce this settlement, per member (#407).
         *
         * Rides along on the single-settlement read for the same reason
         * `reversals` does: the expandable breakdown answers "what happened to
         * this member", and *"the announcement bounced"* is part of that answer.
         * A second request per expanded row would be the alternative, and this
         * one is already being made.
         *
         * Empty in list responses. Best effort per ADR-0038 rule 6 — this is
         * the "never invisible" half of it.
         *
         * @var list<QueuedMailDto>
         */
        public array $notifications = [],
        /**
         * The announcements that actually went out, per member (#408).
         *
         * Not a duplicate of `notifications`, and the difference is a lifetime.
         * A queue row carries the recipient address, which ADR-0029 puts in the
         * operational tier: erased with the member, and pruned ninety days after
         * delivery. This carries only *that* a member was announced to and when,
         * names no address, and is kept for the retention period — so the
         * breakdown still answers "was this member told?" about a collection
         * from three years ago, whose queue rows are long gone.
         *
         * While both exist they agree by construction: the timestamp here is
         * copied from the queue row at the moment of delivery.
         *
         * @var list<array{member_id: string, kind: string, sent_at: string}>
         */
        public array $announcements = [],
    ) {}

    /**
     * @param list<SettlementReversalDto> $reversals Only the single-settlement
     *        read passes these; the list endpoint does not join them.
     * @param list<QueuedMailDto> $notifications Likewise.
     * @param list<array{member_id: string, kind: string, sent_at: string}> $announcements Likewise.
     */
    public static function fromRow(
        array $row,
        array $items = [],
        array $reversals = [],
        array $notifications = [],
        array $announcements = [],
    ): self {
        // Rows read outside SettlementsRepository (minimal fixtures, older
        // tests) carry no counts. Absent means none, which derives the same
        // status the settlement had before reversals existed.
        $reversedMemberCount = (int) ($row['reversal_member_count'] ?? count($reversals));
        $settledMemberCount = (int) ($row['settled_member_count'] ?? $row['member_count'] ?? 0);

        return new self(
            id: $row['id'],
            // Default to DIRECT_DEBIT for rows that predate #163's `method`
            // column (e.g. minimal fixtures in older tests).
            method: SettlementMethod::tryFrom($row['method'] ?? '') ?? SettlementMethod::DIRECT_DEBIT,
            settlementDate: $row['settlement_date'],
            executionDate: $row['execution_date'],
            periodStart: $row['period_start'] ?? null,
            periodEnd: $row['period_end'] ?? null,
            totalAmountCents: (int) $row['total_amount_cents'],
            memberCount: (int) $row['member_count'],
            isCancelled: (bool) $row['is_cancelled'],
            cancelledAt: $row['cancelled_at'] ?? null,
            exportedAt: $row['exported_at'] ?? null,
            notes: $row['notes'] ?? null,
            items: $items,
            createdAt: $row['created_at'],
            createdByAdminId: $row['created_by_admin_id'] ?? null,
            createdByAdminName: $row['admin_display_name'] ?? null,
            transactionCount: (int) ($row['transaction_count'] ?? 0),
            transactionDateMin: $row['transaction_date_min'] ?? null,
            transactionDateMax: $row['transaction_date_max'] ?? null,
            submittedAt: $row['submitted_at'] ?? null,
            submittedByAdminId: $row['submitted_by_admin_id'] ?? null,
            isCancellable: CancellationGate::isCancellable($row),
            cancellationBlockedReason: CancellationGate::blocker($row),
            status: SettlementStatus::derive($row, $reversedMemberCount, $settledMemberCount),
            isReversible: ReversalGate::isReversible($row),
            reversalBlockedReason: ReversalGate::blocker($row),
            reversedMemberCount: $reversedMemberCount,
            reversals: $reversals,
            notifications: $notifications,
            announcements: $announcements,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method->value,
            'settlement_date' => $this->settlementDate,
            'execution_date' => $this->executionDate,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            // The one string that names this settlement everywhere: in the
            // pain.008 (MsgId, PmtInfId), in the Verwendungszweck a member
            // reads on their statement, in the announcement mail, and here.
            // Rendered once, server-side, so the rule does not also exist in
            // TypeScript (Pattern 003).
            'reference' => SettlementReference::of($this->id),
            'total_amount_cents' => $this->totalAmountCents,
            'total_amount_eur' => round($this->totalAmountCents / 100, 2),
            'member_count' => $this->memberCount,
            'is_cancelled' => $this->isCancelled,
            'cancelled_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->cancelledAt),
            'exported_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->exportedAt),
            'notes' => $this->notes,
            'items' => array_map(fn($i) => $i instanceof SettlementItemDto ? $i->toArray() : $i, $this->items),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'created_by_admin_id' => $this->createdByAdminId,
            'created_by_admin_name' => $this->createdByAdminName,
            'transaction_count' => $this->transactionCount,
            'transaction_date_min' => \App\Shared\Utils\DateFormatter::toUtcIso($this->transactionDateMin),
            'transaction_date_max' => \App\Shared\Utils\DateFormatter::toUtcIso($this->transactionDateMax),
            'submitted_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->submittedAt),
            'submitted_by_admin_id' => $this->submittedByAdminId,
            'is_cancellable' => $this->isCancellable,
            // Three keys for one refusal: the English sentence every existing
            // consumer reads, plus the code and values the admin panel
            // translates it from (#757).
            'cancellation_blocked_reason' => $this->cancellationBlockedReason?->message,
            'cancellation_blocked_code' => $this->cancellationBlockedReason?->reason->value,
            'cancellation_blocked_params' => $this->cancellationBlockedReason?->params ?: null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_reversible' => $this->isReversible,
            'reversal_blocked_reason' => $this->reversalBlockedReason?->message,
            'reversal_blocked_code' => $this->reversalBlockedReason?->reason->value,
            'reversal_blocked_params' => $this->reversalBlockedReason?->params ?: null,
            'reversed_member_count' => $this->reversedMemberCount,
            'reversals' => array_map(
                static fn($r) => $r instanceof SettlementReversalDto ? $r->toArray() : $r,
                $this->reversals,
            ),
            'notifications' => array_map(
                static fn($n) => $n instanceof QueuedMailDto ? $n->toArray() : $n,
                $this->notifications,
            ),
            // Labelled UTC like the queue row it is copied from (#365) — the
            // two describe the same delivery, and a payload that spells one
            // instant two ways is a payload a reader has to reconcile.
            'announcements' => array_map(
                static fn(array $a) => [
                    ...$a,
                    'sent_at' => \App\Shared\Utils\DateFormatter::toUtcIso($a['sent_at'] ?? null),
                ],
                $this->announcements,
            ),
        ];
    }
}
