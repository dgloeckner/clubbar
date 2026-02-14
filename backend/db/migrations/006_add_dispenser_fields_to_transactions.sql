-- Add dispenser metadata fields to transactions table
-- dispenser_tx_id: Idempotency token for dispenser API calls
-- dispenser_requested: Number of tokens requested from dispenser
-- dispenser_actual: Number of tokens actually dispensed

ALTER TABLE transactions
ADD COLUMN dispenser_tx_id VARCHAR(16) NULL AFTER notes,
ADD COLUMN dispenser_requested INT NULL AFTER dispenser_tx_id,
ADD COLUMN dispenser_actual INT NULL AFTER dispenser_requested;

-- Add index for lookups by dispenser transaction ID
CREATE INDEX idx_transactions_dispenser_tx_id ON transactions(dispenser_tx_id);
