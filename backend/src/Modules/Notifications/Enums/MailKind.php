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
     * A key moved through its lifecycle: registered, put in force, or revoked.
     *
     * Not a warning about time passing but a report that somebody acted, and
     * the reason it leaves by mail is that everything else about a key swap
     * stays inside the panel. The audit log records it and nothing reads the
     * audit log; the dashboard alert only speaks when a key is missing or
     * expiring, so a freshly activated key with 365 days left is silent by
     * construction — and it names the key by `key_identifier`, which whoever
     * registered it chose. An admin who did not perform the action learns
     * nothing at all.
     *
     * Mail is the one channel somebody holding an admin session cannot
     * suppress, which is the same argument {@see self::ADMIN_EMAIL_CHANGED}
     * makes about a stolen session moving a login address. The fingerprint
     * travels in full, because comparing it against the offline generator is
     * the only check that distinguishes the club's key from someone else's.
     *
     * Three kinds rather than one with a state field: they are three different
     * messages — "there is a new key on file", "it is now sealing your
     * members' bank details", "a key was withdrawn" — and the subject line is
     * what gets read.
     */
    case ENCRYPTION_KEY_REGISTERED = 'encryption_key_registered';
    case ENCRYPTION_KEY_ACTIVATED = 'encryption_key_activated';
    case ENCRYPTION_KEY_REVOKED = 'encryption_key_revoked';

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

    /**
     * A sale slipped past a stale terminal and was served to a minor
     * (#622, [ADR-0045](../../../adr/0045-age-restricted-products.md) §3).
     *
     * The audit entry M7 writes is the **record**; this is only how a human
     * finds out about it. Without it the record is pull-only — somebody has to
     * already suspect a violation to go and filter the audit log for one — and
     * the log is `admin`-only under ADR-0044, so the Kassenwart, the office the
     * epic names as the recipient, could not see it at all.
     *
     * **The message names no member.** `AdminNotifier` fans out to every active
     * admin account regardless of role, which includes the Getränkewart — and
     * ADR-0045 invariant 5 says they gain no member data. So the mail carries
     * the drink, the age it required, the terminal and the transaction id, and
     * the reader resolves the rest in a surface their own role permits. Rule 6
     * binds here as much as at the till: name what the *drink* required, never
     * what the member is.
     *
     * The **transaction id is the subject**, so the occasion only has to
     * distinguish this violation from another about the same sale — which
     * cannot happen, since M7 flags once per inserted row. It carries the
     * required age instead, `age:18`, which pins the limit as it stood at the
     * sale into the message and keeps invariant 4 true of the mail as well as
     * of the record.
     */
    case JUGENDSCHUTZ_VIOLATION = 'jugendschutz_violation';

    /**
     * A new admin account exists (ADR-0044 rule 3).
     *
     * ADR-0044 calls account creation the *loud* path — the one that fires
     * lifecycle mail, against which promoting an existing lesser role was the
     * quiet one. It was loud only in intention until this kind existed.
     *
     * Goes to every active admin **and** to the club-level address, which is
     * the half that matters: with exactly one admin, a message about an account
     * being created otherwise goes from the actor, to the actor, about a thing
     * they just did — silent in precisely the case where that one account is
     * the compromised one.
     */
    case ADMIN_ACCOUNT_CREATED = 'admin_account_created';

    /**
     * An account's roles moved (ADR-0044 rule 2: granting a role is minting
     * authority).
     *
     * The out-of-band half of the same control the step-up and the
     * `ROLE_GRANTED` / `ROLE_REVOKED` audit rows provide: whoever holds one
     * admin's session, password and TOTP code clears the step-up on the grant
     * path and still cannot reach the other admins' inboxes, or the club's.
     *
     * One kind for both directions, unlike the audit actions — a reader of the
     * *queue* wants "the roles on this account changed, go and look"; a reader
     * of the *log* is answering "who gained admin last quarter". The occasion
     * carries the direction, so two changes to one account are two messages.
     */
    case ADMIN_ROLE_CHANGED = 'admin_role_changed';

    /**
     * Does a club-level copy of this go out alongside the admins' (ADR-0044
     * rule 3)?
     *
     * Only admin **lifecycle** events. Not the operational warnings — a
     * Vorstand list has nothing to do about an expiring terminal token, and
     * routing every warning there turns the one channel that must be read into
     * one that is filtered. The point is a second pair of eyes on *who gained
     * power*, which is the event a compromised sole admin would otherwise be
     * the only witness to.
     *
     * An explicit `match` for the same reason `addressesMember()` is one: the
     * next kind has to answer the question rather than inherit an answer from
     * the shape of the existing ones.
     */
    public function addressesClub(): bool
    {
        return match ($this) {
            self::ADMIN_ACCOUNT_CREATED,
            self::ADMIN_ROLE_CHANGED,
            // Not an admin lifecycle event, and the one deliberate exception to
            // the rule above. JuSchG § 28 exposure lands on the club and on
            // whoever served, so the Vorstand is a party here rather than a
            // bystander — and a violation should be rare enough never to become
            // the noise this method otherwise guards against.
            self::JUGENDSCHUTZ_VIOLATION => true,
            self::SEPA_PRENOTIFICATION,
            self::CANCELLATION_NOTICE,
            self::DECKEL_STATEMENT,
            self::KEY_EXPIRY_WARNING,
            self::ENCRYPTION_KEY_REGISTERED,
            self::ENCRYPTION_KEY_ACTIVATED,
            self::ENCRYPTION_KEY_REVOKED,
            self::TERMINAL_TOKEN_EXPIRY_WARNING,
            self::TERMINAL_ANOMALY_WARNING,
            self::TERMINAL_TOKEN_ISSUED,
            self::ADMIN_EMAIL_CHANGED => false,
        };
    }

    /** What `subject_id` refers to for this kind. */
    public function subjectType(): MailSubject
    {
        return match ($this) {
            self::SEPA_PRENOTIFICATION,
            self::CANCELLATION_NOTICE => MailSubject::SETTLEMENT,
            self::KEY_EXPIRY_WARNING,
            self::ENCRYPTION_KEY_REGISTERED,
            self::ENCRYPTION_KEY_ACTIVATED,
            self::ENCRYPTION_KEY_REVOKED => MailSubject::ENCRYPTION_KEY,
            self::TERMINAL_TOKEN_EXPIRY_WARNING,
            self::TERMINAL_ANOMALY_WARNING,
            self::TERMINAL_TOKEN_ISSUED => MailSubject::TERMINAL,
            self::ADMIN_EMAIL_CHANGED,
            self::ADMIN_ACCOUNT_CREATED,
            self::ADMIN_ROLE_CHANGED => MailSubject::ADMIN_USER,
            self::DECKEL_STATEMENT => MailSubject::MEMBER,
            self::JUGENDSCHUTZ_VIOLATION => MailSubject::TRANSACTION,
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
            self::ENCRYPTION_KEY_REGISTERED,
            self::ENCRYPTION_KEY_ACTIVATED,
            self::ENCRYPTION_KEY_REVOKED,
            self::TERMINAL_TOKEN_EXPIRY_WARNING,
            self::TERMINAL_ANOMALY_WARNING,
            self::TERMINAL_TOKEN_ISSUED,
            self::ADMIN_EMAIL_CHANGED,
            self::ADMIN_ACCOUNT_CREATED,
            self::ADMIN_ROLE_CHANGED,
            self::JUGENDSCHUTZ_VIOLATION => false,
        };
    }
}
