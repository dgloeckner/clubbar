-- Rollback for migration 069: the counter and the prefix go away.
--
-- References already minted are NOT affected — they live in `mandates.reference`
-- and `pending_registrations.mandate_reference`, which this rollback does not
-- touch. That is the point: they are on signed paper and in collections already
-- sent to the bank, and a return is matched by `MREF+` months later.
--
-- What is lost is the club's chosen prefix and the high-water mark. Re-running
-- 069 afterwards seeds the counter back at 0, so the next mint would collide
-- with references already handed out. Note the current `value` before rolling
-- back if the install has ever minted one.
--
-- Code from before 069 mints from a UUID again and reads neither object.

ALTER TABLE sepa_config DROP COLUMN mandate_reference_prefix;
DROP TABLE mandate_reference_counter;
