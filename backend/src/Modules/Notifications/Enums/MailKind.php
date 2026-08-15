<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What a queued message is (ADR-0038).
 *
 * The value is part of the outbox's uniqueness key —
 * `UNIQUE (kind, subject_id, dedup_key)` — which is why a settlement can carry
 * both an announcement and, later, a cancellation notice for the same member
 * without either displacing the other.
 *
 * A kind also decides two things about its row that are therefore not columns:
 * what `subject_id` points at ({@see subjectType()}), and whether the message
 * is addressed to a member or to an admin ({@see addressesMember()}). Both live
 * here so there is one place to be right.
 */
enum MailKind: string
{
    /** The SEPA pre-notification: creditor ID, mandate reference, amount, due date, statement. */
    case SEPA_PRENOTIFICATION = 'sepa_prenotification';

    /** „Einzug entfällt" — sent only to members whose announcement actually went out. */
    case CANCELLATION_NOTICE = 'cancellation_notice';

    /**
     * Reserved for #410: `bank_transfer` settlements need a payment request
     * (amount, club bank details, payment reference) and explicitly **no**
     * mandate reference or creditor ID. Nothing enqueues this yet.
     */
    case PAYMENT_REQUEST = 'payment_request';

    /**
     * Reserved for #438: an encryption key approaching expiry, at one of the
     * 90/30/7-day tiers ADR-0036 already computes for the dashboard.
     *
     * The tier belongs in `dedup_key`, not in a separate kind per tier: "warn
     * at 90 days" and "warn at 30 days" are two messages about one key, and the
     * unique index is what makes each of them fire once rather than once per
     * request that happens to notice the key is still inside the window.
     */
    case KEY_EXPIRY_WARNING = 'key_expiry_warning';

    /** Reserved for #438: the same, for a terminal token (ADR-0036). */
    case TERMINAL_TOKEN_EXPIRY_WARNING = 'terminal_token_expiry_warning';

    /**
     * ADR-0041: a terminal's credential looks like it is on more than one
     * device — two addresses active at once, or a sync cursor that does not
     * continue the history this token has been building.
     *
     * The anomaly, not the terminal, is what makes a message distinct: the
     * `dedup_key` carries a slice of the anomaly id, so an ongoing condition
     * mails once while a genuinely new one mails again.
     */
    case TERMINAL_ANOMALY_WARNING = 'terminal_anomaly_warning';

    /** What `subject_id` refers to for this kind. */
    public function subjectType(): MailSubject
    {
        return match ($this) {
            self::SEPA_PRENOTIFICATION,
            self::CANCELLATION_NOTICE,
            self::PAYMENT_REQUEST => MailSubject::SETTLEMENT,
            self::KEY_EXPIRY_WARNING => MailSubject::ENCRYPTION_KEY,
            self::TERMINAL_TOKEN_EXPIRY_WARNING,
            self::TERMINAL_ANOMALY_WARNING => MailSubject::TERMINAL,
        };
    }

    /**
     * Does this go to a member, or to whoever runs the club?
     *
     * Money mail is addressed to the member it collects from. Operational
     * warnings are addressed to an admin — a member has no way to act on an
     * expiring encryption key, and telling them about one would leak that the
     * club's credentials are in a state worth mentioning.
     */
    public function addressesMember(): bool
    {
        return $this->subjectType() === MailSubject::SETTLEMENT;
    }
}
