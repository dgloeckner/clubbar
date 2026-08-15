-- Rollback for migration 033: the durable per-member announcement record (#408).
--
-- Dropping this table loses nothing that pruning has not already taken — but
-- that is only true on an installation where pruning has not yet run. Once it
-- has, the outbox rows these were copied from are gone, and this table is the
-- only remaining record that a § 7 Abs. 3 announcement was made at all.
--
-- So: roll this back before the first drain run after 033, or accept that the
-- announcement evidence for anything older than the retention window does not
-- come back.

DROP TABLE IF EXISTS settlement_announcements;
