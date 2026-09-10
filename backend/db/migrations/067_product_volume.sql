-- =============================================================================
-- 067_product_volume.sql — a product's size becomes data
-- =============================================================================
-- Product names carry their size inline today — `Weizenbier (0,5l)`, `Pils
-- 0,5L`, `Bier 0,5 l`. There has never been a field to put it in, so it went
-- into the only field there was: `names`, which is translated JSON. The size is
-- therefore spelled several ways, cannot be sorted or compared, and reaches an
-- English member with German punctuation.
--
-- `volume_ml` gives it a column of its own: language-neutral whole millilitres,
-- formatted for each reader at the edge (ADR-0056).
--
-- ## NULL, not 0
--
-- NULL means the product has no size at all — a Sauna-Token, a Kaffee, a
-- Portion Nüsse. That is not the same as a size of zero, which is why the
-- column is nullable and why nothing casts it. `min_age` (049) reads the same
-- way for the same reason.
--
-- ## Nothing is backfilled
--
-- This migration adds the column and stops. It does not try to parse `(0,5l)`
-- out of any name: a regex cannot tell a size from a price, cannot decide what
-- an English translation should become, and would mangle a minority of products
-- silently — onto a bar terminal that members read. Admins set the volume and
-- shorten the name in one save, so no product is ever half-renamed
-- (ADR-0056, decision 2).
--
-- ## The bound
--
-- 10 000 ml is a sanity check rather than a business rule: past any glass and
-- past most kegs sold by the unit, so a larger value is a typo — litres entered
-- where millilitres were asked for, or a digit too many. It is enforced by the
-- API (`gte:1`, `lte:10000`); the column itself only refuses a negative one.
--
-- Rollback: db/rollback/067_product_volume.down.sql
-- =============================================================================

ALTER TABLE products
    ADD COLUMN volume_ml INT UNSIGNED NULL
        COMMENT 'Product size in whole millilitres; NULL = no size (ADR-0056)'
        AFTER min_age;
