-- Rollback for migration 024: mail configuration (ADR-0038)
DROP TABLE IF EXISTS mail_config;
