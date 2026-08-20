-- =============================================================================
-- 049_age_restrictions.sql — Jugendschutz gets somewhere to live
-- =============================================================================
-- ADR-0045. The bar serves alcohol and JuSchG § 9 sets hard limits on who may
-- be handed what; until now nothing in the schema could express either half of
-- that sentence. Two columns, one on each side of the check:
--
--   members.date_of_birth — the age, as a raw date
--   products.min_age      — the limit, as a number the Getränkewart sets
--
-- **Why the raw date and not an age.** Age changes on a day the server cannot
-- predict a sync for. The terminal is offline-first (ADR-0033) and decides from
-- a cache that may be weeks old, so a precomputed age in years is wrong from
-- the member's next birthday until the next sync — and it is wrong about
-- exactly the member who is about to notice. Only the raw date is correct on
-- every future day with the network unplugged.
--
-- **Why NULL is allowed on a field the API requires.** `date_of_birth` is
-- `required` in the create rules and nullable in the column, which is the shape
-- `first_name` and `last_name` already have. The split is load-bearing: the
-- rule is what removes the "unknown age, allow anyway" branch from the terminal
-- check, and the nullable column is what lets GDPR erasure clear it —
-- `MembersRepository::anonymize()` nulls every column that says something about
-- the human, and OLG Dresden 4 U 1278/21 puts the date of birth squarely among
-- them (ADR-0029). So a NULL birth date means exactly one thing: **an
-- anonymized member**, who is inactive and cannot book anyway.
--
-- **Why `min_age` is a free integer and not an enum.** JuSchG's 16 and 18 are
-- German law; this is self-hosted open source. A Dutch club needs 18 on
-- everything and no 16 at all. TINYINT UNSIGNED holds 0–255; the API bounds it
-- to 1–99. NULL means unrestricted, which is the overwhelming majority of a
-- drinks list and must not require an assertion.
--
-- **No index on either column.** `date_of_birth` is never filtered or sorted
-- on — it is read one member at a time, through the row the sync already
-- fetches. `min_age` is filtered client-side by the terminal from its own
-- cache. Neither touches `idx_sync_combined (updated_at, deleted_at)`, so delta
-- sync rides it unchanged.
--
-- Rollback: db/rollback/049_age_restrictions.down.sql
-- =============================================================================

ALTER TABLE members
    ADD COLUMN date_of_birth DATE NULL
        COMMENT 'Member date of birth; NULL means anonymized (ADR-0045)'
        AFTER phone;

ALTER TABLE products
    ADD COLUMN min_age TINYINT UNSIGNED NULL
        COMMENT 'Minimum legal age to buy this product; NULL = unrestricted (ADR-0045)'
        AFTER requires_dispenser;
