-- Migration 004: Add deletion tracking for sync protocol
-- Adds deleted_at and deleted_by_admin_id columns to members, categories, and products
-- Adds composite indexes for optimal sync query performance

-- Members table: Add deleted_by_admin_id (deleted_at already exists from GDPR)
ALTER TABLE members
  ADD COLUMN deleted_by_admin_id VARCHAR(36) NULL AFTER deleted_at;

ALTER TABLE members
  ADD INDEX idx_sync_combined (updated_at, deleted_at);

ALTER TABLE members
  ADD CONSTRAINT fk_members_deleted_by
    FOREIGN KEY (deleted_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL;

-- Categories table: Add deletion tracking
ALTER TABLE categories
  ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
  ADD COLUMN deleted_by_admin_id VARCHAR(36) NULL AFTER deleted_at;

ALTER TABLE categories
  ADD INDEX idx_deleted_at (deleted_at),
  ADD INDEX idx_sync_combined (updated_at, deleted_at);

ALTER TABLE categories
  ADD CONSTRAINT fk_categories_deleted_by
    FOREIGN KEY (deleted_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL;

-- Products table: Add deletion tracking
ALTER TABLE products
  ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
  ADD COLUMN deleted_by_admin_id VARCHAR(36) NULL AFTER deleted_at;

ALTER TABLE products
  ADD INDEX idx_deleted_at (deleted_at),
  ADD INDEX idx_sync_combined (updated_at, deleted_at);

ALTER TABLE products
  ADD CONSTRAINT fk_products_deleted_by
    FOREIGN KEY (deleted_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL;
