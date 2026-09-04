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
    /**
     * The singleton `self_registration_config` row, as `subject_id`.
     *
     * A literal because there is exactly one registration surface per
     * installation and no id to look up — the same shape the backup warnings
     * use for `instance_config`. It is also the `entity_id` the audit entries
     * on that surface already carry, so the queue and the log name the same
     * thing.
     */
    public const SELF_REGISTRATION_SUBJECT_ID = 'self_registration_config';

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
        /**
         * Who performed the action this message announces — distinct from
         * {@see $adminUserId}, which is who the row is addressed to. Null when the
         * action had no human actor, or for kinds that predate this field.
         */
        public ?string $actorAdminUserId = null,
        /**
         * True for the club-level copy of an admin lifecycle notice (ADR-0044
         * rule 3) — the one kind of row addressed to nobody in particular.
         * Never persisted: it exists so the constructor can tell "addressed to
         * the club" apart from "somebody forgot to attribute this".
         */
        public bool $addressedToClub = false,
        /**
         * True for the Anmeldelink (#821, ADR-0053) — a message to somebody who
         * is not a member, not an admin, and not the club. Never persisted, and
         * a separate flag from {@see $addressedToClub} rather than a reuse of
         * it: the two are both "no id columns" and are otherwise nothing
         * alike, and collapsing them would let a club-level notice be queued to
         * a stranger's address by passing the wrong boolean.
         */
        public bool $addressedToProspect = false,
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
        //
        // A club-addressed row (ADR-0044 rule 3) is the one legitimate case
        // with neither, and it says so rather than slipping through: the
        // recipient is an address the club configured, belonging to no person
        // at all, so there is nobody for erasure to find and nothing for a
        // cascade to clean up. It carries no member data by construction — the
        // lifecycle kinds are about an *admin account* — and it is deliberately
        // outside migration 026's `admin_user_id` cascade, because a notice
        // about an account that was created and then deleted is exactly what
        // the club-level copy exists to preserve.
        if ($memberId === null && $adminUserId === null && !$addressedToClub && !$addressedToProspect) {
            throw new \InvalidArgumentException(
                'A queued message must name the member or the admin it is addressed to, '
                . 'so erasure and cleanup can find it'
            );
        }

        if ($addressedToClub && ($memberId !== null || $adminUserId !== null)) {
            throw new \InvalidArgumentException(
                'A club-addressed message belongs to no person; naming one would put it '
                . 'inside a cascade it must survive'
            );
        }

        // Same shape, different reason. A prospect has no row to name because
        // nothing about them is stored (ADR-0053); an id here would mean the
        // caller is queueing something else entirely and has reached for the
        // wrong constructor.
        if ($addressedToProspect && ($memberId !== null || $adminUserId !== null || $addressedToClub)) {
            throw new \InvalidArgumentException(
                'An Anmeldelink is addressed to somebody this database holds no row for; '
                . 'naming a member, an admin or the club contradicts that'
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
     * A message to a member about their own record — an onboarding, a card
     * replacement, or one end of an address change.
     *
     * Like {@see forStatement()}, `subjectId` and `memberId` carry the same
     * value for the same two reasons, and for the same reason neither may be
     * dropped. What differs is the dedup key, which is why this is a
     * constructor of its own rather than a `$kind` argument on that one: a
     * statement is distinguished by the period it states, and these are
     * distinguished by an **occasion** — a moment for the things that happen
     * more than once, and the constant `welcome` for the thing that must not.
     *
     * `$recipient` is passed explicitly and is not always `members.email`. The
     * address-change pair splits on exactly this: one copy goes to the address
     * the member is losing, which by the time this is called is no longer in
     * the members row at all, and the snapshot column is the only thing that
     * still knows it.
     */
    public static function forMemberOccasion(
        MailKind $kind,
        string $memberId,
        string $recipient,
        MailLanguage $language,
        string $occasion,
    ): self {
        return new self(
            kind: $kind,
            subjectId: $memberId,
            recipient: $recipient,
            language: $language,
            dedupKey: $occasion,
            memberId: $memberId,
        );
    }

    /**
     * A periodic statement to a member about their own tab (ADR-0039).
     *
     * The first message whose subject is the recipient. `subjectId` and
     * `memberId` therefore carry the same value and are both set anyway: the
     * first is what {@see MailKind::subjectType()} says the row is about, the
     * second is the column erasure and the delete cascade key on, and collapsing
     * them would break one of those two jobs.
     *
     * `$period` is the dedup key, and it is the entire idempotency of a
     * time-triggered enqueue: one statement per member per period, enforced by
     * `UNIQUE (kind, subject_id, dedup_key)` rather than by a scan that
     * remembers what it already did.
     */
    public static function forStatement(
        string $memberId,
        string $recipient,
        MailLanguage $language,
        string $period,
    ): self {
        return new self(
            kind: MailKind::DECKEL_STATEMENT,
            subjectId: $memberId,
            recipient: $recipient,
            language: $language,
            dedupKey: $period,
            memberId: $memberId,
        );
    }

    /**
     * A message to an admin about a credential (#438).
     *
     * `$occasion` is what stops one warning from being every warning — the tier
     * for an expiry notice. It is combined with the admin so that two admins
     * each get told, and neither gets told twice.
     *
     * `$actorAdminUserId` is who performed the action, not who this particular
     * copy is addressed to — every active admin gets a row with the same actor,
     * including the actor's own row.
     */
    /**
     * A copy of an admin lifecycle message for the club-level address
     * (ADR-0044 rule 3).
     *
     * `adminUserId` is deliberately null: the recipient is an address the club
     * configured, not an account. That has a consequence worth stating —
     * migration 026's `ON DELETE CASCADE` on `admin_user_id` takes a queued
     * row with the admin it is addressed to, and this row has no such owner, so
     * it survives the deletion of whoever it was about. Which is right: "this
     * account was created and then removed before anyone read about it" is
     * exactly the sequence the club-level copy exists to witness.
     *
     * The dedup key ends in `:club` rather than an account id, so the club copy
     * neither collides with an admin's nor silences one.
     */
    public static function forClub(
        MailKind $kind,
        string $subjectId,
        string $recipient,
        MailLanguage $language,
        string $occasion,
        ?string $actorAdminUserId = null,
    ): self {
        return new self(
            kind: $kind,
            subjectId: $subjectId,
            recipient: $recipient,
            language: $language,
            dedupKey: $occasion . ':club',
            actorAdminUserId: $actorAdminUserId,
            addressedToClub: true,
        );
    }

    /**
     * The invitation link for one new admin (migration 058).
     *
     * Its own constructor because it is the one admin-addressed message whose
     * **recipient is its subject**: it goes to the account being onboarded,
     * about that account. {@see forAdmin()} appends the recipient's id to the
     * occasion so that two admins each get told and neither gets told twice —
     * here that dimension does not exist, and appending it is not merely
     * redundant but *wrong*: `<uuid>:<uuid>` is 73 characters and `dedup_key`
     * is VARCHAR(64), so the row is refused by the database. That failure is
     * swallowed by the caller (an unqueued notice must never fail the thing it
     * announces), which is exactly how a silently un-mailed invitation would
     * reach production.
     *
     * The invitation's own id is therefore the whole key. It is what makes a
     * resend a second message rather than one the unique index swallows — a
     * resend exists precisely because the first did not arrive — and it is
     * what the mail builder reads back to find the token it has to unseal.
     */
    public static function forInvitation(
        string $adminUserId,
        string $recipient,
        string $invitationId,
        MailLanguage $language,
        ?string $actorAdminUserId = null,
    ): self {
        return new self(
            kind: MailKind::ADMIN_INVITATION,
            subjectId: $adminUserId,
            recipient: $recipient,
            language: $language,
            dedupKey: $invitationId,
            adminUserId: $adminUserId,
            actorAdminUserId: $actorAdminUserId,
        );
    }

    /**
     * The club's registration link, to somebody thinking of joining
     * (#821, ADR-0053).
     *
     * The one constructor here that names nobody: `memberId` and `adminUserId`
     * are both null because this person has no row in this database and — if
     * they never join — never will. That is the design rather than a gap in it,
     * and it is why {@see $addressedToProspect} exists to say so out loud.
     *
     * **`$nonce` is a per-send value, so dedup is effectively off**, and that
     * inverts what `dedupKey` does everywhere else in this class. The unique
     * index exists so a repeating *scan* is idempotent — a digest must not send
     * twice for one window, an expiry warning must not re-fire each tick. There
     * is no scan here: a human types an address and clicks send, and clicking
     * again is the intent, not an accident to swallow. A key of the bare
     * address would refuse precisely the re-send that answers "I never got it",
     * silently, from the database, with a 204 on the wire. The double click is
     * guarded in the UI, which is where the mistake actually happens.
     *
     * The subject is the registration surface itself — a singleton, like the
     * config row the backup warnings file under — because what this message is
     * *about* is the club's open door, not the person walking through it.
     */
    public static function forProspect(
        string $recipient,
        MailLanguage $language,
        string $nonce,
    ): self {
        return new self(
            kind: MailKind::REGISTRATION_LINK,
            subjectId: self::SELF_REGISTRATION_SUBJECT_ID,
            recipient: $recipient,
            language: $language,
            dedupKey: $nonce,
            addressedToProspect: true,
        );
    }

    public static function forAdmin(
        MailKind $kind,
        string $subjectId,
        string $adminUserId,
        string $recipient,
        MailLanguage $language,
        string $occasion,
        ?string $actorAdminUserId = null,
    ): self {
        return new self(
            kind: $kind,
            subjectId: $subjectId,
            recipient: $recipient,
            language: $language,
            dedupKey: $occasion . ':' . $adminUserId,
            adminUserId: $adminUserId,
            actorAdminUserId: $actorAdminUserId,
        );
    }
}
