-- =============================================================================
-- 029_cron_interval_and_batch_size.sql — the scheduler's *interval* becomes a fact
-- =============================================================================
-- ADR-0039 decision 5, amending ADR-0031. ADR-0038 accepted a hard dependency
-- on the host having *a* scheduler; every number downstream then quietly
-- assumed the 5–15 minute interval `bin/cron.php` recommends. Panel crons in
-- practice offer hourly at best, sometimes only daily — and at that granularity
-- a flat fifteen-minute retry backoff is dead letter and an age-based stall
-- alarm cries wolf.
--
-- So the interval stops being an assumption and becomes a declared, verified
-- fact — declared here, cross-checked against what the heartbeat observes
-- (`previous_run_at` below), and reported by the self-check when the two
-- disagree. Declaration alone cannot catch a crontab that says hourly and fires
-- daily; observation alone has nothing to go on before the second run.
--
-- ## Why the enum has two values and not three
--
-- `weekly` is refused rather than supported. ADR-0038 argues the § 7 Abs. 3
-- announcement distance is guaranteed *at enqueue*, because the execution date
-- is already seven days out. At a weekly drain that argument fails: a message
-- enqueued an hour after a tick leaves up to seven days later and lands **on**
-- the execution date, taking the promised distance to roughly zero. A host that
-- cannot keep the promise must not pretend to, so the value simply does not
-- exist — the API refuses it with that reasoning by name, and the column could
-- not store it even if the API let it through.
--
-- ## drain_batch_size
--
-- Was `MAIL_DRAIN_BATCH_SIZE` in the environment, which means a club on a
-- stricter relay had to edit a file to lower it. It is an operational dial with
-- no secret in it, so it belongs with the rest of the club-editable mail
-- settings. 100 per tick is ~2400/day at hourly — a monthly statement run for a
-- 300-member club clears in one tick. The environment variable still overrides
-- it, for CI and for a host where the value has to be pinned outside the
-- database.
--
-- ## previous_run_at
--
-- One row cannot describe a gap. Keeping the run before the current one turns
-- the singleton into the smallest thing that can answer "how far apart do runs
-- actually arrive?" — which is the whole of the cross-check above. It is not a
-- history table on purpose: two timestamps answer the question, and a growing
-- log of cron ticks is exactly the kind of table nobody prunes.
--
-- Rollback: db/rollback/029_cron_interval_and_batch_size.down.sql
-- =============================================================================

ALTER TABLE mail_config
    -- Default `hourly` rather than `daily`: it is what the supported tariffs
    -- offer, and being wrong in this direction schedules retries that are too
    -- eager rather than a ladder the cron is never awake for.
    ADD COLUMN cron_interval ENUM('hourly', 'daily') NOT NULL DEFAULT 'hourly'
        COMMENT 'Declared scheduler granularity; retry ladder and stall thresholds scale with it'
        AFTER logo_url,
    ADD COLUMN drain_batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 100
        COMMENT 'Messages claimed per drain round; lower it for a stricter relay'
        AFTER cron_interval;

ALTER TABLE cron_heartbeat
    ADD COLUMN previous_run_at DATETIME NULL
        COMMENT 'The run before last_run_at; the gap between them is the observed interval'
        AFTER last_run_at;
