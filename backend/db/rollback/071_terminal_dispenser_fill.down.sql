-- Rollback for migration 071: stop estimating how full the hopper is.
--
-- The estimate itself is computed on read, so nothing derived is lost — but the
-- anchor is. `dispenser_refilled_at` and `dispenser_refill_tokens` are the only
-- record that a refill ever happened: the device's counters are cumulative and
-- RAM-only, and no report history is kept (ADR-0057). Dropping these columns
-- therefore does not merely hide the estimate, it deletes the fact it was built
-- from, and re-applying 071 leaves every terminal without an estimate until
-- somebody counts a hopper again.
--
-- `dispenser_low_threshold` is a setting and goes back to its default, which is
-- the ordinary cost of a rollback and is why the default is a usable value
-- rather than a placeholder.

ALTER TABLE terminals
    DROP COLUMN dispenser_low_threshold,
    DROP COLUMN dispenser_refill_tokens,
    DROP COLUMN dispenser_refilled_at;
