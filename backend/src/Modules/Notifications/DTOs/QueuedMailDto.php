<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;

/**
 * One queued message, as the Kassenwart reads it (#407).
 *
 * ADR-0038 rule 6 makes delivery best effort *and never invisible*. This is the
 * "never invisible" half: what was queued, for whom, whether it left, and — when
 * it did not — the reason in the words the receiving server used.
 *
 * ### Why the recipient address is here at all
 *
 * It is the one field on an outbox row that is not reproducible from anywhere
 * else: `members.email` can change or be erased, and the snapshot is the proof
 * of who was actually written to. Showing it is the point of the row — *"the
 * announcement went to the old address"* is a diagnosis a name alone cannot
 * give. Every reader of this DTO is an authenticated admin who can already see
 * the member list.
 *
 * ### What is deliberately absent
 *
 * No body. Nothing stores one (ADR-0038 rule 5), so the queue view cannot show
 * "what was sent" and must not pretend to — the content is reproduced from
 * settlement data at send time, and the preview script is where it is read.
 */
final readonly class QueuedMailDto
{
    public function __construct(
        public string $id,
        public MailKind $kind,
        public string $subjectId,
        public ?string $memberId,
        public ?string $memberName,
        public ?string $adminUserId,
        public ?string $adminName,
        public string $recipient,
        public string $language,
        public MailStatus $status,
        public int $attempts,
        public ?string $lastError,
        public string $queuedAt,
        public ?string $nextAttemptAt,
        public ?string $sentAt,
    ) {}

    /** @param array<string,mixed> $row A joined `mail_outbox` row. */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            kind: MailKind::from((string) $row['kind']),
            subjectId: (string) $row['subject_id'],
            memberId: self::nullable($row['member_id'] ?? null),
            memberName: self::name($row['member_first_name'] ?? null, $row['member_last_name'] ?? null),
            adminUserId: self::nullable($row['admin_user_id'] ?? null),
            adminName: self::nullable($row['admin_display_name'] ?? null),
            recipient: (string) $row['recipient'],
            language: (string) ($row['language'] ?? 'de'),
            status: MailStatus::from((string) $row['status']),
            attempts: (int) ($row['attempts'] ?? 0),
            lastError: self::nullable($row['last_error'] ?? null),
            queuedAt: (string) $row['queued_at'],
            nextAttemptAt: self::nullable($row['next_attempt_at'] ?? null),
            sentAt: self::nullable($row['sent_at'] ?? null),
        );
    }

    /**
     * Can this row be put back in the queue?
     *
     * Only a `failed` one. A `sent` message has reached somebody and resending
     * it is not a retry; a `pending` one is already going to be tried; a
     * `superseded` one belongs to a collection that was called off, and
     * reviving it would announce a debit that is not happening.
     *
     * Answered here rather than left to the UI so the button and the endpoint
     * cannot disagree about which rows are eligible.
     */
    public function isRetryable(): bool
    {
        return $this->status === MailStatus::FAILED;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'subject_id' => $this->subjectId,
            'member_id' => $this->memberId,
            'member_name' => $this->memberName,
            'admin_user_id' => $this->adminUserId,
            'admin_name' => $this->adminName,
            'recipient' => $this->recipient,
            'language' => $this->language,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
            'queued_at' => $this->queuedAt,
            'next_attempt_at' => $this->nextAttemptAt,
            'sent_at' => $this->sentAt,
            'is_retryable' => $this->isRetryable(),
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The member's name for display, or null when the join found nobody.
     *
     * Null is the ordinary answer for an anonymised member (ADR-0029 clears the
     * name and keeps the booking history), and the row still has to render —
     * with the snapshot address, which is what proves the announcement was
     * made.
     */
    private static function name(mixed $first, mixed $last): ?string
    {
        $name = trim(trim((string) ($first ?? '')) . ' ' . trim((string) ($last ?? '')));

        return $name === '' ? null : $name;
    }
}
