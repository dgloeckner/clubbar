<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum AuditAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case ACTIVATE = 'activate';
    case DEACTIVATE = 'deactivate';
    case REORDER = 'reorder';
    case ANONYMIZE = 'anonymize';
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case LOGIN_FAILED = 'login_failed';
    case EXPORT = 'export';
    /** A booking reversed in full (#169). The only admin-initiated transaction. */
    case TRANSACTION_STORNO = 'transaction_storno';
    case SETTLEMENT_CREATE = 'settlement_create';
    case SETTLEMENT_CANCEL = 'settlement_cancel';
    case SETTLEMENT_EXPORT = 'settlement_export';
    /** The exported file reached the bank — the point cancellation ends (#81). */
    case SETTLEMENT_SUBMIT = 'settlement_submit';
    /** Money that already moved has come back — per member (#196, ruling #148). */
    case SETTLEMENT_REVERSE = 'settlement_reverse';
    /** A bank return stopped the next run re-debiting this member (#196 §3). */
    case COLLECTION_HOLD_PLACED = 'collection_hold_placed';
    /** An admin released that member back into the next run (#196 §5). */
    case COLLECTION_HOLD_CLEARED = 'collection_hold_cleared';
    case TOTP_ENROLLED = 'totp_enrolled';
    case TOTP_RESET = 'totp_reset';

    /**
     * A self-service password change. Distinct from the plain `update` a
     * display-name edit produces, and from the cross-account reset — an
     * investigation asks "when did this account's password change", and the
     * answer should be one filter rather than a scan of every admin-user
     * update's payload (ADR-0013).
     */
    case PASSWORD_CHANGED = 'password_changed';

    /**
     * A change to `admin_users.email`. The email is the login identifier, so
     * this records a change to who can sign in — never merely a contact detail.
     */
    case EMAIL_CHANGED = 'email_changed';
    case MANDATE_DOCUMENT_UPLOAD = 'mandate_document_upload';
    case MANDATE_DOCUMENT_DELETE = 'mandate_document_delete';
    // IBAN encryption key lifecycle (ADR-0036). The private key never touches
    // the server's storage; these events record who moved a key through which
    // state, and how many rows a rotation batch touched — never key material.
    case KEY_REGISTERED = 'key_registered';
    case KEY_ACTIVATED = 'key_activated';
    case KEY_ROTATION_STARTED = 'key_rotation_started';
    case KEY_ROTATION_BATCH_COMPLETED = 'key_rotation_batch_completed';
    case KEY_ROTATION_COMPLETED = 'key_rotation_completed';
    case KEY_RETIRED = 'key_retired';
    case KEY_REVOKED = 'key_revoked';
    case KEY_MARKED_COMPROMISED = 'key_marked_compromised';
    /** A bulk decryption happened: pain.008 built from sealed IBANs (ADR-0036). */
    case SEPA_EXPORT = 'sepa_export';
    /** Privileged full-IBAN display — the exceptional revision case (ADR-0036). */
    case IBAN_FULL_VIEW = 'iban_full_view';
    /** A terminal resumed sync after its instance_id pairing mismatched (ADR-0035, #380). */
    case TERMINAL_REPAIR = 'terminal_repair';
    // Terminal credential lifecycle (ADR-0036 §Terminal tokens, #395). Token
    // material never appears in the payloads — only which terminal moved
    // through which state, when, and at whose hand.
    /** A terminal was enrolled and its first token issued, already active. */
    case TERMINAL_TOKEN_CREATED = 'terminal_token_created';
    /** A pending token was used for the first time and promoted to active. */
    case TERMINAL_TOKEN_ACTIVATED = 'terminal_token_activated';
    /** An admin issued a replacement token; it starts pending, alongside the old one. */
    case TERMINAL_TOKEN_ROTATED = 'terminal_token_rotated';
    /** An admin withdrew a terminal's credentials outright. */
    case TERMINAL_TOKEN_REVOKED = 'terminal_token_revoked';
    /** A terminal presented a token that had aged out — written once per expiry. */
    case TERMINAL_TOKEN_EXPIRED = 'terminal_token_expired';
    // Outgoing mail (ADR-0038). Written at *enqueue*, not at send: enqueue is
    // the moment the club commits to announcing a collection, and it is the
    // only one that shares the settlement's transaction. Whether a given
    // message left the host is queue state, visible per member in the
    // settlement detail — best effort, and never audited as a promise kept.
    /** Announcements or cancellation notices were queued for a settlement. */
    case MAIL_ENQUEUED = 'mail_enqueued';
    /** Unsent announcements were closed out because the settlement was cancelled. */
    case MAIL_SUPERSEDED = 'mail_superseded';
    /**
     * An admin put a failed message back in the queue (#407).
     *
     * The one queue transition with a person behind it — everything else in
     * this table is the drain recording what a mail server answered. Worth an
     * entry for the same reason the enqueue is: somebody decided that a
     * member's announcement should be attempted again.
     */
    case MAIL_RETRIED = 'mail_retried';
    /**
     * An admin sent themselves a test mail to check the transport (#407).
     *
     * Audited because it is the one place in the application that opens an SMTP
     * connection from a web request rather than from the scheduler. It carries
     * no member data and goes only to the requesting admin's own address — and
     * the record is what makes that checkable afterwards rather than asserted.
     */
    case MAIL_TEST_SENT = 'mail_test_sent';
    // Terminal credential anomalies (ADR-0041). Observed by the cron tick, so
    // the detection entry carries no actor; the acknowledgement carries the
    // admin who decided the alert had been dealt with. Neither changes the
    // token — this whole path alerts and never enforces.
    /** Concurrent use or a cursor discontinuity was observed for a terminal. */
    case TERMINAL_ANOMALY_DETECTED = 'terminal_anomaly_detected';
    /** An admin marked a terminal anomaly as seen, clearing it from the panel. */
    case TERMINAL_ANOMALY_ACKNOWLEDGED = 'terminal_anomaly_acknowledged';
    /**
     * An admin generated a new URL-trigger secret from the panel (#473).
     *
     * Carries no secret material — only that a rotation happened, who did it,
     * and when. The old secret (and `config.php`'s, if that is what was still
     * authorising the route) stops working the moment this is written.
     */
    case CRON_SECRET_ROTATED = 'cron_secret_rotated';
}
