<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

use RuntimeException;

/**
 * `backup.dsn` was filled in and cannot be used (pattern-018).
 *
 * Always fatal to the *upload*, never to the archive: a club with a broken DSN
 * still gets a sealed archive on the webspace, and the run reports that the
 * remote could not be reached. The one thing this must never become is silence
 * — a malformed DSN that read as "no remote configured" would leave a club
 * believing its archives are off the host when they are not, which is the
 * belief ADR-0049 exists to destroy.
 *
 * Part of #691 and #825, epic #686.
 */
final class BackupDsnException extends RuntimeException
{
    public static function noScheme(string $dsn): self
    {
        return new self(sprintf(
            'backup.dsn is not a DSN: "%s". It must begin with a scheme, as in '
            . 'hidrive://<user>@<host>/<absolute path> or '
            . 'msgraph://<tenant-id>/<client-id>@<site>/<library>/<folder>.',
            self::redact($dsn)
        ));
    }

    /** @param list<string> $supported */
    public static function unsupportedScheme(string $scheme, array $supported): self
    {
        return new self(sprintf(
            'backup.dsn uses the scheme "%s", which this build does not know. Supported: %s.',
            $scheme,
            implode(', ', $supported)
        ));
    }

    /**
     * The roadmap schemes get their own message.
     *
     * ADR-0049 names `s3://` — the option that closes the append-only gap
     * properly rather than mitigating it — as the transport still unbuilt. A
     * club that read the ADR and configured it has not made a mistake — it is
     * early — and telling it so is the difference between waiting for a release
     * and hunting a typo.
     */
    public static function notBuiltYet(string $scheme): self
    {
        return new self(sprintf(
            'backup.dsn uses "%s://", which ADR-0049 names as a planned transport but which is '
            . 'not built yet. hidrive:// and msgraph:// work today; leave backup.dsn empty until '
            . 'then and archives stay on the webspace, with the periodic manual copy as the '
            . 'off-site one.',
            $scheme
        ));
    }

    /**
     * @param string $shape the scheme's own documented shape — `MsGraphDsn::SHAPE`
     *        or `HiDriveDsn::SHAPE`. Quoting the *other* scheme's shape at a club
     *        is how "backup.dsn is invalid" stops being an edit of one word.
     */
    public static function missingPart(string $part, string $dsn, string $shape): self
    {
        return new self(sprintf(
            'backup.dsn names no %s: "%s". The shape is %s.',
            $part,
            self::redact($dsn),
            $shape
        ));
    }

    /**
     * A credential inside the DSN.
     *
     * Refused rather than accepted, because a DSN is the value that gets pasted
     * into support threads, issue reports and screenshots. `mail.dsn` carries
     * its password for historical reasons; this one does not have to, so it
     * does not — `backup.remote_secret` is its own key.
     */
    public static function secretInDsn(): self
    {
        return new self(
            'backup.dsn carries what looks like a password. Put the remote\'s secret in '
            . 'backup.remote_secret instead: a DSN gets pasted into support threads and '
            . 'screenshots, and a credential that travels with it leaks by ordinary '
            . 'helpfulness rather than by attack.'
        );
    }

    /**
     * Enough to identify the line in `config.php`, never the whole value.
     *
     * The tenant and client ids are not secrets, but a full DSN in a log line
     * is noise — and if somebody *has* put a secret in it, this is the one path
     * that would otherwise print it.
     */
    private static function redact(string $dsn): string
    {
        $dsn = trim($dsn);

        return strlen($dsn) > 32 ? substr($dsn, 0, 32) . '…' : $dsn;
    }
}
