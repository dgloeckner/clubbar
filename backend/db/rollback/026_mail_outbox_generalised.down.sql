-- Rollback for migration 026: the outbox stops being a settlement table (ADR-0038)
--
-- This one can lose data, and says so rather than pretending otherwise: any
-- message whose subject is not a settlement, or whose recipient is an admin,
-- has nowhere to go in the old shape. Those rows are deleted first, because the
-- alternative is a NOT NULL member_id that cannot be satisfied and an ALTER
-- that fails halfway.
--
-- Nothing enqueues such a message today, so on a current installation the DELETE
-- matches nothing.

DELETE FROM mail_outbox
 WHERE kind IN ('key_expiry_warning', 'terminal_token_expiry_warning')
    OR member_id IS NULL;

ALTER TABLE mail_outbox DROP INDEX uq_mail_outbox_message;
DROP INDEX idx_mail_outbox_subject ON mail_outbox;

ALTER TABLE mail_outbox DROP FOREIGN KEY fk_mail_outbox_admin;
ALTER TABLE mail_outbox DROP COLUMN admin_user_id;
ALTER TABLE mail_outbox DROP COLUMN dedup_key;

ALTER TABLE mail_outbox MODIFY COLUMN member_id CHAR(36) NOT NULL;

ALTER TABLE mail_outbox MODIFY COLUMN kind ENUM(
    'sepa_prenotification',
    'cancellation_notice',
    'payment_request'
) NOT NULL;

ALTER TABLE mail_outbox
    CHANGE COLUMN subject_id settlement_id CHAR(36) NOT NULL;

ALTER TABLE mail_outbox
    ADD CONSTRAINT uq_mail_outbox_message UNIQUE (kind, settlement_id, member_id),
    ADD CONSTRAINT fk_mail_outbox_settlement FOREIGN KEY (settlement_id)
        REFERENCES settlements(id) ON DELETE CASCADE;

CREATE INDEX idx_mail_outbox_settlement ON mail_outbox (settlement_id, status);
