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
 *
 * **There is no `payment_request`.** It was reserved for #410 — a mail asking a
 * member to transfer their Deckel when SEPA could not collect it — and removed
 * with migration `036` when the issue was closed unbuilt: a `bank_transfer`
 * settlement is the record of money that *already arrived* (ADR-0032 §4), so
 * such a mail would ask for it twice. Nothing in this system asks a member to
 * send money; see **Settlement method** in CONTEXT.md.
 */
enum MailKind: string
{
    /** The SEPA pre-notification: creditor ID, mandate reference, amount, due date, statement. */
    case SEPA_PRENOTIFICATION = 'sepa_prenotification';

    /** „Einzug entfällt" — sent only to members whose announcement actually went out. */
    case CANCELLATION_NOTICE = 'cancellation_notice';

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

    /**
     * ADR-0043: a terminal credential was just minted — a device enrolled, or
     * an existing terminal's token rotated.
     *
     * The mirror of {@see TERMINAL_TOKEN_EXPIRY_WARNING}: that one reports on a
     * secret running out, this one reports that a secret came into existence.
     * Issuance was the only credential event in the system that created
     * something and told nobody, and the audit entry it left is *pull* —
     * somebody has to decide to go and look.
     *
     * Sent to every active admin, including whoever performed it. Its value is
     * that it is out of band with respect to the credential that would be
     * compromised: an attacker holding one admin's session, password and TOTP
     * code clears the step-up on the mint path and still cannot reach the other
     * admins' inboxes.
     *
     * The **event and the token's generation** live in the `dedup_key`, as
     * `enrolled:<stamp>` or `rotated:<stamp>` — an occasion rather than a tier,
     * because issuance happens once instead of staying true for thirty days,
     * and two rotations of one terminal are two things to be told about
     * ({@see \App\Modules\Notifications\Services\TerminalTokenIssuedMailBuilder::occasion()}).
     */
    case TERMINAL_TOKEN_ISSUED = 'terminal_token_issued';

    /**
     * "Your login address was changed", sent to the address it was changed
     * *from* — the one place the change is visible to someone who did not make
     * it. An attacker holding a session can move the address; this is what
     * reaches the real owner, at the only address they still control.
     *
     * The recipient is therefore not derived from `admin_users` at send time,
     * as every other kind's is: by then the row holds the new address. It comes
     * from the outbox row's `recipient` snapshot, which is exactly the guarantee
     * that column exists to give.
     */
    case ADMIN_EMAIL_CHANGED = 'admin_email_changed';

    /**
     * ADR-0039: the periodic Deckelauszug — a member's tab as it stood at a
     * calendar boundary, sent whatever it says, collecting nothing.
     *
     * The first kind whose subject is the **member** rather than a thing that
     * happened to them, and the first that is triggered by time passing rather
     * than by somebody doing something. Neither needed anything new from the
     * queue: the period goes in `dedup_key`, and the unique index then makes a
     * scan that runs every hour produce one statement a month without ever
     * asking whether it already had.
     */
    case DECKEL_STATEMENT = 'deckel_statement';

    /** What `subject_id` refers to for this kind. */
    public function subjectType(): MailSubject
    {
        return match ($this) {
            self::SEPA_PRENOTIFICATION,
            self::CANCELLATION_NOTICE => MailSubject::SETTLEMENT,
            self::KEY_EXPIRY_WARNING => MailSubject::ENCRYPTION_KEY,
            self::TERMINAL_TOKEN_EXPIRY_WARNING,
            self::TERMINAL_ANOMALY_WARNING,
            self::TERMINAL_TOKEN_ISSUED => MailSubject::TERMINAL,
            self::ADMIN_EMAIL_CHANGED => MailSubject::ADMIN_USER,
            self::DECKEL_STATEMENT => MailSubject::MEMBER,
        };
    }

    /**
     * Does this go to a member, or to whoever runs the club?
     *
     * Money mail is addressed to the member it collects from. Operational
     * warnings are addressed to an admin — a member has no way to act on an
     * expiring encryption key, and telling them about one would leak that the
     * club's credentials are in a state worth mentioning.
     *
     * This used to read `subjectType() === MailSubject::SETTLEMENT`, which was
     * true of every kind that existed and false of the first one that did not:
     * a Deckelauszug is addressed to a member and is about that member. It is
     * an explicit `match` rather than an added `||` so the next kind has to
     * answer the question instead of inheriting whichever answer the shape of
     * the existing ones happens to give it — the failure being guarded against
     * is silent, and it is a message sent to the wrong sort of person.
     */
    public function addressesMember(): bool
    {
        return match ($this) {
            self::SEPA_PRENOTIFICATION,
            self::CANCELLATION_NOTICE,
            self::DECKEL_STATEMENT => true,
            self::KEY_EXPIRY_WARNING,
            self::TERMINAL_TOKEN_EXPIRY_WARNING,
            self::TERMINAL_ANOMALY_WARNING,
            self::TERMINAL_TOKEN_ISSUED,
            self::ADMIN_EMAIL_CHANGED => false,
        };
    }
}
