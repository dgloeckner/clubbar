-- Ruderbar Database Initialization
-- This file runs on first container start

-- Ensure UTF-8 encoding
ALTER DATABASE ruderbar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant permissions
GRANT ALL PRIVILEGES ON ruderbar.* TO 'ruderbar'@'%';
FLUSH PRIVILEGES;

-- Note: Laravel migrations will create the actual tables
-- Run: docker-compose exec backend php artisan migrate
