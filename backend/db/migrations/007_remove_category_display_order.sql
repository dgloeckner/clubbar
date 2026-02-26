-- Migration 007: Remove display_order from categories
-- Categories are now sorted lexicographically by name in the terminal.
-- The display_order feature has been removed to simplify the data model.

ALTER TABLE categories DROP INDEX idx_categories_display_order;
ALTER TABLE categories DROP COLUMN display_order;
