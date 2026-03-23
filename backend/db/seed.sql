-- =============================================================================
-- seed.sql — Development & testing seed data
-- =============================================================================
-- Run after migrations to populate test data for local development and E2E tests.
-- NOT included in the shared hosting package — production starts with empty tables.
--
-- Usage:
--   docker compose exec database mysql -uclubbar -pclubbar clubbar < backend/db/seed.sql
--   — or —
--   php backend/db/run-seed.php
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Test admin user
--   Email:    admin@example.com
--   Password: password123
--   Hash:     password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12])
--   TOTP secret: JBSWY3DPEHPK3PXP (base32)
--   Encrypted with TOTP_ENCRYPTION_KEY=0000...0001 (63 zeros + 1), IV=0x00*16
--   Matches TEST_CREDENTIALS.totp.adminSecret in e2etests/config/test-credentials.ts
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_secret, totp_enabled, created_at, updated_at)
VALUES (
    '123e4567-e89b-12d3-a456-426614174000',
    'admin@example.com',
    '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG',
    'Admin User',
    'de',
    1,
    'AAAAAAAAAAAAAAAAAAAAAA==:HfdK5XMmHZlKgJl97MSpvKrDR62kRrN9FWvhGO62PQM=',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- Test terminal
--   Device ID: test-device-001
--   Token:     test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h
--   Hash:      hash('sha256', token)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO terminals (id, name, device_id, api_token_hash, is_active, created_at, updated_at)
VALUES (
    '44e4567-e89b-12d3-a456-426614174000',
    'Test Terminal',
    'test-device-001',
    'f88cf6afb8a2a7e19112a34a967c32d6e672dfbaec2809c82be6e970b550e1ae',
    1,
    NOW(),
    NOW()
);
