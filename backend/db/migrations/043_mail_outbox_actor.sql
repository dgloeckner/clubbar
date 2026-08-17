-- =============================================================================
-- 043_mail_outbox_actor.sql — the outbox learns who acted, not only who was told
-- =============================================================================
-- ADR-0043 and ADR-0036 both shipped their admin-addressed notices with the
-- actor deliberately left out: `admin_user_id` is the recipient — `warnAdmins()`
-- fans one row out per active admin — and nothing else on the row said who had
-- actually minted the token or swapped the key. Both mechanics tables even said
-- so in as many words, and both messages sent the reader to the audit log
-- instead of guessing.
--
-- That was a real constraint, not a preference: ADR-0038 rule 5 renders a
-- message from current state at send time rather than a stored body, and the
-- actor is not part of "current state" — it is a fact about the moment the row
-- was queued, and nothing durable carried it forward to the drain.
--
-- This column is that durability. `TerminalsService::rotateToken()` /
-- `::createTerminal()` and `EncryptionKeyService::register()` /
-- `::activate()` / `::revoke()` already know the acting admin at enqueue time —
-- it is the `adminUserId` `AdminNotifier::warnAdmins()` already threads through
-- to the audit entry — so recording it alongside the row costs nothing new to
-- discover, only somewhere to put what was already known.
--
-- `ON DELETE SET NULL`, not `CASCADE` like the recipient's `admin_user_id`.
-- Deleting the admin who *was written to* rightly takes their queued mail with
-- them (migration 026). Deleting the admin who *acted* must not: the row is
-- still a real notice to a different, still-active recipient, and losing it
-- because the actor's account was later removed would silently blind the other
-- admins to a mint or a key swap that genuinely happened. Losing only the name
-- is the correct amount of damage.
--
-- Nullable for the same reason `admin_user_id` is: a hand-edited row, or a
-- future caller of `warnAdmins()` with no human actor, must still be able to
-- queue. Both mail builders already drop a blank line rather than print one, so
-- an absent actor degrades to exactly what shipped before this migration.
--
-- Rollback: db/rollback/043_mail_outbox_actor.down.sql
-- =============================================================================

ALTER TABLE mail_outbox
    ADD COLUMN actor_admin_user_id CHAR(36) NULL AFTER admin_user_id;

ALTER TABLE mail_outbox
    ADD CONSTRAINT fk_mail_outbox_actor FOREIGN KEY (actor_admin_user_id)
        REFERENCES admin_users(id) ON DELETE SET NULL;
