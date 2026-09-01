-- Rollback for migration 059: self-registration goes away entirely.
--
-- `pending_registrations` holds people who applied and were never reviewed.
-- Dropping it discards them irrecoverably — they exist nowhere else, by design:
-- a pending registration is not a member, so nothing was copied anywhere. Anyone
-- in the queue at this moment would have to scan the poster and apply again.
--
-- `self_registration_config` takes the poster secret with it. Every printed QR
-- code stops working, and re-enabling later means generating a new secret and
-- reprinting.
--
-- Take a dump first if this is being run on anything but a scratch database.

DROP TABLE IF EXISTS registration_attempts;
DROP TABLE IF EXISTS pending_registrations;
DROP TABLE IF EXISTS self_registration_config;
