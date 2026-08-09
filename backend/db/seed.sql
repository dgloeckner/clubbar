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
-- ---------------------------------------------------------------------------
-- Bank codes (BLZ) — test data for IBAN → bank name resolution
-- BLZs used in E2E test IBANs (see e2etests/utils/transactions.ts)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO bank_codes (bank_code, bank_name, short_name, bic, postal_code, city)
VALUES
    ('37040044', 'Commerzbank',                       'Commerzbank Köln',    'COBADEFFXXX', '50447', 'Köln'),
    ('51210800', 'Commerzbank vormals Dresdner Bank', 'Commerzbank Ffm',     'DRESDEFF512', '63263', 'Neu-Isenburg'),
    ('10077777', 'norisbank',                         'norisbank Berlin',    'NORSDE51XXX', '10789', 'Berlin'),
    ('10010010', 'Postbank',                          'Postbank Berlin',     'PBNKDEFF100', '10559', 'Berlin'),
    ('37050299', 'Kreissparkasse Köln',               'KSK Köln',           'COKSDE33XXX', '50441', 'Köln');

-- ---------------------------------------------------------------------------
-- Test terminal
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO terminals (id, name, device_id, api_token_hash, token_issued_at, token_expires_at, is_active, created_at, updated_at)
VALUES (
    '44e4567-e89b-12d3-a456-426614174000',
    'Test Terminal',
    'test-device-001',
    'f88cf6afb8a2a7e19112a34a967c32d6e672dfbaec2809c82be6e970b550e1ae',
    NOW(),
    NOW() + INTERVAL 90 DAY,
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- Expired test terminal (#106)
-- ---------------------------------------------------------------------------
-- Its token is well-formed, its terminal is active, and its lifetime ran out
-- yesterday: the one state that cannot be produced through the API, and the
-- one the expiry check exists for. E2E asserts it is refused with
-- `terminal_token_expired` — see e2etests/config/test-credentials.ts.
INSERT IGNORE INTO terminals (id, name, device_id, api_token_hash, token_issued_at, token_expires_at, is_active, created_at, updated_at)
VALUES (
    '44e4567-e89b-12d3-a456-426614174001',
    'Expired Test Terminal',
    'test-device-expired-001',
    SHA2('expired-terminal-token-do-not-use-in-production-9z8y7x6w5v4u', 256),
    NOW() - INTERVAL 91 DAY,
    NOW() - INTERVAL 1 DAY,
    1,
    NOW() - INTERVAL 91 DAY,
    NOW()
);
