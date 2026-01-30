-- Reset Settlements and Transactions for Testing
-- WARNING: This deletes all settlement and transaction data. Use only in development.

SET FOREIGN_KEY_CHECKS=0;

-- Clear settlement-related tables
TRUNCATE TABLE settlement_items;
TRUNCATE TABLE settlements;

-- Clear transactions (optional - comment out to preserve transactions)
-- TRUNCATE TABLE transactions;

SET FOREIGN_KEY_CHECKS=1;

-- Verify clean state
SELECT COUNT(*) as settlement_count FROM settlements;
SELECT COUNT(*) as settlement_items_count FROM settlement_items;
SELECT COUNT(*) as transactions_count FROM transactions;
