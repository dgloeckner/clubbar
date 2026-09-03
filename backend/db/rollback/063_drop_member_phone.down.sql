-- Rollback for migration 063: give the phone columns back, empty.
--
-- The values are gone. This restores the shape of the schema so that code from
-- before migration 063 can run against it again; it does not restore any
-- number, because 063 dropped them and nothing else in this system held a copy.
--
-- Restore from a dump if the values themselves are needed.

ALTER TABLE members
    ADD COLUMN phone VARCHAR(20) NULL AFTER email;

ALTER TABLE pending_registrations
    ADD COLUMN phone VARCHAR(20) NULL AFTER email;
