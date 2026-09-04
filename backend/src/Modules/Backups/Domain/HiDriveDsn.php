<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * A HiDrive target: which backup user signs in, and which WebDAV folder receives.
 *
 *     hidrive://<user>@<host>/<absolute path>
 *     hidrive://clubbar-backup@webdav.hidrive.ionos.com/users/clubbar-backup/archives
 *
 * Every part is required, including the path — see below.
 *
 * ## Why the host is written out rather than assumed
 *
 * `webdav.hidrive.ionos.com` is the host every IONOS club will use, and hard-
 * coding it would shorten the DSN by one field. It is not hard-coded because
 * the same storage is sold by STRATO under a different name, offers a per-user
 * host (`<user>.webdav.hidrive.ionos.com`), and is exactly the kind of value a
 * provider changes once a decade — at which point a hard-coded host is a
 * release, and a configured one is an edit.
 *
 * ## Why the path is required and absolute
 *
 * A HiDrive user's WebDAV root is `/users/<username>`, so the shorter DSN
 * `hidrive://<user>@<host>/archives` would have to mean `/users/<user>/archives`
 * — a prefix invented by this class rather than written by the club. That rule
 * is invisible in the value, and it is wrong the day a club points the backup
 * user at a **shared** folder outside its own home, which it cannot then
 * express at all. So the DSN carries the path the server actually serves, and
 * nothing is prepended to it.
 *
 * The folder must also already exist: {@see \App\Modules\Backups\Transport\HiDriveWebDavTransport}
 * refuses to create it. A `MKCOL` on a typo would turn a wrong DSN into a
 * *successful* upload into a folder nobody is watching, which is ADR-0049's
 * founding failure — believing you have off-site backups — reached by a typo.
 *
 * Part of #825, epic #686.
 */
final readonly class HiDriveDsn extends BackupDsn
{
    /** Quoted back at a club whose DSN is missing a part. */
    public const SHAPE = 'hidrive://<user>@<host>/<absolute path>';

    private function __construct(
        /** The backup user. Never printed by {@see self::describe()} */
        public string $username,
        public string $host,
        string $path,
    ) {
        parent::__construct('hidrive', $path);
    }

    /**
     * @internal the scheme branch of {@see BackupDsn::parse()}
     * @throws BackupDsnException
     */
    public static function fromRest(string $rest, string $dsn): self
    {
        [$username, $target] = self::splitCredentials($rest, $dsn, 'user name', self::SHAPE);

        if ($username === '' || str_contains($username, '/')) {
            throw BackupDsnException::missingPart('user name', $dsn, self::SHAPE);
        }

        [$host, $path] = array_pad(explode('/', $target, 2), 2, '');

        if ($host === '') {
            throw BackupDsnException::missingPart('host', $dsn, self::SHAPE);
        }

        $path = trim($path, '/');
        if ($path === '') {
            // Unlike the Graph DSN, whose folder is optional because a library
            // root is a perfectly good place for archives. A WebDAV root is the
            // backup user's whole home, and writing archives into it would make
            // the retention delete's blast radius that home directory.
            throw BackupDsnException::missingPart('folder path', $dsn, self::SHAPE);
        }

        return new self($username, $host, $path);
    }

    /**
     * One line for a log or the self-check: *which folder am I pushing to*.
     *
     * The host and the path, never the user name. It is half of a credential,
     * and this string is written to every log line, shown on the backups page
     * and mailed — all three of which end up in screenshots and support
     * threads. The path usually contains the user name anyway for a
     * home-directory DSN, which is unavoidable; what this does not do is print
     * it *as the identity*.
     */
    public function describe(): string
    {
        return sprintf('%s://%s/%s', $this->scheme, $this->host, $this->path);
    }

    /**
     * The collection archives live in, as an absolute `https://` URL.
     *
     * Path segments are encoded one at a time: `rawurlencode()` on the whole
     * path would escape the separators too and ask the server for one
     * ludicrously named file.
     */
    public function collectionUrl(): string
    {
        $segments = array_map('rawurlencode', explode('/', $this->path));

        return 'https://' . $this->host . '/' . implode('/', $segments);
    }

    /** Where one archive of this name lives, as an absolute `https://` URL. */
    public function urlFor(string $filename): string
    {
        return $this->collectionUrl() . '/' . rawurlencode($filename);
    }
}
