-- Rollback for migration 049: the Jugendschutz columns go away.
--
-- Dropping them removes the club's ability to enforce JuSchG § 9 at all — every
-- product becomes unrestricted and no member has an age. Nothing degrades
-- gracefully here: the terminal simply stops refusing, silently, because a
-- product with no `min_age` is indistinguishable from an unrestricted one.
--
-- The member birth dates are destroyed with the column and cannot be recovered
-- from anywhere else in the system. Take a dump first if the rollback is being
-- run on anything but a scratch database.

ALTER TABLE products DROP COLUMN min_age;
ALTER TABLE members DROP COLUMN date_of_birth;
