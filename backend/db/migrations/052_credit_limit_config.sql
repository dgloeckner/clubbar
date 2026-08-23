-- =============================================================================
-- Migration 052: the credit limit stops being a compiled-in constant
-- =============================================================================
-- ADR-0047. The ceiling a member's Deckel may reach is enforced today (#24) —
-- the terminal blocks a checkout past it and warns from the band below — but
-- the number itself is a constant duplicated in two languages, in
-- `terminal-frontend/lib/config/app_config.dart` and in the backend class this
-- migration's module replaces. A club that wants a different ceiling needs a
-- rebuilt terminal binary and a backend deploy, released together, or the
-- dashboard and the Deckelauszug start naming a figure nobody is holding.
--
-- Two columns' worth of somewhere for that number to live:
--
--   credit_limit_config      — what the club sets: a default ceiling, and the
--                              share of it at which a member is warned
--   members.credit_limit_cents — what one member may run up instead
--
-- **Nothing changes on the day this runs.** The seeded row is exactly the
-- constants it replaces (10000 / 80), which is what makes this migration
-- deployable and verifiable before anything starts reading it.
--
-- **Why the member column is nullable with no default.** NULL means "follow
-- the club", and that is the whole point of a club-level default: raising it
-- must lift everyone who has not been deliberately excepted. A
-- `NOT NULL DEFAULT 10000` column would freeze each member at whatever the
-- default happened to be on the day they were created, so a club that later
-- raised its ceiling would lift nobody.
--
-- **Why zero is a value and not an absence.** `limitCents <= 0` already means
-- "not enforced" at the terminal, in `CreditLimitCheck`, and the Deckelauszug
-- already omits its limit sentence on it. So NULL and 0 are different answers —
-- NULL is "follow the club", 0 is a deliberate "no ceiling for this member" —
-- and the API rejects a negative ceiling rather than letting it pass the same
-- `<= 0` test by accident.
--
-- **Why the warning band is only here and not on members.** An override sets
-- one number, the ceiling; the band is always the same share of whatever
-- ceiling a member ends up with (ADR-0047 decision 4). One knob per member is
-- also what keeps the resolution rule to a single line on each side of the
-- wire, which is what makes duplicating it in Dart safe.
--
-- No index on `members.credit_limit_cents`: it is read one member at a time
-- through rows the sync and the dashboard already fetch, and the dashboard's
-- per-row ceiling is computed from a column it already selects. Nothing here
-- touches `idx_sync_combined (updated_at, deleted_at)`, so delta sync rides it
-- unchanged.
--
-- Rollback: db/rollback/052_credit_limit_config.down.sql
-- =============================================================================

CREATE TABLE credit_limit_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    default_limit_cents INT NOT NULL DEFAULT 10000
        COMMENT 'Club-wide ceiling in cents; 0 = no ceiling (ADR-0047)',
    warn_threshold_percent TINYINT UNSIGNED NOT NULL DEFAULT 80
        COMMENT 'Share of the effective ceiling from which a member is warned, 1-100 (ADR-0047)',
    updated_by_admin_id CHAR(36) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_credit_limit_config_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize singleton row with today's constants
INSERT INTO credit_limit_config (id, default_limit_cents, warn_threshold_percent) VALUES (1, 10000, 80);

ALTER TABLE members
    ADD COLUMN credit_limit_cents INT NULL
        COMMENT 'This member''s own ceiling in cents; NULL = follow the club default, 0 = unlimited (ADR-0047)'
        AFTER preferred_language;
