-- Bank codes lookup table for resolving IBAN to bank name.
-- Data source: Deutsche Bundesbank BLZ-Datei (quarterly updates).
-- Only Merkmal=1 (main office) entries are imported to avoid duplicates.

CREATE TABLE IF NOT EXISTS bank_codes (
    bank_code   CHAR(8)      NOT NULL PRIMARY KEY COMMENT 'Bankleitzahl (BLZ)',
    bank_name   VARCHAR(128) NOT NULL              COMMENT 'Full bank name from Bundesbank file',
    short_name  VARCHAR(30)  NOT NULL DEFAULT ''    COMMENT 'Short designation (Kurzbezeichnung)',
    bic         VARCHAR(11)  NOT NULL DEFAULT ''    COMMENT 'SWIFT/BIC code',
    postal_code VARCHAR(10)  NOT NULL DEFAULT ''    COMMENT 'PLZ of bank headquarters',
    city        VARCHAR(40)  NOT NULL DEFAULT ''    COMMENT 'City of bank headquarters',
    imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this row was last imported'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
