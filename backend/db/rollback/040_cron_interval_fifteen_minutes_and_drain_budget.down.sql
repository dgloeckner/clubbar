-- Rollback for migration 040: the two scheduler dials go back to what 029 left.
--
-- Order matters, and for the same reason migration 039's rollback documents:
-- `MODIFY COLUMN … ENUM(…)` is a replacement, and a row holding a value the new
-- list omits is silently truncated to the empty string rather than refused. An
-- installation that had declared `fifteen_minutes` would be left with an empty
-- string in a NOT NULL enum, which `CronInterval::fromDeclared()` would read as
-- the default — recoverable, but only by accident. So the value is rewritten to
-- the interval it had to be declared as before this migration existed.
--
-- That is a lie in the safe direction, and the only one available: `hourly` is
-- what the deployment docs told a 15-minute installation to declare until
-- #473, it makes every threshold conservative rather than tight, and
-- `QueueHealth::intervalDisagrees()` does not flag a cron faster than declared.

UPDATE mail_config SET cron_interval = 'hourly' WHERE cron_interval = 'fifteen_minutes';

ALTER TABLE mail_config
    MODIFY COLUMN cron_interval ENUM('hourly', 'daily') NOT NULL DEFAULT 'hourly'
        COMMENT 'Declared scheduler granularity; retry ladder and stall thresholds scale with it',
    DROP COLUMN drain_budget_seconds;
