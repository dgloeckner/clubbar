-- Migration: Standardize Product Icon Names
-- Date: 2026-02-08
-- Description: Migrate from legacy camelCase names (e.g., "PilsIcon")
--              to canonical kebab-case names (e.g., "beer-pils")
--              as defined in docs/icon-registry.md

-- This migration ensures consistency across backend, admin frontend, and terminal.

-- Beverages - Beer
UPDATE products SET icon_name = 'beer-pils' WHERE icon_name = 'PilsIcon';
UPDATE products SET icon_name = 'beer-weizen' WHERE icon_name = 'WeizenIcon';
UPDATE products SET icon_name = 'beer-radler' WHERE icon_name = 'RadlerIcon';
UPDATE products SET icon_name = 'beer-alcohol-free' WHERE icon_name = 'BeerAFIcon';

-- Beverages - Cider & Spritzers
UPDATE products SET icon_name = 'cider-apfelwein' WHERE icon_name = 'BembelIcon';
UPDATE products SET icon_name = 'spritzer-apple' WHERE icon_name = 'ApfelschorleIcon';

-- Beverages - Hot Drinks
UPDATE products SET icon_name = 'coffee' WHERE icon_name = 'CoffeeMugIcon';

-- Beverages - Water & Soft Drinks
UPDATE products SET icon_name = 'water-large' WHERE icon_name = 'WaterLargeIcon';

-- Services - Sauna
UPDATE products SET icon_name = 'sauna-session' WHERE icon_name = 'SaunaTimeIcon';
UPDATE products SET icon_name = 'sauna-cabin' WHERE icon_name = 'SaunaCabinIcon';
UPDATE products SET icon_name = 'sauna-infusion' WHERE icon_name = 'SaunaAufgussIcon';
UPDATE products SET icon_name = 'sauna-towel' WHERE icon_name = 'SaunaTowelIcon';

-- Verify migration (show all unique icon names after migration)
-- SELECT DISTINCT icon_name FROM products ORDER BY icon_name;
