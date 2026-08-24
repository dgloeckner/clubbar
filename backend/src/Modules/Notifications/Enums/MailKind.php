<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use App\Modules\AdminUsers\Enums\AdminRole;

/**
 * What a queued message is (ADR-0038).
 *
 * The value is part of the outbox's uniqueness key —
 * `UNIQUE (kind, subject_id, dedup_key)` — which is why a settlement can carry
 * both an announcement and, later, a cancellation notice for the same member
 * without either displacing the other.
 *
 * A kind also decides three things about its row that are therefore not
 * columns: what `subject_id` points at ({@see subjectType()}), whether the
 * message is addressed to a member or to an admin ({@see addressesMember()}),
 * and — when it is addressed to an admin — which offices it is for
 * ({@see recipientRoles()}). All of them live here so there is one place to be
 * right.
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
     * Sent to every active `admin` account ({@see recipientRoles()}), including
     * whoever performed it. Its value is that it is out of band with respect to
     * the credential that would be compromised: an attacker holding one admin's
     * session, password and TOTP code clears the step-up on the mint path and
     * still cannot reach the other admins' inboxes.
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
     * **The message names no member.** That was originally forced: the fan-out
     * reached every active account regardless of role, including the
     * Getränkewart, and ADR-0045 invariant 5 says they gain no member data.
     * #633 removed the constraint — this kind is now addressed to `admin` and
     * `kassenwart`, mirroring the `TREASURY` route its dashboard alert sits on
     * ({@see recipientRoles()}) — and the design stayed, because it was never
     * only about the Getränkewart: {@see addressesClub()} sends a copy to the
     * club address, a list belonging to no account at all. So the mail carries
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
     * The near-limit digest: every member whose Deckel has reached the warning
     * band, what they owe, and the ceiling it is measured against — one mail,
     * on a cadence the club chooses (ADR-0047, migration 053).
     *
     * **The first kind that is a list rather than an event.** Every other kind
     * here reports one thing that happened to one subject; this reports a
     * standing condition across the membership, and that is what makes it an
     * aggregate instead of a fan-out. Queueing one message per member near
     * their limit would be the same information delivered as the pile of mail
     * a treasurer stops reading — and it would put member names into the queue,
     * where this design keeps them out of it entirely.
     *
     * So `subject_id` is the club's credit-limit configuration
     * ({@see MailSubject::CREDIT_LIMIT_CONFIG}, the singleton row `1`) and the
     * `dedup_key` is `<window>:<adminUserId>` — `2026-W35:8f3c…`. The unique
     * index then does the whole of the idempotency: a scheduler ticking every
     * fifteen minutes produces one digest per recipient per window, with no
     * lookup and therefore no race ({@see \App\Modules\Notifications\Domain\DigestWindow}).
     *
     * The names and amounts are **not** in the queue row. They are rebuilt from
     * live data when the drain renders the message, which is what ADR-0038
     * rule 5 asks of every builder and which matters more here than elsewhere:
     * a digest queued on Monday and sent on Tuesday should say what is true on
     * Tuesday, and a member who settled up in between should not be named at
     * all.
     */
    case CREDIT_LIMIT_DIGEST = 'credit_limit_digest';

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
            self::ADMIN_EMAIL_CHANGED,
            // Not a lifecycle event, and not for a Vorstand list. Who is near
            // their Deckel ceiling is operational detail with member names in
            // it, and routing it to a club-wide address would widen a report
            // the role model deliberately holds to two offices.
            self::CREDIT_LIMIT_DIGEST => false,
        };
    }

    /**
     * Which offices this kind's fan-out is addressed to (#633, ADR-0044).
     *
     * `AdminNotifier::warnAdmins()` used to write to **every active account**,
     * whatever it was for. That made the mail channel the one surface the role
     * model did not reach: an account holding only `getraenkewart` was told an
     * encryption key's fingerprint, which terminal credentials had just been
     * minted, and who had been promoted — every one of them behind an
     * `admin`-only route in the panel.
     *
     * The rule here is **mirror the grant on the surface the mail points at**,
     * so there is one source of truth about who may know a thing rather than a
     * second table of who-hears-what drifting alongside `RouteRoleMap`. Keys,
     * terminals and admin accounts are `admin`-only routes, so their mail is
     * `admin`-only. The Jugendschutz notice is the one that is not: its
     * dashboard alert is `TREASURY` ([#622](https://github.com/dgloeckner/clubbar/issues/622)),
     * because ADR-0045 names the Kassenwart as its recipient, and the mail
     * carries the same set.
     *
     * The Kassenwart is narrowed by the same rule rather than left where they
     * were: a terminal token's expiry and an encryption key's fingerprint are
     * as far outside a treasurer's remit as a stock keeper's.
     *
     * A member-addressed kind answers `[]` — nobody is fanned out to, because
     * the fan-out is not how it is sent. {@see \App\Modules\Notifications\Services\AdminNotifier::warnAdmins()}
     * refuses those kinds outright, before this is consulted.
     *
     * An explicit `match`, like the two methods around it: a kind added later
     * has to answer the question rather than inherit whichever answer the shape
     * of the existing ones happens to give it. Here the silent failure being
     * guarded against is a notice that reaches an office it was never meant
     * for, in the one channel that cannot be taken back.
     *
     * @return list<AdminRole>
     */
    public function recipientRoles(): array
    {
        return match ($this) {
            self::KEY_EXPIRY_WARNING,
            self::ENCRYPTION_KEY_REGISTERED,
            self::ENCRYPTION_KEY_ACTIVATED,
            self::ENCRYPTION_KEY_REVOKED,
            self::TERMINAL_TOKEN_EXPIRY_WARNING,
            self::TERMINAL_ANOMALY_WARNING,
            self::TERMINAL_TOKEN_ISSUED,
            self::ADMIN_ACCOUNT_CREATED,
            self::ADMIN_ROLE_CHANGED,
            // Never actually fanned out — it goes to one address, the one the
            // account was moved *away* from, taken from the outbox row's
            // snapshot rather than from `admin_users`. The answer is stated
            // anyway, because "which offices would this be for" has an answer
            // for it, and a kind whose delivery path changes must not silently
            // acquire a wider audience than the one somebody chose for it.
            self::ADMIN_EMAIL_CHANGED => [AdminRole::ADMIN],

            // The one kind whose surface is not `admin`-only, and it is not a
            // special case: it is the same rule applied to a different route.
            self::JUGENDSCHUTZ_VIOLATION => [AdminRole::ADMIN, AdminRole::KASSENWART],

            // The same rule, applied to the same route. This digest is the push
            // half of the dashboard's near-limit panel, `GET /api/admin/dashboard`
            // is TREASURY, and the Kassenwart is the office the epic names as
            // the reader — so the mail carries exactly that set. The
            // Getränkewart is outside it because member balances are, on every
            // surface.
            self::CREDIT_LIMIT_DIGEST => [AdminRole::ADMIN, AdminRole::KASSENWART],

            self::SEPA_PRENOTIFICATION,
            self::CANCELLATION_NOTICE,
            self::DECKEL_STATEMENT => [],
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
            self::CREDIT_LIMIT_DIGEST => MailSubject::CREDIT_LIMIT_CONFIG,
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
            self::JUGENDSCHUTZ_VIOLATION,
            self::CREDIT_LIMIT_DIGEST => false,
        };
    }
}
