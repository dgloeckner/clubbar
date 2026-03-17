-- Club Bar Database Initialization
-- This file runs on first container start

-- Ensure UTF-8 encoding
ALTER DATABASE clubbar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant permissions
GRANT ALL PRIVILEGES ON clubbar.* TO 'clubbar'@'%';
FLUSH PRIVILEGES;

-- Note: SQL migrations create the actual tables via the install endpoint
-- Run: curl -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=migrate"
