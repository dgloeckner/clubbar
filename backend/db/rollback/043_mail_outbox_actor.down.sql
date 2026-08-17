-- Rollback for migration 043: the actor column leaves the schema again.
--
-- The FK has to go before the column it constrains. No data loss beyond the
-- column itself: the recipient, the kind and everything a message renders from
-- stays exactly as it was — this only forgets who acted, which is what shipped
-- before this migration existed.

ALTER TABLE mail_outbox DROP FOREIGN KEY fk_mail_outbox_actor;
ALTER TABLE mail_outbox DROP COLUMN actor_admin_user_id;
