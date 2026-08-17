-- =============================================================================
-- 044_admin_user_roles.sql — an admin account gains a role, and loses nothing
-- =============================================================================
-- ADR-0044 replaces ADR-0015's single sentence "all admin users have full
-- access" with three roles: `admin`, `kassenwart`, `getraenkewart`. This is the
-- foundation for that — storage only. No route is enforced against it yet
-- (#519 does that), deliberately, so this migration is deployable and
-- verifiable while everybody's access is still exactly what it was yesterday.
--
-- A relation, not a `role` column on `admin_users`. ADR-0044 §4 rejects
-- one-role-per-user on the grounds that a volunteer organisation covers for
-- each other: a Kassenwart minding the drinks list while the Getränkewart is on
-- holiday must be able to hold both roles rather than share a login, which is
-- what single-role models reliably degrade into.
--
-- The role vocabulary is an ENUM, not a lookup table with rows an admin can
-- add to. ADR-0044 alternative 2 rejects permissions-as-data: three hardcoded
-- roles are greppable, diffable in review, and cannot be recombined at runtime
-- into a fourth role nobody reviewed. A new role is a migration and a
-- conversation, which is the correct price for one.
--
-- `ON DELETE CASCADE`: a grant is meaningless without the account it names, and
-- an orphan would silently re-attach to whoever next held that id.
--
-- The backfill is the whole point of the file. **Every existing row becomes
-- `admin`**, because `admin` is a strict superset of what every account holds
-- today — nobody logs in the next morning to find something missing. ADR-0044
-- §Migration also rejects the clever version outright: a migration that
-- inferred `kassenwart` from "who last ran a settlement" would remove access on
-- a guess, and the first symptom of a wrong guess is a treasurer stuck
-- mid-collection with no explanation. Demotion is a deliberate, audited,
-- per-person act performed afterwards by a human who has looked at the person.
--
-- `INSERT IGNORE ... SELECT` rather than a plain INSERT: it adds and never
-- removes, so re-running it over accounts that already hold roles is a no-op on
-- those rows rather than a duplicate-key failure. That is what makes "this
-- migration cannot take access away" a property a test can execute rather than
-- read.
--
-- `db/seed.sql` inserts its admin *after* migrations run, so the backfill never
-- sees it; the seed grants its own role for the same reason the installer does.
--
-- Rollback: db/rollback/044_admin_user_roles.down.sql
-- =============================================================================

CREATE TABLE admin_user_roles (
    admin_user_id CHAR(36) NOT NULL,
    role ENUM('admin', 'kassenwart', 'getraenkewart') NOT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (admin_user_id, role),
    CONSTRAINT fk_admin_user_roles_admin_user FOREIGN KEY (admin_user_id)
        REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_user_roles (admin_user_id, role)
    SELECT id, 'admin' FROM admin_users;
