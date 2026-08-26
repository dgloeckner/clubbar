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
 *
 * The folder is optional; everything else is not.
 *
 * ## Two ways to name the target, and the first one is the one to use
 *
 * `drive/<drive-id>` is what the onboarding script prints, and it is preferred
 * for two reasons learned from running it against a real tenant
 * ([`docs/m365-backup-target.md`](../../../../../docs/m365-backup-target.md)):
 * a drive id needs **no resolution at all**, so a run makes two fewer requests
 * and cannot fail on a name; and a library's *display* name is localised by the
 * tenant, so `Dokumente` and `Documents` are the same library in two tenants
 * and `Freigegebene Dokumente` is a different one in the same tenant.
 *
 * `<site>/<library>` stays supported because a drive id is a hundred opaque
 * characters that nobody can sanity-check by eye, and a club that has lost the
 * script's output can still write the site and library it can see in
 * SharePoint. It costs two extra requests per run and one failure mode; that
 * is the trade, stated rather than hidden.
 *
 * ## Parsed by hand rather than by `parse_url()`
 *
 * `parse_url()` would read the authority of the string above as the tenant
 * alone and hand back the rest as a path, because the first `/` ends the
 * authority — so the client id and the site would arrive glued together in a
 * field that means neither. The documented shape is fixed and small, so
 * splitting it explicitly is both shorter and able to say *which part* is
 * missing, which is what turns "backup.dsn is invalid" into an edit of one
 * word.
 *
 * ## What is deliberately not in here
 *
 * The client secret. `mail.dsn` carries its password for historical reasons;
 * this one does not have to, so it does not — a DSN is the value that gets
 * pasted into support threads and screenshots, and a credential that travels
 * with it leaks by ordinary helpfulness rather than by attack. A DSN that looks
 * like it carries one is refused (see {@see BackupDsnException::secretInDsn()}).
 *
 * This class parses and validates; it never connects.
 *
 * Part of #691, epic #686.
 */
final readonly class BackupDsn
{
    /** What this build can actually talk to. */
    public const SUPPORTED_SCHEMES = ['msgraph'];

    /**
     * Named in ADR-0049 as the roadmap, in that order: `s3://` with object lock
     * is the option that closes the append-only gap *properly* rather than
     * mitigating it, and `hidrive://` comes last because it is the same vendor
     * as the hosting — a suspended account would take both.
     */
    public const RESERVED_SCHEMES = ['s3', 'hidrive'];

    /** The first target segment that means "what follows is a drive id". */
    private const DRIVE_MARKER = 'drive';

    private function __construct(
        public string $scheme,
        public string $tenantId,
        public string $clientId,
        /** '' when the DSN names a drive directly */
        public string $site,
        /** '' when the DSN names a drive directly */
        public string $library,
        /** '' when the DSN names a site and library instead */
        public string $driveId,
        public string $path,
    ) {
    }

    /** Does this DSN name the drive outright, or a site and library to resolve? */
    public function addressesDriveDirectly(): bool
    {
        return $this->driveId !== '';
    }

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

        // The *last* `@` rather than the first: an id will not contain one, but
        // a site might in some tenants, and splitting on the first would then
        // move the boundary silently.
        $at = strrpos($rest, '@');
        if ($at === false) {
            throw BackupDsnException::missingPart('site', $dsn);
        }

        $credentials = substr($rest, 0, $at);
        $target = substr($rest, $at + 1);

        if (str_contains($credentials, ':')) {
            throw BackupDsnException::secretInDsn();
        }

        [$tenantId, $clientId] = array_pad(explode('/', $credentials, 2), 2, '');
        if ($tenantId === '') {
            throw BackupDsnException::missingPart('tenant id', $dsn);
        }
        if ($clientId === '') {
            throw BackupDsnException::missingPart('client id', $dsn);
        }

        $targetParts = explode('/', trim($target, '/'));
        $first = array_shift($targetParts) ?? '';

        if ($first === '') {
            throw BackupDsnException::missingPart('site', $dsn);
        }

        if (strcasecmp($first, self::DRIVE_MARKER) === 0) {
            $driveId = array_shift($targetParts) ?? '';
            if ($driveId === '') {
                throw BackupDsnException::missingPart('drive id', $dsn);
            }

            return new self($scheme, $tenantId, $clientId, '', '', $driveId, implode('/', $targetParts));
        }

        $library = array_shift($targetParts) ?? '';
        if ($library === '') {
            throw BackupDsnException::missingPart('library', $dsn);
        }

        return new self($scheme, $tenantId, $clientId, $first, $library, '', implode('/', $targetParts));
    }

    /**
     * Where an archive of this name lands, as a path inside the library.
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
     * One line for a log or the self-check: *which site am I pushing to*.
     *
     * Not the whole DSN. The tenant and client ids are not secrets, but they
     * are noise in a log line, and the value that gets printed is the value
     * that ends up in a screenshot.
     */
    public function describe(): string
    {
        $target = $this->addressesDriveDirectly()
            // A drive id is ~100 opaque characters and identifies nothing to a
            // reader; its tail is enough to tell two configured targets apart,
            // which is all a log line is being asked to do.
            ? 'drive …' . substr($this->driveId, -8)
            : $this->site . '/' . $this->library;

        return sprintf(
            '%s://%s%s',
            $this->scheme,
            $target,
            $this->path === '' ? '' : '/' . $this->path
        );
    }

    /**
     * How Graph is asked for this site — only meaningful for a site-addressed DSN.
     *
     * A site segment is one DSN path segment, so a server-relative path is
     * written with colons instead of slashes:
     *
     *     frgs.sharepoint.com                 → the root site
     *     frgs.sharepoint.com:sites:Backups   → https://frgs.sharepoint.com/sites/Backups
     *
     * The second form is the one to use, and the onboarding guide says so in
     * stronger words: `Sites.Selected` is granted per **site collection**, so
     * granting on the root site would hand this app write access to the club
     * intranet and every library under it.
     */
    public function graphSiteSelector(): string
    {
        $parts = array_values(array_filter(explode(':', $this->site), static fn (string $p): bool => $p !== ''));
        $host = array_shift($parts) ?? '';

        if ($parts === []) {
            return rawurlencode($host);
        }

        // `{hostname}:/{server-relative-path}:` — the trailing colon is what
        // lets `/drives` be appended to it without Graph reading it as more path.
        return rawurlencode($host) . ':/' . implode('/', array_map('rawurlencode', $parts)) . ':';
    }
}
