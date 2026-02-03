-- =============================================================================
-- 001_initial_schema.sql
-- =============================================================================
-- Consolidated initial schema for Ruderbar (Vereinsbar) application.
-- Converted from 18 Laravel migrations into a single fresh-install schema.
--
-- Table creation order respects foreign key dependencies:
--   1. terminals          (no FKs)
--   2. members            (no FKs)
--   3. admin_users        (no FKs)
--   4. sessions           (no FKs)
--   5. audit_log          (no FKs, admin_user_id is logical reference only)
--   6. categories         (no FKs)
--   7. products           (FK -> categories)
--   8. transactions       (FK -> members)
--   9. settlements        (FK -> admin_users)
--  10. settlement_items   (FK -> settlements, transactions, members)
--  11. sepa_config        (FK -> admin_users)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. terminals
-- ---------------------------------------------------------------------------
CREATE TABLE terminals (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    device_id VARCHAR(255) NOT NULL UNIQUE,
    api_token_hash VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_terminals_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. members
-- ---------------------------------------------------------------------------
CREATE TABLE members (
    id CHAR(36) NOT NULL PRIMARY KEY,
    card_uid VARCHAR(20) NULL UNIQUE,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    preferred_language VARCHAR(10) NOT NULL DEFAULT 'de',
    iban VARCHAR(34) NULL,
    mandate_reference VARCHAR(35) NULL UNIQUE,
    mandate_signed_at DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_members_is_active (is_active),
    INDEX idx_members_created_at (created_at),
    INDEX idx_members_updated_at (updated_at),
    INDEX idx_members_active_created (is_active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. admin_users
-- ---------------------------------------------------------------------------
CREATE TABLE admin_users (
    id CHAR(36) NOT NULL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NULL,
    locale VARCHAR(10) NOT NULL DEFAULT 'de',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_admin_users_is_active (is_active),
    INDEX idx_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. sessions
-- ---------------------------------------------------------------------------
CREATE TABLE sessions (
    id VARCHAR(255) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    INDEX idx_sessions_user_id (user_id),
    INDEX idx_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. audit_log
-- ---------------------------------------------------------------------------
CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id CHAR(36) NULL,
    action ENUM('create','update','delete','anonymize','login','logout','login_failed','export','settlement_create','settlement_cancel','settlement_export','activate','deactivate','reorder') NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(36) NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_admin_user_id (admin_user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity_type (entity_type),
    INDEX idx_audit_entity_id (entity_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created_action (created_at, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. categories
-- ---------------------------------------------------------------------------
CREATE TABLE categories (
    id CHAR(36) NOT NULL PRIMARY KEY,
    names JSON NOT NULL,
    display_order INT NOT NULL UNIQUE,
    icon_name VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_categories_is_active (is_active),
    INDEX idx_categories_display_order (display_order),
    INDEX idx_categories_created_at (created_at),
    INDEX idx_categories_updated_at (updated_at),
    INDEX idx_categories_icon_name (icon_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. products (references categories)
-- ---------------------------------------------------------------------------
CREATE TABLE products (
    id CHAR(36) NOT NULL PRIMARY KEY,
    category_id CHAR(36) NOT NULL,
    names JSON NOT NULL,
    descriptions JSON NULL,
    price_cents INT NOT NULL,
    icon_name VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_is_active (is_active),
    INDEX idx_products_created_at (created_at),
    INDEX idx_products_updated_at (updated_at),
    INDEX idx_products_active_category (is_active, category_id, created_at),
    INDEX idx_products_icon_name (icon_name),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. transactions (references members)
-- ---------------------------------------------------------------------------
CREATE TABLE transactions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    member_id CHAR(36) NOT NULL,
    product_id CHAR(36) NULL,
    created_by_terminal_id CHAR(36) NULL,
    created_by_admin_id CHAR(36) NULL,
    amount_cents INT NOT NULL,
    transaction_type ENUM('purchase','correction') NOT NULL DEFAULT 'purchase',
    notes VARCHAR(500) NULL,
    related_transaction_id CHAR(36) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transactions_member_id (member_id),
    INDEX idx_transactions_product_id (product_id),
    INDEX idx_transactions_terminal_id (created_by_terminal_id),
    INDEX idx_transactions_admin_id (created_by_admin_id),
    INDEX idx_transactions_member_created (member_id, created_at),
    CONSTRAINT fk_transactions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9. settlements (references admin_users)
-- ---------------------------------------------------------------------------
CREATE TABLE settlements (
    id CHAR(36) NOT NULL PRIMARY KEY,
    manual_reason ENUM('cash','bank_transfer','write_off','other') NULL,
    settlement_date DATE NOT NULL,
    execution_date DATE NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    sepa_message_id VARCHAR(35) NULL UNIQUE,
    total_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    member_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    cancelled_at TIMESTAMP NULL,
    cancelled_by_admin_id CHAR(36) NULL,
    exported_at TIMESTAMP NULL,
    notes VARCHAR(1000) NULL,
    created_by_admin_id CHAR(36) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_settlements_date (settlement_date),
    INDEX idx_settlements_execution (execution_date),
    INDEX idx_settlements_cancelled (is_cancelled),
    CONSTRAINT fk_settlements_cancelled_by FOREIGN KEY (cancelled_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlements_created_by FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 10. settlement_items (references settlements, transactions, members)
-- ---------------------------------------------------------------------------
CREATE TABLE settlement_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id CHAR(36) NOT NULL,
    transaction_id CHAR(36) NOT NULL UNIQUE,
    member_id CHAR(36) NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    INDEX idx_settlement_items_settlement (settlement_id),
    INDEX idx_settlement_items_transaction (transaction_id),
    INDEX idx_settlement_items_member (member_id),
    INDEX idx_settlement_items_settlement_member (settlement_id, member_id),
    CONSTRAINT fk_settlement_items_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
    CONSTRAINT fk_settlement_items_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_items_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 11. sepa_config (singleton row, references admin_users)
-- ---------------------------------------------------------------------------
CREATE TABLE sepa_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    creditor_id VARCHAR(35) NULL,
    creditor_name VARCHAR(70) NULL,
    creditor_iban VARCHAR(34) NULL,
    creditor_address_street VARCHAR(70) NULL,
    creditor_address_city VARCHAR(35) NULL,
    creditor_address_country VARCHAR(2) NULL,
    updated_by_admin_id CHAR(36) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sepa_config_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize singleton row
INSERT INTO sepa_config (id) VALUES (1);
