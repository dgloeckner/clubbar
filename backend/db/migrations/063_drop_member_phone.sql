-- =============================================================================
-- 063_drop_member_phone.sql — stop keeping a number nothing reads
-- =============================================================================
-- The phone number was collected in three places and read in none.
--
-- An applicant typed it into the onboarding page (`/register/`), it landed in
-- `pending_registrations.phone`, approval copied it into `members.phone`, and
-- there it stayed. The admin panel has no phone column, no phone field on the
-- member form and no phone in any export; the club's Anmeldung PDF has no
-- `telefon` field to draw it into, so it was never even printed on the paper
-- the member signs. The only screen that ever showed it again was the review
-- panel, for the length of one approval.
--
-- That is personal data held for no purpose, which Art. 5(1)(c) GDPR does not
-- allow and which this project's own privacy-by-default rule does not want. The
-- fix is not to hide the field — a column that exists gets written to again —
-- it is to stop having somewhere to put it.
--
-- ## What is lost
--
-- Every number already on file, in both tables. Deliberately and permanently:
-- a number retained because deleting it felt irreversible is exactly the
-- retention this migration exists to end. The rollback recreates the columns
-- empty; it cannot bring values back, and that is the correct behaviour rather
-- than a limitation.
--
-- Nothing else depends on either column. `members.phone` has no index, no
-- foreign key and no constraint, and is not part of the anonymization UPDATE
-- after this (it was one of the columns set to NULL there; with the column gone
-- there is nothing to clear).
--
-- Rollback: db/rollback/063_drop_member_phone.down.sql
-- =============================================================================

ALTER TABLE members DROP COLUMN phone;

ALTER TABLE pending_registrations DROP COLUMN phone;
