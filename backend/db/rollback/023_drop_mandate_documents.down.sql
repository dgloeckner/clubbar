-- =============================================================================
-- Rollback for 023_drop_mandate_documents.php
-- =============================================================================
-- Recreates the schema so an older release can start against it again — both
-- pieces come back empty. The PDFs the migration deleted are not recoverable
-- from the database, and nothing in the `mandate_documents` row shape ever
-- held the file bytes themselves (only a path into `storage/mandates/`), so
-- there is nothing here to restore them from either.
--
-- A release that needs an actual document back has to ask the treasurer for
-- the paper original and re-scan it.
-- =============================================================================

CREATE TABLE mandate_documents (
    id               CHAR(36)            NOT NULL,
    member_id        CHAR(36)            NOT NULL,
    file_path        VARCHAR(255)        NOT NULL,
    original_filename VARCHAR(255)       NOT NULL,
    file_size_bytes  INT UNSIGNED        NOT NULL,
    extraction_status ENUM('completed','failed') NULL DEFAULT NULL,
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

ALTER TABLE mandates
    ADD COLUMN document_id CHAR(36) NULL,
    ADD CONSTRAINT fk_mandates_document
        FOREIGN KEY (document_id) REFERENCES mandate_documents(id) ON DELETE SET NULL;
