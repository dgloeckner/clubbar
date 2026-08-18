-- Rollback for migration 044: role storage leaves the schema again.
--
-- Dropping the table drops every grant with it, which is safe precisely
-- because nothing enforces roles yet (#519 is the slice that starts reading
-- them). Before enforcement, an account's access is what it always was —
-- rolling back returns the system to "every admin has full access" rather than
-- to "nobody can do anything".
--
-- Rolling back *after* enforcement ships would be a different act: it removes
-- the only record of who was demoted, and re-running migration 044 afterwards
-- re-grants `admin` to everybody. That is the deliberate, safe direction for a
-- rollback to fail in, but it is not a no-op.

DROP TABLE IF EXISTS admin_user_roles;
