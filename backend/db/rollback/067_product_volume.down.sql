-- Rollback for migration 067: the volume column goes away.
--
-- Every volume an admin has entered is destroyed with the column, and it cannot
-- be recovered from anywhere else — decision 2 of ADR-0056 means the value was
-- never derived from the name, so the name does not still carry it. A club that
-- has done the renaming pass (set the volume, shorten the name) is left with
-- names that no longer say what size anything is.
--
-- Take a dump first if this is being run on anything but a scratch database.
--
-- Code from before 067 reads the remaining columns unchanged: the field was
-- additive on the wire, so an older backend and an older terminal never
-- referred to it.

ALTER TABLE products DROP COLUMN volume_ml;
