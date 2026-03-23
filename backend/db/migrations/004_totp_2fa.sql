ALTER TABLE admin_users
    ADD COLUMN totp_secret  VARCHAR(255) NULL    COMMENT 'AES-256-CBC encrypted TOTP secret (base64(iv):base64(ciphertext))',
    ADD COLUMN totp_enabled TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0 = not enrolled, 1 = TOTP active';

ALTER TABLE audit_log
    MODIFY COLUMN action ENUM(
        'create','update','delete','anonymize',
        'login','logout','login_failed',
        'export','settlement_create','settlement_cancel','settlement_export',
        'activate','deactivate','reorder',
        'totp_enrolled','totp_reset'
    ) NOT NULL;
