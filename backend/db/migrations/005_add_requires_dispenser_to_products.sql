-- Migration 005: Add requires_dispenser column to products table
-- Adds boolean flag to mark products requiring physical dispenser (e.g., sauna tokens)
-- Products with this flag set will trigger dispenser integration in terminal

-- Add requires_dispenser column after is_active
ALTER TABLE products
  ADD COLUMN requires_dispenser TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- Add index for filtering products by dispenser requirement
CREATE INDEX idx_products_requires_dispenser ON products(requires_dispenser);
