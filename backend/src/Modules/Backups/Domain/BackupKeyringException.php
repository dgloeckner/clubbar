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
            . 'keypair with tools/keypair-generator.html and add its public half to '
            . 'backup.public_keys in config.php. A plaintext backup is never written instead.'
        );
    }

    public static function malformedEntry(string $entry): self
    {
        return new self(sprintf(
            'backup.public_keys carries an entry that is not "label:hexkey": "%s". Expected a '
            . 'label of letters, digits, hyphens or underscores, a colon, then 64 hex '
            . 'characters — as tools/keypair-generator.html prints them.',
            self::redact($entry)
        ));
    }

    public static function duplicateLabel(string $label): self
    {
        return new self(sprintf(
            'backup.public_keys names "%s" twice. Two recipients with one label make the '
            . 'decryptor unable to tell a holder which key to fetch, which is the one job its '
            . 'header does.',
            $label
        ));
    }

    /** @param list<string> $labels */
    public static function everyKeyCompromised(array $labels): self
    {
        return new self(sprintf(
            'Every configured backup key is marked compromised (%s), so no archive can be '
            . 'written. Add a replacement key before removing the last usable one — a '
            . 'compromise is when backups matter most.',
            implode(', ', $labels)
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
