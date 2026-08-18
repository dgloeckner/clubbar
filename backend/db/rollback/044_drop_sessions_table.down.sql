-- Rollback for 044_drop_sessions_table.sql
-- Recreates the `sessions` table, empty — the dropped rows are not recoverable.

CREATE TABLE sessions (
    id VARCHAR(255) NOT NULL,
    admin_user_id CHAR(36) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sessions_admin_user_id (admin_user_id),
    INDEX idx_sessions_last_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
