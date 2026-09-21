-- Rollback for migration 070: stop recording what each terminal's dispenser said.
--
-- Nothing else holds a copy, and nothing needs one: every terminal re-files its
-- whole status on its next sync cycle, so re-applying 070 refills the column
-- within minutes rather than needing a dump. The one thing that does not come
-- back is `state_since` — an episode's start is re-stamped to the first report
-- after the column returns, so a fault that has been running for a week reads
-- as new. That is a cosmetic loss on a display-only field, and the alternative
-- (keeping the column to preserve it) is not a rollback.

ALTER TABLE terminals
    DROP COLUMN dispenser_status_at,
    DROP COLUMN dispenser_status;
