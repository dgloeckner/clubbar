-- =============================================================================
-- 066_canonical_card_uid.sql — one spelling per chip
-- =============================================================================
-- `members.card_uid` is matched by exact string comparison, so the *spelling* a
-- UID was stored in decides whether the card works. Until now the only rule was
-- `^[0-9A-F]+$` between 8 and 20 characters, which admits two spellings of one
-- chip:
--
--   * lower case, if a value ever reached the row around the admin form (the
--     terminal's own cache had the same problem — issue #18);
--   * an odd number of digits, which is half a written byte and means a leading
--     zero was dropped somewhere between the card and the keyboard. `01EB4CB`
--     and `001EB4CB` are the same card, stored as two different members' worth
--     of value.
--
-- The canonical spelling is uppercase hex in whole bytes — `001EB4CB` — and
-- from this migration on it is the only one the API will write
-- (`App\Shared\Utils\CardUid`). This brings the rows that predate it into line.
--
-- ## What is deliberately not touched
--
--   * `ANON-…`, the placeholder an anonymized member carries (ADR-0017). It is
--     not hex and must never be rewritten into something card-shaped.
--   * Anything else that is not hex. Nothing should be there, and a migration
--     is the wrong place to find out what a value nobody recognises meant.
--   * Any value whose canonical form is already taken by another member. Adding
--     the missing zero would collide with the `UNIQUE` index and abort the
--     whole migration; the two rows are left as they are and the collision is
--     for a person to resolve, since only they know which member is holding
--     which card.
-- =============================================================================

-- 1. Case. A no-op under the schema's case-insensitive collation for the
--    comparison, but the stored bytes are what the terminal caches and what an
--    export shows, so they are made canonical too.
UPDATE members
   SET card_uid = UPPER(card_uid)
 WHERE card_uid IS NOT NULL
   AND card_uid REGEXP '^[0-9a-fA-F]+$'
   AND CAST(card_uid AS BINARY) <> CAST(UPPER(card_uid) AS BINARY);

-- 2. The dropped leading zero. Joined against the table itself so a value whose
--    padded form already belongs to somebody is skipped rather than aborting
--    the migration on the UNIQUE index.
UPDATE members m
  LEFT JOIN members other
         ON other.card_uid = CONCAT('0', m.card_uid)
   SET m.card_uid = CONCAT('0', m.card_uid)
 WHERE m.card_uid IS NOT NULL
   AND m.card_uid REGEXP '^[0-9A-F]+$'
   AND LENGTH(m.card_uid) % 2 = 1
   AND LENGTH(m.card_uid) < 20
   AND other.id IS NULL;
