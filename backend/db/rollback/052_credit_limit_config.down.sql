-- Rollback for migration 052: the credit limit goes back to being a constant.
--
-- Dropping `credit_limit_config` costs the club nothing it cannot retype — the
-- code falls back to the shipped defaults (10000 / 80), which is the same
-- fail-soft path a deployment between `composer install` and the migration
-- takes.
--
-- `members.credit_limit_cents` is different: **every per-member override is
-- destroyed with the column and exists nowhere else in the system.** There is
-- no derivation that recovers them and no audit trail that reconstructs the
-- current value. Take a dump first if this is being run on anything but a
-- scratch database.

ALTER TABLE members DROP COLUMN credit_limit_cents;
DROP TABLE credit_limit_config;
