<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;

/**
 * One message asking to be queued.
 *
 * A named object rather than seven positional arguments, because the two halves
 * of the row that matter — what the message is about, and what makes it
 * distinct from the next one about the same thing — are easy to transpose and
 * impossible to notice transposed: the result is not an error, it is a
 * duplicate announcement or a warning that never fires twice.
 *
 * `dedupKey` is the field to think about. It completes
 * `UNIQUE (kind, subject_id, dedup_key)`, so it has to name every dimension in
 * which two messages of the same kind about the same subject legitimately
 * differ, and nothing else:
 *
 * | Message | subjectId | dedupKey |
 * |---|---|---|
 * | Pre-notification | the settlement | the member — one announcement each |
 * | Cancellation notice | the settlement | the member |
 * | Key expiry warning (#438) | the encryption key | the tier and the admin — 90, 30 and 7 are three messages |
 *
 * Put too little in it and the second message is silently swallowed; put a
 * timestamp in it and every request queues another copy.
 */
final readonly class MailRequestDto
{
    public function __construct(
        public MailKind $kind,
        /** The settlement, key or terminal this is about — see {@see MailKind::subjectType()}. */
        public string $subjectId,
        /** The address the message goes to, snapshotted at enqueue. */
        public string $recipient,
        public MailLanguage $language = MailLanguage::German,
        /** Everything besides the subject that makes this message distinct. */
        public string $dedupKey = '',
        /** Set when the recipient is a member — the hook GDPR erasure uses (#408). */
        public ?string $memberId = null,
        /** Set when the recipient is an admin. */
        public ?string $adminUserId = null,
    ) {
        if (trim($subjectId) === '') {
            throw new \InvalidArgumentException('A queued message must say what it is about');
        }
        if (trim($recipient) === '') {
            throw new \InvalidArgumentException('A queued message must have a recipient');
        }
        // Not a style rule: an unattributed row cannot be found by erasure and
        // cannot be cleaned up when the person leaves, and both failures are
        // silent.
        if ($memberId === null && $adminUserId === null) {
            throw new \InvalidArgumentException(
                'A queued message must name the member or the admin it is addressed to, '
                . 'so erasure and cleanup can find it'
            );
        }
    }

    /** A message to a member about a settlement — the announcement and its retraction. */
    public static function forMember(
        MailKind $kind,
        string $settlementId,
        string $memberId,
        string $recipient,
        MailLanguage $language,
    ): self {
        return new self(
            kind: $kind,
            subjectId: $settlementId,
            recipient: $recipient,
            language: $language,
            // The member *is* the distinguishing dimension: one message each,
            // per settlement, per kind.
            dedupKey: $memberId,
            memberId: $memberId,
        );
    }

    /**
     * A message to an admin about a credential (#438).
     *
     * `$occasion` is what stops one warning from being every warning — the tier
     * for an expiry notice. It is combined with the admin so that two admins
     * each get told, and neither gets told twice.
     */
    public static function forAdmin(
        MailKind $kind,
        string $subjectId,
        string $adminUserId,
        string $recipient,
        MailLanguage $language,
        string $occasion,
    ): self {
        return new self(
            kind: $kind,
            subjectId: $subjectId,
            recipient: $recipient,
            language: $language,
            dedupKey: $occasion . ':' . $adminUserId,
            adminUserId: $adminUserId,
        );
    }
}
