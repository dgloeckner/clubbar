-- 005_mandate_documents.sql
-- One scanned SEPA mandate PDF per member.
-- extraction_status/extracted_data are placeholders for future LLM extraction.

-- Extend audit_log action enum with mandate document actions
ALTER TABLE audit_log MODIFY COLUMN action ENUM(
    'create','update','delete','anonymize','login','logout','login_failed',
    'export','settlement_create','settlement_cancel','settlement_export',
    'activate','deactivate','reorder','totp_enrolled','totp_reset',
    'mandate_document_upload','mandate_document_delete'
) NOT NULL;

CREATE TABLE mandate_documents (
    id               CHAR(36)            NOT NULL,
    member_id        CHAR(36)            NOT NULL,
    file_path        VARCHAR(255)        NOT NULL,
    original_filename VARCHAR(255)       NOT NULL,
    file_size_bytes  INT UNSIGNED        NOT NULL,
    extraction_status ENUM('pending','completed','failed') NULL DEFAULT NULL,
    extracted_data   JSON                NULL,
    uploaded_by_admin_id CHAR(36)        NOT NULL,
    created_at       TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_mandate_documents_member (member_id),

    CONSTRAINT fk_mandate_documents_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_mandate_documents_admin
        FOREIGN KEY (uploaded_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
