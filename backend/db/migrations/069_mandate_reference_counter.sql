-- =============================================================================
-- 069_mandate_reference_counter.sql — a mandate reference a member can read out
-- =============================================================================
-- The mandate reference (UMR) is the one identifier a member sees on their own
-- Kontoauszug and reads aloud to the Kassenwart when a collection is queried,
-- and it is printed on the paper a self-registering member signs. ADR-0006
-- minted it from a UUID with the hyphens removed — 32 hex characters, which is
-- unreadable in both places.
--
-- SEPA only requires the UMR to be unique **per creditor** (max 35 chars, SEPA
-- charset). One install is one Gläubiger-ID, so a single counter row is all the
-- uniqueness that is owed, and `CB-000042` is what a member gets instead.
--
-- ## Why a counter row and not CREATE SEQUENCE
--
-- A native sequence exists only on MariaDB >= 10.3, and `docs/deployment.md`
-- promises MySQL 5.7 / MariaDB 10.5 — on mass hosting the database version is
-- the host's decision (ADR-0038; `MailOutboxRepository::claimBatch` refuses
-- `SKIP LOCKED` for exactly the same reason). A sequence is also
-- non-transactional, whereas this row rolls back with the transaction that
-- drew from it, so the paper printed from a pending registration and the
-- database can never name different numbers.
--
-- The portable draw is the classic one, run inside the minting transaction:
--
--     UPDATE mandate_reference_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1;
--     SELECT LAST_INSERT_ID();
--
-- `LAST_INSERT_ID()` is connection-scoped, so two overlapping requests each read
-- their own number. Gaps are harmless: a rejected or purged registration takes
-- its number with it and nothing goes looking for it.
--
-- ## Nothing existing is re-minted
--
-- Mandate rows are append-only and the reference is on signed paper and in
-- every collection already sent to the bank — a return is matched by `MREF+`
-- months later. Only newly opened mandates get the new form; a mixed population
-- of 32-hex and short references is fine for the bank. This migration therefore
-- touches no `mandates` row at all.
--
-- ## The prefix
--
-- `sepa_config.mandate_reference_prefix` lets a club pick its own (default
-- `CB`). It exists mainly so that references an admin types in by hand for
-- mandates carried over from a previous system cannot collide with the club's
-- own sequence.
--
-- Rollback: db/rollback/069_mandate_reference_counter.down.sql
-- =============================================================================

CREATE TABLE mandate_reference_counter (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    value BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Highest mandate reference number handed out so far (ADR-0006)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The singleton row, the same shape as sepa_config's. The minter refuses to
-- mint rather than inventing a number when this row is missing, because
-- LAST_INSERT_ID() after an UPDATE that matched nothing still returns a value.
INSERT INTO mandate_reference_counter (id, value) VALUES (1, 0);

ALTER TABLE sepa_config
    ADD COLUMN mandate_reference_prefix VARCHAR(10) NULL
        COMMENT 'Prefix for newly minted mandate references; NULL = CB (ADR-0006)'
        AFTER payment_reference_prefix;
