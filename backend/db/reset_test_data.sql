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
--   - 2 categories: Getränke, Sauna
--   - 12 products with nice icons
--   - 8 members with nice names and valid SEPA data
--   - Some sample transactions for testing
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Clear existing test data (preserves admin_users and terminals)
-- ---------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

-- Delete in order of dependencies
DELETE FROM settlement_items;
DELETE FROM settlements;
DELETE FROM transactions;
DELETE FROM products;
DELETE FROM categories;
DELETE FROM members;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- 2. Create Categories
-- ---------------------------------------------------------------------------
-- Category: Getränke (Beverages)
INSERT INTO categories (id, names, display_order, icon_name, is_active, created_at, updated_at)
VALUES (
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Getränke", "en": "Beverages"}',
    1,
    'PilsIcon',
    1,
    NOW(),
    NOW()
);

-- Category: Sauna
INSERT INTO categories (id, names, display_order, icon_name, is_active, created_at, updated_at)
VALUES (
    'cat-sauna-00001-0001-000000000002',
    '{"de": "Sauna", "en": "Sauna"}',
    2,
    'SaunaCabinIcon',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 3. Create Products - Getränke
-- ---------------------------------------------------------------------------
-- Pils 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-pils-00001-0001-000000000001',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Pils 0,5L", "en": "Pilsner 0.5L"}',
    '{"de": "Frisches Pils vom Fass", "en": "Fresh draft pilsner"}',
    350,
    'PilsIcon',
    1,
    NOW(),
    NOW()
);

-- Weizen 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-weizen-0001-0001-000000000002',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Weizen 0,5L", "en": "Wheat Beer 0.5L"}',
    '{"de": "Bayrisches Hefeweizen", "en": "Bavarian wheat beer"}',
    380,
    'WeizenIcon',
    1,
    NOW(),
    NOW()
);

-- Radler 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-radler-0001-0001-000000000003',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Radler 0,5L", "en": "Shandy 0.5L"}',
    '{"de": "Erfrischendes Radler", "en": "Refreshing beer lemonade mix"}',
    320,
    'RadlerIcon',
    1,
    NOW(),
    NOW()
);

-- Alkoholfrei 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-beeraf-0001-0001-000000000004',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Alkoholfrei 0,5L", "en": "Non-Alcoholic 0.5L"}',
    '{"de": "Alkoholfreies Pils", "en": "Non-alcoholic pilsner"}',
    320,
    'BeerAFIcon',
    1,
    NOW(),
    NOW()
);

-- Apfelschorle 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-apfels-0001-0001-000000000005',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Apfelschorle 0,5L", "en": "Apple Spritzer 0.5L"}',
    '{"de": "Apfelsaft mit Sprudel", "en": "Apple juice with sparkling water"}',
    280,
    'ApfelschorleIcon',
    1,
    NOW(),
    NOW()
);

-- Wasser 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-wasser-0001-0001-000000000006',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Wasser 0,5L", "en": "Water 0.5L"}',
    '{"de": "Stilles oder mit Kohlensäure", "en": "Still or sparkling"}',
    200,
    'WaterLargeIcon',
    1,
    NOW(),
    NOW()
);

-- Kaffee
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-kaffee-0001-0001-000000000007',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Kaffee", "en": "Coffee"}',
    '{"de": "Frisch gebrühter Filterkaffee", "en": "Freshly brewed filter coffee"}',
    150,
    'CoffeeMugIcon',
    1,
    NOW(),
    NOW()
);

-- Äppler 0.5L
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-appler-0001-0001-000000000008',
    'cat-getraenke-0001-0001-000000000001',
    '{"de": "Äppler 0,5L", "en": "Apple Cider 0.5L"}',
    '{"de": "Hessischer Apfelwein", "en": "Hessian apple cider"}',
    350,
    'BembelIcon',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 4. Create Products - Sauna
-- ---------------------------------------------------------------------------
-- Sauna Tageskarte
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-sauna1-0001-0001-000000000009',
    'cat-sauna-00001-0001-000000000002',
    '{"de": "Sauna Tageskarte", "en": "Sauna Day Pass"}',
    '{"de": "Ganztägiger Zugang zur Sauna", "en": "Full day sauna access"}',
    1500,
    'SaunaCabinIcon',
    1,
    NOW(),
    NOW()
);

-- Sauna 2 Stunden
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-sauna2-0001-0001-000000000010',
    'cat-sauna-00001-0001-000000000002',
    '{"de": "Sauna 2 Stunden", "en": "Sauna 2 Hours"}',
    '{"de": "2 Stunden Saunanutzung", "en": "2 hours sauna access"}',
    800,
    'SaunaTimeIcon',
    1,
    NOW(),
    NOW()
);

-- Handtuch
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-sauna3-0001-0001-000000000011',
    'cat-sauna-00001-0001-000000000002',
    '{"de": "Handtuch", "en": "Towel"}',
    '{"de": "Leihhandtuch", "en": "Rental towel"}',
    300,
    'SaunaTowelIcon',
    1,
    NOW(),
    NOW()
);

-- Aufguss Special
INSERT INTO products (id, category_id, names, descriptions, price_cents, icon_name, is_active, created_at, updated_at)
VALUES (
    'prod-sauna4-0001-0001-000000000012',
    'cat-sauna-00001-0001-000000000002',
    '{"de": "Aufguss Special", "en": "Special Infusion"}',
    '{"de": "Premium Aufguss mit ätherischen Ölen", "en": "Premium infusion with essential oils"}',
    500,
    'SaunaAufgussIcon',
    1,
    NOW(),
    NOW()
);

-- ---------------------------------------------------------------------------
-- 5. Create Members with nice German names and valid SEPA data
-- ---------------------------------------------------------------------------
-- Valid German IBANs for testing (fictional but checksum-valid):
-- DE89370400440532013000 - Standard test IBAN
-- DE02120300000000202051 - Another valid format

-- Member 1: Hans Müller (active rower)
INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, iban, account_holder_name, mandate_reference, mandate_signed_at, is_active, created_at, updated_at)
VALUES (
    'mem-hansm-00001-0001-000000000001',
    'CARD001',
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
    'mem-marias-0001-0001-000000000002',
    'CARD002',
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
    'mem-thomw-00001-0001-000000000003',
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
    'mem-annaf-00001-0001-000000000004',
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
    'mem-michb-00001-0001-000000000005',
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
    'mem-sabik-00001-0001-000000000006',
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
    'mem-peterh-0001-0001-000000000007',
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
    'mem-juliaw-0001-0001-000000000008',
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
-- 6. Create Sample Transactions (for testing Journal/Statistics)
-- ---------------------------------------------------------------------------
-- Get terminal ID for transactions
SET @terminal_id = '44e4567-e89b-12d3-a456-426614174000';

-- Hans buys a Pils (yesterday)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test01-0001-0001-000000000001',
    'mem-hansm-00001-0001-000000000001',
    'prod-pils-00001-0001-000000000001',
    @terminal_id,
    -350,
    'purchase',
    DATE_SUB(NOW(), INTERVAL 1 DAY)
);

-- Maria buys Weizen (yesterday)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test02-0001-0001-000000000002',
    'mem-marias-0001-0001-000000000002',
    'prod-weizen-0001-0001-000000000002',
    @terminal_id,
    -380,
    'purchase',
    DATE_SUB(NOW(), INTERVAL 1 DAY)
);

-- Anna buys Sauna Tageskarte (today)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test03-0001-0001-000000000003',
    'mem-annaf-00001-0001-000000000004',
    'prod-sauna1-0001-0001-000000000009',
    @terminal_id,
    -1500,
    'purchase',
    NOW()
);

-- Thomas buys Coffee (today)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test04-0001-0001-000000000004',
    'mem-thomw-00001-0001-000000000003',
    'prod-kaffee-0001-0001-000000000007',
    @terminal_id,
    -150,
    'purchase',
    NOW()
);

-- Michael buys Äppler (2 days ago)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test05-0001-0001-000000000005',
    'mem-michb-00001-0001-000000000005',
    'prod-appler-0001-0001-000000000008',
    @terminal_id,
    -350,
    'purchase',
    DATE_SUB(NOW(), INTERVAL 2 DAY)
);

-- Peter buys Apfelschorle (3 days ago)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test06-0001-0001-000000000006',
    'mem-peterh-0001-0001-000000000007',
    'prod-apfels-0001-0001-000000000005',
    @terminal_id,
    -280,
    'purchase',
    DATE_SUB(NOW(), INTERVAL 3 DAY)
);

-- Julia buys Sauna 2 Stunden + Handtuch (today)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test07-0001-0001-000000000007',
    'mem-juliaw-0001-0001-000000000008',
    'prod-sauna2-0001-0001-000000000010',
    @terminal_id,
    -800,
    'purchase',
    NOW()
);

INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test08-0001-0001-000000000008',
    'mem-juliaw-0001-0001-000000000008',
    'prod-sauna3-0001-0001-000000000011',
    @terminal_id,
    -300,
    'purchase',
    NOW()
);

-- Sabine buys Wasser (today)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test09-0001-0001-000000000009',
    'mem-sabik-00001-0001-000000000006',
    'prod-wasser-0001-0001-000000000006',
    @terminal_id,
    -200,
    'purchase',
    NOW()
);

-- Hans buys another Pils (today)
INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, created_at)
VALUES (
    'txn-test10-0001-0001-000000000010',
    'mem-hansm-00001-0001-000000000001',
    'prod-pils-00001-0001-000000000001',
    @terminal_id,
    -350,
    'purchase',
    NOW()
);

-- ---------------------------------------------------------------------------
-- Summary
-- ---------------------------------------------------------------------------
SELECT 'Test data reset complete!' AS status;
SELECT COUNT(*) AS categories FROM categories;
SELECT COUNT(*) AS products FROM products;
SELECT COUNT(*) AS members FROM members;
SELECT COUNT(*) AS transactions FROM transactions;
