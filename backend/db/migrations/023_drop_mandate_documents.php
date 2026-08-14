<?php

declare(strict_types=1);

// =============================================================================
// 023_drop_mandate_documents.php — mandate scans are no longer retained (ADR-0037)
// =============================================================================
// The scanned mandate — a name, an IBAN and a handwritten signature — stops
// being a load-bearing artifact: the signed paper original, archived by the
// treasurer outside the system, is the Beleg from here on (superseded
// ADR-0010 already reached the same conclusion; ADR-0037 restates it now that
// ADR-0036 also closes the database's own IBAN copy). `mandates.document_id`
// was never written by any code path in this release — the FK exists, the
// column has always been NULL — so dropping it discards no data. The
// `mandate_documents` table did hold uploaded PDFs; whatever rows and files
// exist for it are the destructive part of this migration.
//
// This is a `.php` migration, not `.sql`, because the schema change alone
// would leave the actual PDF bytes behind on disk under `storage/mandates/`
// — exactly the file class ADR-0037 says must stop existing server-side.
// `MigrationRunner` now runs a `.php` file's returned callable with the same
// checksum bookkeeping a `.sql` file gets; the callable additionally receives
// `$context['storageDir']`, resolved from the same `AppConfig` the
// application itself uses to find its data directory.
//
// Destructive and announced: release notes instruct the club to download and
// archive any stored scans before upgrading. `SecuritySelfCheck` reports a
// finding if files remain under `storage/mandates/` afterwards — the most
// likely cause being a host that would not let PHP delete them, which this
// step does not treat as fatal (the schema change still commits; a leftover
// file becomes a warning on the security page, not a broken upgrade).
//
// Idempotent: every step checks the current schema/filesystem state before
// acting, so re-running this callable — by hand, against a database that
// already has it applied — is a no-op rather than an error. (The checksum
// bookkeeping in MigrationRunner already prevents that in the normal path;
// this is the belt for the admin who runs the SQL by hand instead.)
//
// Rollback: db/rollback/023_drop_mandate_documents.down.sql recreates the
// column and table, empty. The deleted files are not recoverable from the
// database in either direction — that is the point of this migration.
// =============================================================================

return function (PDO $pdo, array $context): void {
    $hasDocumentIdColumn = (bool) $pdo->query(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mandates' AND COLUMN_NAME = 'document_id'"
    )->fetchColumn();

    if ($hasDocumentIdColumn) {
        $hasForeignKey = (bool) $pdo->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mandates'
                AND CONSTRAINT_NAME = 'fk_mandates_document' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        )->fetchColumn();

        $pdo->exec($hasForeignKey
            ? 'ALTER TABLE mandates DROP FOREIGN KEY fk_mandates_document, DROP COLUMN document_id'
            : 'ALTER TABLE mandates DROP COLUMN document_id');
    }

    $pdo->exec('DROP TABLE IF EXISTS mandate_documents');

    $storageDir = rtrim((string) ($context['storageDir'] ?? ''), '/') . '/mandates';
    foreach (glob($storageDir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
};
