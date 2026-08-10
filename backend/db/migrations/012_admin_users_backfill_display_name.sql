-- =============================================================================
-- 012_admin_users_backfill_display_name.sql — backfill NULL display_name (#330)
-- =============================================================================
-- `display_name` is nullable in the schema but `install.php` never set it when
-- creating the wizard's first admin, so every fresh install carried a NULL
-- value there. `AdminUserDto` mapped that column straight into a non-nullable
-- `string`, so `GET /api/admin/admin-users` threw a TypeError on any install
-- built by the installer and the admin-user list returned 500 for every user,
-- including the one who just finished the wizard.
--
-- install.php now sets display_name = email for new installs (see #330), and
-- AdminUserDto falls back to email when the column is NULL or empty, so the
-- endpoint no longer breaks. This backfills existing installs whose admin
-- users already carry the NULL value, so the fix reaches them without a
-- manual SQL edit.
--
-- Rollback: db/rollback/012_admin_users_backfill_display_name.down.sql
-- =============================================================================

UPDATE admin_users
   SET display_name = email
 WHERE display_name IS NULL OR display_name = '';
