<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * Where finished archives are pushed, as one configuration value.
 *
 * The same shape `mail.dsn` already has (ADR-0038 rule 2, ADR-0049 decision 6),
 * and for the same reason: one field the club fills in, and a storage target
 * that can be swapped without touching the producer.
 *
 *     msgraph://<tenant-id>/<client-id>@drive/<drive-id>/<folder>
 *     msgraph://<tenant-id>/<client-id>@<site>/<library>/<folder>
 *     hidrive://<user>@<host>/<absolute path>
 *
 * ## Why this is a base class and not one struct with nullable fields
 *
 * A DSN's *parts* are scheme-specific and its *use* is not. Everything outside
 * this namespace wants two things — "where am I pushing" for a log line and
 * "where does this filename land" for the upload, the listing and the retention
 * delete — and neither depends on which store answers. Everything that does
 * differ (a tenant, a drive id, a WebDAV host) belongs to exactly one subclass,
 * where the type system can insist on it.
 *
 * The rejected alternative was one `final` class holding every field of every
 * scheme, four of them always empty. It survives two schemes and rots at the
 * third: `describe()` becomes a switch, every consumer learns which fields are
 * real for which scheme, and nothing stops a Graph field being read on a
 * HiDrive DSN — the compiler having no opinion is the whole problem.
 *
 * {@see self::parse()} stays the one entry point, so no caller learns which
 * subclass it is getting until it asks.
 *
 * ## What is deliberately not in here
 *
 * The secret. `mail.dsn` carries its password for historical reasons; this one
 * does not have to, so it does not — a DSN is the value that gets pasted into
 * support threads and screenshots, and a credential that travels with it leaks
 * by ordinary helpfulness rather than by attack. A DSN that looks like it
 * carries one is refused (see {@see BackupDsnException::secretInDsn()}).
 *
 * This class parses and validates; it never connects.
 *
 * Part of #691 and #825, epic #686.
 */
abstract readonly class BackupDsn
{
    /** What this build can actually talk to. */
    public const SUPPORTED_SCHEMES = ['msgraph', 'hidrive'];

    /**
     * Named in ADR-0049 as the roadmap. `s3://` with object lock is the option
     * that closes the append-only gap *properly* rather than mitigating it, and
     * it is the one still unbuilt.
     *
     * `hidrive://` was in this list and was built early, out of order: Entra's
     * edge refuses the reference host's egress address outright — every
     * request, including an anonymous fetch of a public metadata document —
     * so the club that motivated `msgraph://` cannot use it ([#825](https://github.com/dgloeckner/clubbar/issues/825)).
     * ADR-0049's amendment records the reversal and the price: HiDrive is the
     * same vendor as the hosting, so a suspended account takes both, and the
     * periodic manual copy stays the copy that survives that.
     */
    public const RESERVED_SCHEMES = ['s3'];

    protected function __construct(
        public string $scheme,
        /** '' when archives go in the target's root */
        public string $path,
    ) {
    }

    /** One line for a log or the self-check: *which store, which folder*. */
    abstract public function describe(): string;

    /**
     * @throws BackupDsnException when the value cannot be used as a DSN
     */
    public static function parse(string $dsn): self
    {
        $dsn = trim($dsn);

        $separator = strpos($dsn, '://');
        if ($separator === false || $separator === 0) {
            throw BackupDsnException::noScheme($dsn);
        }

        $scheme = strtolower(substr($dsn, 0, $separator));
        $rest = substr($dsn, $separator + 3);

        if (in_array($scheme, self::RESERVED_SCHEMES, true)) {
            throw BackupDsnException::notBuiltYet($scheme);
        }

        if (!in_array($scheme, self::SUPPORTED_SCHEMES, true)) {
            throw BackupDsnException::unsupportedScheme($scheme, self::SUPPORTED_SCHEMES);
        }

        return match ($scheme) {
            'msgraph' => MsGraphDsn::fromRest($rest, $dsn),
            'hidrive' => HiDriveDsn::fromRest($rest, $dsn),
        };
    }

    /**
     * Where an archive of this name lands, as a path inside the target.
     *
     * Kept here rather than in the transport so the answer is the same for the
     * upload, the listing and the retention delete — three places that must
     * agree about one location or retention silently stops finding what the
     * upload wrote.
     */
    public function remotePathFor(string $filename): string
    {
        return $this->path === '' ? $filename : $this->path . '/' . $filename;
    }

    /**
     * Split `<credentials>@<target>`, refusing a secret in either half.
     *
     * On the *last* `@` rather than the first: an id will not contain one, but
     * a SharePoint site might in some tenants, and splitting on the first would
     * then move the boundary silently.
     *
     * @return array{0: string, 1: string} credentials, target
     * @throws BackupDsnException
     */
    protected static function splitCredentials(string $rest, string $dsn, string $missing, string $shape): array
    {
        $at = strrpos($rest, '@');
        if ($at === false) {
            throw BackupDsnException::missingPart($missing, $dsn, $shape);
        }

        $credentials = substr($rest, 0, $at);
        if (str_contains($credentials, ':')) {
            throw BackupDsnException::secretInDsn();
        }

        return [$credentials, substr($rest, $at + 1)];
    }
}
