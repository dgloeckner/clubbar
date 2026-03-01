-- =============================================================================
-- reset_test_data.sql
-- =============================================================================
-- Resets the database to a clean state with test data for manual Flutter UI testing.
--
-- Usage:
--   docker compose exec -T database mysql -u root -proot ruderbar < backend/db/reset_test_data.sql
--
-- IMPORTANT: After running this script, reset the Flutter app's local cache:
--   1. QUIT the Flutter app first (press 'q' in terminal)
--   2. Delete the database:
--      rm ~/Library/Containers/com.example.ruderbarTerminal/Data/Library/Application\ Support/com.example.ruderbarTerminal/ruderbar_terminal.db
--   3. Restart the Flutter app: flutter run -d macos
--
-- Note: The app must be stopped BEFORE deleting the database, otherwise it
-- recreates it from in-memory cache.
--
-- Creates:
--   - 1 admin user: admin@example.com / password123
--   - 2 categories: Getränke, Sauna
--   - 13 products with nice icons (including Sauna-Token with dispenser)
--   - 8 members with nice names and valid SEPA data (2 with real card UIDs)
--   - 3 terminals with known test tokens (Bar + Sauna active, Terrace inactive)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Clear all data
-- ---------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM settlement_items;
DELETE FROM settlements;
DELETE FROM transactions;
DELETE FROM products;
DELETE FROM categories;
DELETE FROM members;
DELETE FROM terminals;
DELETE FROM audit_log;
DELETE FROM sessions;
DELETE FROM sepa_config;
DELETE FROM admin_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- 2. Create Admin User
-- ---------------------------------------------------------------------------
-- Email: admin@example.com
-- Password: password123
-- Hash generated with: password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12])
INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, created_at, updated_at)
VALUES (
    '33e4567-e89b-12d3-a456-426614174000',
    'admin@example.com',
    '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG',
    'Admin User',
    'de',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 3. Create Categories
-- ---------------------------------------------------------------------------
-- Category: Getränke (Beverages)
INSERT INTO categories (id, names, icon_name, is_active, created_at, updated_at)
VALUES (
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Getränke", "en": "Beverages"}',
    'beer-pils',
    1,
    NOW(),
    NOW()
);

-- Category: Sauna
INSERT INTO categories (id, names, icon_name, is_active, created_at, updated_at)
VALUES (
    '22222222-2222-2222-2222-222222222222',
    '{"de": "Sauna", "en": "Sauna"}',
    'sauna-cabin',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 4. Create Products - Getränke
-- ---------------------------------------------------------------------------
-- Pils 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333331-3333-3333-3333-333333333331',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Pils 0,5L", "en": "Pilsner 0.5L"}',
    '{"de": "Frisches Pils vom Fass", "en": "Fresh draft pilsner"}',
    350,
    'beer-pils',
    1,
    NOW(),
    NOW()
);

-- Weizen 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333332-3333-3333-3333-333333333332',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Weizen 0,5L", "en": "Wheat Beer 0.5L"}',
    '{"de": "Bayrisches Hefeweizen", "en": "Bavarian wheat beer"}',
    380,
    'beer-weizen',
    1,
    NOW(),
    NOW()
);

-- Radler 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333333-3333-3333-3333-333333333333',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Radler 0,5L", "en": "Shandy 0.5L"}',
    '{"de": "Erfrischendes Radler", "en": "Refreshing beer lemonade mix"}',
    320,
    'beer-radler',
    1,
    NOW(),
    NOW()
);

-- Alkoholfrei 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333334-3333-3333-3333-333333333334',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Alkoholfrei 0,5L", "en": "Non-Alcoholic 0.5L"}',
    '{"de": "Alkoholfreies Pils", "en": "Non-alcoholic pilsner"}',
    320,
    'beer-alcohol-free',
    1,
    NOW(),
    NOW()
);

-- Apfelschorle 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333335-3333-3333-3333-333333333335',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Apfelschorle 0,5L", "en": "Apple Spritzer 0.5L"}',
    '{"de": "Apfelsaft mit Sprudel", "en": "Apple juice with sparkling water"}',
    280,
    'spritzer-apple',
    1,
    NOW(),
    NOW()
);

-- Wasser 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333336-3333-3333-3333-333333333336',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Wasser 0,5L", "en": "Water 0.5L"}',
    '{"de": "Stilles oder mit Kohlensäure", "en": "Still or sparkling"}',
    200,
    'water-large',
    1,
    NOW(),
    NOW()
);

-- Kaffee
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333337-3333-3333-3333-333333333337',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Kaffee", "en": "Coffee"}',
    '{"de": "Frisch gebrühter Filterkaffee", "en": "Freshly brewed filter coffee"}',
    150,
    'coffee',
    1,
    NOW(),
    NOW()
);

-- Äppler 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '33333338-3333-3333-3333-333333333338',
    '11111111-1111-1111-1111-111111111111',
    '{"de": "Äppler 0,5L", "en": "Apple Cider 0.5L"}',
    '{"de": "Hessischer Apfelwein", "en": "Hessian apple cider"}',
    350,
    'cider-apfelwein',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 5. Create Products - Sauna
-- ---------------------------------------------------------------------------
-- Sauna Tageskarte
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '44444441-4444-4444-4444-444444444441',
    '22222222-2222-2222-2222-222222222222',
    '{"de": "Sauna Tageskarte", "en": "Sauna Day Pass"}',
    '{"de": "Ganztägiger Zugang zur Sauna", "en": "Full day sauna access"}',
    1500,
    'sauna-cabin',
    1,
    NOW(),
    NOW()
);

-- Sauna 2 Stunden
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '44444442-4444-4444-4444-444444444442',
    '22222222-2222-2222-2222-222222222222',
    '{"de": "Sauna 2 Stunden", "en": "Sauna 2 Hours"}',
    '{"de": "2 Stunden Saunanutzung", "en": "2 hours sauna access"}',
    800,
    'sauna-session',
    1,
    NOW(),
    NOW()
);

-- Handtuch
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '44444443-4444-4444-4444-444444444443',
    '22222222-2222-2222-2222-222222222222',
    '{"de": "Handtuch", "en": "Towel"}',
    '{"de": "Leihhandtuch", "en": "Rental towel"}',
    300,
    'sauna-towel',
    1,
    NOW(),
    NOW()
);

-- Aufguss Special
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    '44444444-4444-4444-4444-444444444444',
    '22222222-2222-2222-2222-222222222222',
    '{"de": "Aufguss Special", "en": "Special Infusion"}',
    '{"de": "Premium Aufguss mit ätherischen Ölen", "en": "Premium infusion with essential oils"}',
    500,
    'sauna-infusion',
    1,
    NOW(),
    NOW()
);

-- Sauna-Token (requires dispenser)
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, requires_dispenser, is_active, created_at, updated_at)
VALUES (
    '44444445-4444-4444-4444-444444444445',
    '22222222-2222-2222-2222-222222222222',
    '{"de": "Sauna-Token", "en": "Sauna Token"}',
    '{"de": "Physischer Token für Sauna-Schließfach", "en": "Physical token for sauna locker"}',
    300,
    'sauna-token',
    1,
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 6. Create Members with nice German names and valid SEPA data
-- ---------------------------------------------------------------------------
-- Valid German IBANs for testing (fictional but checksum-valid):
-- DE89370400440532013000 - Standard test IBAN
-- DE02120300000000202051 - Another valid format

-- Member 1: Hans Müller (active rower)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555551-5555-5555-5555-555555555551',
    '0003195661',
    'Hans',
    'Müller',
    'hans.mueller@example.de',
    '+49 170 1234567',
    'de',
    'DE89370400440532013000',
    'Hans Müller',
    'MANDATE001HANSMUELLER',
    '2024-01-15',
    1,
    NOW(),
    NOW()
);

-- Member 2: Maria Schmidt (team captain)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555552-5555-5555-5555-555555555552',
    '0013466849',
    'Maria',
    'Schmidt',
    'maria.schmidt@example.de',
    '+49 171 2345678',
    'de',
    'DE02120300000000202051',
    'Maria Schmidt',
    'MANDATE002MARIASCHMIDT',
    '2024-02-20',
    1,
    NOW(),
    NOW()
);

-- Member 3: Thomas Weber (English preferred)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555553-5555-5555-5555-555555555553',
    'CARD003',
    'Thomas',
    'Weber',
    'thomas.weber@example.de',
    '+49 172 3456789',
    'en',
    'DE89370400440532013000',
    'Thomas Weber',
    'MANDATE003THOMASWEBER',
    '2024-03-10',
    1,
    NOW(),
    NOW()
);

-- Member 4: Anna Fischer (sauna enthusiast)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555554-5555-5555-5555-555555555554',
    'CARD004',
    'Anna',
    'Fischer',
    'anna.fischer@example.de',
    '+49 173 4567890',
    'de',
    'DE02120300000000202051',
    'Anna Fischer',
    'MANDATE004ANNAFISCHER',
    '2024-01-05',
    1,
    NOW(),
    NOW()
);

-- Member 5: Michael Bauer (veteran rower)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555555-5555-5555-5555-555555555555',
    'CARD005',
    'Michael',
    'Bauer',
    'michael.bauer@example.de',
    '+49 174 5678901',
    'de',
    'DE89370400440532013000',
    'Michael Bauer',
    'MANDATE005MICHAELBAUER',
    '2023-11-20',
    1,
    NOW(),
    NOW()
);

-- Member 6: Sabine Klein (new member)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555556-5555-5555-5555-555555555556',
    'CARD006',
    'Sabine',
    'Klein',
    'sabine.klein@example.de',
    '+49 175 6789012',
    'de',
    'DE02120300000000202051',
    'Sabine Klein',
    'MANDATE006SABINEKLEIN',
    '2024-06-01',
    1,
    NOW(),
    NOW()
);

-- Member 7: Peter Hoffmann (board member)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555557-5555-5555-5555-555555555557',
    'CARD007',
    'Peter',
    'Hoffmann',
    'peter.hoffmann@example.de',
    '+49 176 7890123',
    'de',
    'DE89370400440532013000',
    'Peter Hoffmann',
    'MANDATE007PETERHOFFMANN',
    '2023-08-15',
    1,
    NOW(),
    NOW()
);

-- Member 8: Julia Wagner (youth coach)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    '55555558-5555-5555-5555-555555555558',
    'CARD008',
    'Julia',
    'Wagner',
    'julia.wagner@example.de',
    '+49 177 8901234',
    'de',
    'DE02120300000000202051',
    'Julia Wagner',
    'MANDATE008JULIAWAGNER',
    '2024-04-22',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 7. Create Terminals
-- ---------------------------------------------------------------------------
-- E2E Test Terminal (used by Playwright E2E tests — DO NOT REMOVE)
-- Token: test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h
-- Hash: echo -n "test-terminal-token..." | sha256sum
-- Must match e2etests/config/test-credentials.ts
INSERT INTO terminals (id, name, device_id, api_token_hash, is_active, created_at, updated_at)
VALUES (
    '44e4567-e89b-12d3-a456-426614174000',
    'Test Terminal',
    'test-device-001',
    'f88cf6afb8a2a7e19112a34a967c32d6e672dfbaec2809c82be6e970b550e1ae',
    1,
    NOW(),
    NOW()
);

-- Terminal 1: Bar Terminal (main POS at the bar counter)
-- Token: test-token-bar-terminal-0001 → SHA-256 hash below
INSERT INTO terminals (id, name, device_id, api_token_hash, is_active, last_sync_at, created_at, updated_at)
VALUES (
    '66666661-6666-6666-6666-666666666661',
    'Bar Terminal',
    'BAR-MAIN-001',
    SHA2('test-token-bar-terminal-0001', 256),
    1,
    DATE_SUB(NOW(), INTERVAL 5 MINUTE),
    NOW(),
    NOW()
);

-- Terminal 2: Sauna Terminal (POS at the sauna entrance)
-- Token: test-token-sauna-terminal-002 → SHA-256 hash below
INSERT INTO terminals (id, name, device_id, api_token_hash, is_active, last_sync_at, created_at, updated_at)
VALUES (
    '66666662-6666-6666-6666-666666666662',
    'Sauna Terminal',
    'SAUNA-ENT-002',
    SHA2('test-token-sauna-terminal-002', 256),
    1,
    DATE_SUB(NOW(), INTERVAL 2 HOUR),
    NOW(),
    NOW()
);

-- Terminal 3: Terrace Terminal (seasonal outdoor POS, currently inactive)
-- Token: test-token-terrace-term-0003 → SHA-256 hash below
INSERT INTO terminals (id, name, device_id, api_token_hash, is_active, last_sync_at, created_at, updated_at)
VALUES (
    '66666663-6666-6666-6666-666666666663',
    'Terrace Terminal',
    'TERRACE-OUT-003',
    SHA2('test-token-terrace-term-0003', 256),
    0,
    NULL,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- Summary
-- ---------------------------------------------------------------------------
SELECT 'Test data reset complete!' AS status;
SELECT COUNT(*) AS admin_users FROM admin_users;
SELECT COUNT(*) AS categories FROM categories;
SELECT COUNT(*) AS products FROM products;
SELECT COUNT(*) AS members FROM members;
SELECT COUNT(*) AS terminals FROM terminals;
