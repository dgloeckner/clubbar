<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * A Microsoft 365 target: which tenant signs in, and which drive receives.
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
 * Part of #691, epic #686.
 */
final readonly class MsGraphDsn extends BackupDsn
{
    /** Quoted back at a club whose DSN is missing a part. */
    public const SHAPE = 'msgraph://<tenant-id>/<client-id>@<site>/<library>/<folder>';

    /** The first target segment that means "what follows is a drive id". */
    private const DRIVE_MARKER = 'drive';

    private function __construct(
        public string $tenantId,
        public string $clientId,
        /** '' when the DSN names a drive directly */
        public string $site,
        /** '' when the DSN names a drive directly */
        public string $library,
        /** '' when the DSN names a site and library instead */
        public string $driveId,
        string $path,
    ) {
        parent::__construct('msgraph', $path);
    }

    /**
     * @internal the scheme branch of {@see BackupDsn::parse()}
     * @throws BackupDsnException
     */
    public static function fromRest(string $rest, string $dsn): self
    {
        [$credentials, $target] = self::splitCredentials($rest, $dsn, 'site', self::SHAPE);

        [$tenantId, $clientId] = array_pad(explode('/', $credentials, 2), 2, '');
        if ($tenantId === '') {
            throw BackupDsnException::missingPart('tenant id', $dsn, self::SHAPE);
        }
        if ($clientId === '') {
            throw BackupDsnException::missingPart('client id', $dsn, self::SHAPE);
        }

        $targetParts = explode('/', trim($target, '/'));
        $first = array_shift($targetParts) ?? '';

        if ($first === '') {
            throw BackupDsnException::missingPart('site', $dsn, self::SHAPE);
        }

        if (strcasecmp($first, self::DRIVE_MARKER) === 0) {
            $driveId = array_shift($targetParts) ?? '';
            if ($driveId === '') {
                throw BackupDsnException::missingPart('drive id', $dsn, self::SHAPE);
            }

            return new self($tenantId, $clientId, '', '', $driveId, implode('/', $targetParts));
        }

        $library = array_shift($targetParts) ?? '';
        if ($library === '') {
            throw BackupDsnException::missingPart('library', $dsn, self::SHAPE);
        }

        return new self($tenantId, $clientId, $first, $library, '', implode('/', $targetParts));
    }

    /** Does this DSN name the drive outright, or a site and library to resolve? */
    public function addressesDriveDirectly(): bool
    {
        return $this->driveId !== '';
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
