-- =============================================================================
-- 026_mail_outbox_generalised.sql — the outbox stops being a settlement table
-- =============================================================================
-- Migration 025 shipped the outbox in the shape ADR-0038 describes it in:
-- `UNIQUE (kind, settlement_id, member_id)`, a settlement foreign key, and a
-- member as the only conceivable recipient. That was right for the
-- pre-notification and wrong for the queue.
--
-- #438 wants expiry warnings for encryption keys and terminal tokens, and each
-- of its three properties breaks that key:
--
--   about a credential, not a settlement   → `settlement_id` cannot hold it
--   addressed to an admin, not a member    → `member_id NOT NULL` cannot hold it
--   repeats per 90/30/7-day tier           → nothing in the key distinguishes them
--
-- The last one is the substance. A warning tier is recomputed on every admin
-- request for as long as the credential sits inside the window, so "queue a
-- warning" has to mean "queue it once" — which is precisely what a unique index
-- is for, and precisely what #438 calls "idempotent-notification storage" and
-- says it needs designing. It does not need designing; it needs a column.
--
-- ## What changes
--
--   settlement_id  → subject_id, and the foreign key goes
--                    Which table `subject_id` points at is decided by `kind`
--                    (`MailKind::subjectType()`), so there is exactly one source
--                    and no second column to disagree with it. The cost is
--                    stated plainly rather than hidden: a polymorphic foreign
--                    key cannot exist, so nothing at this level stops a row
--                    pointing at a settlement that was deleted. It costs little
--                    because the application never deletes a settlement —
--                    ADR-0032 makes it append-only and cancellation marks
--                    rather than removes — and a builder handed an orphan
--                    refuses to invent a message around the gap.
--
--   member_id      → nullable, and out of the unique key
--                    It keeps its foreign key. That is not decoration: it is how
--                    erasure (#408) finds the addresses this table holds, and
--                    the cascade that stops a member's mail outliving them.
--
--   + admin_user_id  nullable, foreign key, cascading
--   + dedup_key      NOT NULL, the rest of a message's identity
--
--   UNIQUE (kind, subject_id, dedup_key)
--       Every column in it is NOT NULL, and that is load-bearing rather than
--       tidy: in MySQL a NULL never equals a NULL, so a single nullable column
--       in a unique index silently stops it being unique at all. That is why
--       the recipient columns stay outside the key and the dedup key carries
--       the recipient itself where one is needed.
--
-- ## Backfill
--
-- Existing rows are announcements, so their dedup key is the member: that
-- reproduces `UNIQUE (kind, settlement_id, member_id)` exactly, and no existing
-- row can collide under the new key that did not collide under the old one.
--
-- The order below matters. The old unique index has to go before `member_id`
-- becomes nullable, the backfill has to happen before the new index is created,
-- and the settlement foreign key has to be dropped before the column it is on
-- is renamed.
--
-- Rollback: db/rollback/026_mail_outbox_generalised.down.sql
-- =============================================================================

-- 1. Release the constraints that pin the old shape.
ALTER TABLE mail_outbox DROP FOREIGN KEY fk_mail_outbox_settlement;
ALTER TABLE mail_outbox DROP INDEX uq_mail_outbox_message;
ALTER TABLE mail_outbox DROP INDEX idx_mail_outbox_settlement;

-- 2. Rename to what the column actually holds now.
ALTER TABLE mail_outbox
    CHANGE COLUMN settlement_id subject_id CHAR(36) NOT NULL
        COMMENT 'What this message is about; which table it points at is decided by kind';

-- 3. The rest of a message's identity. Defaulted so the ALTER can add it to
--    existing rows, then backfilled — never NULL, see the header.
ALTER TABLE mail_outbox
    ADD COLUMN dedup_key VARCHAR(64) NOT NULL DEFAULT '' AFTER subject_id;

-- 4. Every row that exists today is an announcement or its retraction, and for
--    those the member is what makes two messages about one settlement distinct.
UPDATE mail_outbox SET dedup_key = member_id WHERE dedup_key = '';

-- 5. A recipient is a member *or* an admin, so neither can be mandatory.
ALTER TABLE mail_outbox
    MODIFY COLUMN member_id CHAR(36) NULL,
    ADD COLUMN admin_user_id CHAR(36) NULL AFTER member_id;

ALTER TABLE mail_outbox
    ADD CONSTRAINT fk_mail_outbox_admin FOREIGN KEY (admin_user_id)
        REFERENCES admin_users(id) ON DELETE CASCADE;

-- 6. The kinds #438 and #410 will queue. Reserved now so that adding either
--    feature is code rather than another migration.
ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request',
    'key_expiry_warning',
    'terminal_token_expiry_warning'
) NOT NULL;

-- 7. The idempotency guarantee, in its general form.
ALTER TABLE mail_outbox
    ADD CONSTRAINT uq_mail_outbox_message UNIQUE (kind, subject_id, dedup_key);

-- 8. The settlement detail (#407) and the cancellation path read every message
--    about one subject.
CREATE INDEX idx_mail_outbox_subject ON mail_outbox (subject_id, status);
