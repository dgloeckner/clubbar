<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

/**
 * `config.php`, carried inside the sealed archive.
 *
 * ### Why the database alone is not a restore
 *
 * A club that restores only the database gets an installation it **cannot log
 * in to**. `security.totp_encryption_key` is what decrypts every admin's TOTP
 * secret, and it lives in `config.php`, not in the database — restore the rows
 * without it and every second factor fails, for every admin, with no way in.
 * `security.iban_fingerprint_key` is the same shape of problem one level down:
 * without it mandate-change detection silently stops recognising an IBAN it has
 * seen before.
 *
 * Neither is recoverable by generating a new one. They are not credentials that
 * can be reset; they are the keys the stored ciphertext was written under.
 *
 * ### Why it rides inside the `.sql` rather than beside it
 *
 * The restore path on the reference host is *paste a file into phpMyAdmin*
 * (ADR-0031: no shell, no client binaries). Anything that turns the payload
 * into two files, or into an archive format, puts a step in front of the one
 * thing an operator must be able to do under pressure.
 *
 * So the snapshot is appended to the dump as a **block of SQL comments**. The
 * result is still one valid `.sql` file: importing it whole is harmless,
 * because every line of the block is a comment. The decryptor recognises the
 * block and offers the config as a second download, so a club restoring onto a
 * *new* host gets both halves without ever seeing a container format.
 *
 * The content is base64 inside the comments rather than the file's own text.
 * `config.php` is PHP, and PHP can contain anything — including a line starting
 * `-- <<< CONFIG`. Base64 makes the block's boundaries a property of the format
 * instead of a bet on what a club never wrote in a comment.
 *
 * ### The composition risk, stated rather than hidden
 *
 * This is a real increase in what one archive is worth. `docs/deployment.md`
 * already puts it in the words this feature has to inherit: **the key archive
 * and one backup must never be the only two copies in the same building.**
 * Whoever holds a private key *and* an archive now holds the club's
 * configuration too.
 *
 * What it deliberately does **not** hand them is member IBANs. Those are sealed
 * to a separate keypair the Kassenwart holds (ADR-0036), and that private key
 * is not in `config.php` and not in this snapshot — so the separation of
 * custody that ADR-0049 decision 2 exists to preserve survives this change.
 *
 * Part of #692, epic #686.
 */
final class ConfigSnapshot
{
    /** Opens the block. Same `-- >>> …` idiom {@see DatabaseDump} uses per table. */
    public const OPEN_MARKER = '-- >>> CONFIG config.php (base64)';

    /** Closes it. */
    public const CLOSE_MARKER = '-- <<< CONFIG';

    /** Base64 characters per comment line — the MIME width, for readability in a pager. */
    private const LINE_WIDTH = 76;

    public function __construct(
        /**
         * Null when this installation's config path is not known to the caller,
         * which is the case in tests that do not care. An unreadable or absent
         * file is *not* an error: a backup that refused to run because
         * `config.php` sat outside the process's reach would be a backup that
         * stopped happening, which is strictly worse than one that carries the
         * database and says it carries nothing else.
         */
        private readonly ?string $configPath = null,
    ) {
    }

    /**
     * Is there a readable config file to carry?
     *
     * Asked separately so the header can say so without the caller re-deriving
     * it from the length of a string.
     */
    public function isAvailable(): bool
    {
        return $this->configPath !== null
            && $this->configPath !== ''
            && is_file($this->configPath)
            && is_readable($this->configPath);
    }

    /**
     * The comment block to append to a dump, or `''` when there is nothing to carry.
     */
    public function render(): string
    {
        if (!$this->isAvailable()) {
            return '';
        }

        $contents = @file_get_contents((string) $this->configPath);

        // Readable a moment ago and not now: a permissions change or a
        // concurrent edit. Same reasoning as above — carry nothing rather than
        // fail the night's backup.
        if ($contents === false) {
            return '';
        }

        $encoded = chunk_split(base64_encode($contents), self::LINE_WIDTH, "\n");

        $lines = '';
        foreach (explode("\n", rtrim($encoded, "\n")) as $line) {
            $lines .= '-- ' . $line . "\n";
        }

        return "\n" . self::OPEN_MARKER . "\n"
            . "-- The configuration this database was dumped from. Not executable SQL: every\n"
            . "-- line here is a comment, so importing this file whole is safe. Restoring onto\n"
            . "-- a new host needs this as well as the rows — without security.totp_encryption_key\n"
            . "-- every admin's second factor fails and nobody can log in.\n"
            . $lines
            . self::CLOSE_MARKER . "\n";
    }

    /**
     * Read a snapshot back out of a dump.
     *
     * The counterpart the decryptor implements in JavaScript, kept here so the
     * round trip can assert on the same bytes the club would get — and so the
     * two implementations have one definition of the format to disagree with.
     *
     * @return string|null the original file's contents, or null when the dump carries none
     */
    public static function extract(string $sql): ?string
    {
        // From the end. The block is always appended after the dump's footer, so
        // the last occurrence is the real one — and a row whose *data* happened
        // to contain the marker text could otherwise be found first and shadow
        // it. Cheap, and it removes the only way a member's note could affect
        // what a restore believes the configuration to be.
        $start = strrpos($sql, self::OPEN_MARKER . "\n");
        if ($start === false) {
            return null;
        }

        $start += strlen(self::OPEN_MARKER) + 1;

        $end = strpos($sql, self::CLOSE_MARKER, $start);
        if ($end === false) {
            return null;
        }

        $base64 = '';
        foreach (explode("\n", substr($sql, $start, $end - $start)) as $line) {
            if (!str_starts_with($line, '-- ')) {
                continue;
            }

            $payload = substr($line, 3);

            // The human-readable preamble lines are comments too. Base64's
            // alphabet excludes the space, so anything carrying one is prose.
            if ($payload === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $payload) !== 1) {
                continue;
            }

            $base64 .= $payload;
        }

        if ($base64 === '') {
            return null;
        }

        $decoded = base64_decode($base64, true);

        return $decoded === false ? null : $decoded;
    }
}
