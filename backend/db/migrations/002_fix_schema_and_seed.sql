-- =============================================================================
-- 002_fix_schema_and_seed.sql
-- =============================================================================
-- Fixes schema mismatches from Laravel-to-Slim migration:
--   1. Rename admin_users.password_hash → password (matches Slim repository code)
--   2. Recreate sessions table for PHP native sessions (not Laravel session driver)
--   3. Seed test admin user and terminal for development/testing
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Fix admin_users: rename password_hash → password
-- ---------------------------------------------------------------------------
ALTER TABLE admin_users CHANGE COLUMN password_hash password VARCHAR(255) NOT NULL;

-- ---------------------------------------------------------------------------
-- 2. Fix sessions table: replace Laravel session driver schema with
--    Slim-compatible schema (used by SessionRepository)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS sessions;

CREATE TABLE sessions (
    id VARCHAR(255) NOT NULL,
    admin_user_id CHAR(36) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sessions_admin_user_id (admin_user_id),
    INDEX idx_sessions_last_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Seed test admin user (idempotent - only inserts if not exists)
--    Email: admin@example.com
--    Password: password123
--    Hash generated with: password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12])
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO admin_users (id, email, password, display_name, locale, is_active, created_at, updated_at)
VALUES (
    '33e4567-e89b-12d3-a456-426614174000',
    'admin@example.com',
    '$2y$12$b4DD2dnVYgoYYWgNAQi8eORC2K0RjqBDAQT2NnDDkvCHZy0mSNUuy',
    'Admin User',
    'de',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 4. Seed test terminal (idempotent - only inserts if not exists)
--    Device ID: test-device-001
--    Token: test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h
--    Hash generated with: password_hash(token, PASSWORD_BCRYPT, ['cost' => 12])
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO terminals (id, name, device_id, api_token_hash, is_active, created_at, updated_at)
VALUES (
    '44e4567-e89b-12d3-a456-426614174000',
    'Test Terminal',
    'test-device-001',
    '$2y$12$o.N/tfiOnSEskNn6J8tuc.4bEX6r6.LETlkEMWy73lrjtiXVZ3vuq',
    1,
    NOW(),
    NOW()
);
