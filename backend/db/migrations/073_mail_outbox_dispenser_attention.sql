-- =============================================================================
-- 073 — mail_outbox learns that a dispenser can need a human (#956)
--
-- The push half of the Terminals page (#952–#955, ADR-0057 / ADR-0058). Until
-- now the four failures a token dispenser has — jammed, unreachable, answering
-- a protocol the terminal does not speak, running out of tokens — were visible
-- only to an admin who happened to open the panel. A jam on a Friday evening
-- was found by the next member who wanted a token (epic finding 17).
--
-- ## One kind, two dedup anchors
--
-- The `dedup_key` carries the occasion *and its episode*, and there are two
-- kinds of episode, which is the whole reason this is worth a paragraph in a
-- migration:
--
--   * a fault is keyed on the report's `state_since`, which moves only when the
--     dispenser's own state changes — so an hour-long jam is one notice;
--   * a shortage is keyed on `terminals.dispenser_refilled_at`, because a
--     draining hopper changes nothing about what the terminal reports. Keyed on
--     `state_since` it would warn once per terminal and then never again.
--
-- `UNIQUE (kind, subject_id, dedup_key)` is what turns both into one message
-- per admin per episode, without a lookup and without a race.
--
-- Its subject is the **terminal** (`MailSubject::TERMINAL`): the dispenser has
-- no row of its own — ADR-0057 stores its status in two columns on `terminals`
-- — and the terminal is what an admin looks up.
--
-- Rollback: db/rollback/073_mail_outbox_dispenser_attention.down.sql
-- =============================================================================

-- Restating the whole list, as every predecessor did: MariaDB has no ADD VALUE,
-- and a MODIFY COLUMN is a replacement rather than an amendment. Omitting an
-- existing value here is how one is removed, so the list is the interface and
-- it has to be read as one.
ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'key_expiry_warning',
    'terminal_token_expiry_warning',
    'terminal_anomaly_warning',
    'terminal_token_issued',
    'admin_email_changed',
    'deckel_statement',
    'encryption_key_registered',
    'encryption_key_activated',
    'encryption_key_revoked',
    'admin_account_created',
    'admin_role_changed',
    'admin_invitation',
    'jugendschutz_violation',
    'credit_limit_digest',
    'backup_secret_expiry_warning',
    'backup_health_warning',
    'member_welcome',
    'member_card_replaced',
    'member_email_changed',
    'member_email_activated',
    'registration_link',
    'admin_password_changed',
    'admin_totp_enrolled',
    'admin_totp_reset',
    'dispenser_attention'
) NOT NULL;

-- `settlement_announcements` deliberately does NOT gain this value, for the
-- reason 039 first gave and every migration since has repeated: that table is
-- the durable proof that a § 7 Abs. 3 announcement was made about a settlement.
-- A notice that a hopper is empty announces nothing and collects nothing.
