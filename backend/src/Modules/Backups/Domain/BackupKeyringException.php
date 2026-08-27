<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

use RuntimeException;

/**
 * The configured recipient keys cannot produce an archive anybody can open
 * (pattern-018).
 *
 * Always fatal to the run, never degraded: there is no plaintext fallback and
 * no "seal to the ones that parsed" — ADR-0031 rule 3. What the caller does
 * with it is record a ✕ finding and say which entry was wrong, so the fix is an
 * edit rather than an investigation.
 */
final class BackupKeyringException extends RuntimeException
{
    public static function nothingConfigured(): self
    {
        return new self(
            'No backup recipient key is configured, so no archive can be written. Generate a '
            . 'keypair with tools/keypair-generator.html — the **hex** output under "Backup archive keys", not the base64 one above it — and add its public half to '
            . 'backup.recipient_public_keys in config.php — configuring a key is what switches '
            . 'nightly backups on. A plaintext backup is never written instead.'
        );
    }

    public static function malformedEntry(string $entry): self
    {
        return new self(sprintf(
            'backup.recipient_public_keys carries an entry that is not "label:hexkey": "%s". Expected a '
            . 'label of letters, digits, hyphens or underscores, a colon, then 64 hex '
            . 'characters — as the "Backup archive keys" section of tools/keypair-generator.html prints them. The base64 output higher up that page is the IBAN keypair, which the admin panel registers; the two are not interchangeable.',
            self::redact($entry)
        ));
    }

    public static function duplicateLabel(string $label): self
    {
        return new self(sprintf(
            'backup.recipient_public_keys names "%s" twice. Two recipients with one label make the '
            . 'decryptor unable to tell a holder which key to fetch, which is the one job its '
            . 'header does.',
            $label
        ));
    }

    /**
     * A malformed entry is quoted back so it can be found in the file, but a
     * *well-formed* key is 64 hex characters and quoting it whole would put a
     * public key in a log line for no benefit. Truncated either way.
     */
    private static function redact(string $entry): string
    {
        return strlen($entry) > 24 ? substr($entry, 0, 24) . '…' : $entry;
    }
}
