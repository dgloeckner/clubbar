-- =============================================================================
-- Migration 013: Instance branding configuration
-- =============================================================================
-- Singleton table for the deploying club's display name (ADR-0034), following
-- the sepa_config precedent (ADR-0007). Read by the admin frontend, the
-- Terminal (via /health), and TotpService (issuer for new 2FA enrollments).
-- =============================================================================

CREATE TABLE instance_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    instance_name VARCHAR(100) NOT NULL DEFAULT 'Club Bar',
    updated_by_admin_id CHAR(36) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_instance_config_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize singleton row
INSERT INTO instance_config (id, instance_name) VALUES (1, 'Club Bar');
