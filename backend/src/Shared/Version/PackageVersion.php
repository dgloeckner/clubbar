<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * Which way an upgrade package moves an installation: the no-downgrade guard
 * `upgrade.php` applies before it unpacks anything.
 *
 * Not {@see ReleaseVersion}, on purpose. That class refuses a tag without its
 * `v`, which is right for the terminal updater — it must never act on a string
 * it does not recognise — and wrong here: `1.1.7` was released without its `v`,
 * so an installation of it reports exactly that, and an installation that
 * cannot be compared would never be upgraded off it by the guard's caller.
 *
 * So the `v` is dropped from both sides before `version_compare()` sees them.
 * Without that, `version_compare('v1.1.8', '1.1.7')` is -1 — a letter sorts
 * before a digit — and every upgrade off `1.1.7` was refused as a downgrade.
 *
 * Lives in `Shared/` rather than in `upgrade.php` so it can be tested: that
 * script runs on include. `upgrade.php` pulls it out of the uploaded package
 * when the installation predates it, the same way it bootstraps its other
 * Shared classes.
 */
final class PackageVersion
{
    public const UPGRADE = 'upgrade';
    public const SAME = 'same';
    public const DOWNGRADE = 'downgrade';
    /** Either side missing, or a dev build — nothing to compare, so no verdict. */
    public const UNKNOWN = 'unknown';

    public static function compare(?string $installed, ?string $package): string
    {
        if ($installed === null || $package === null) {
            return self::UNKNOWN;
        }

        $installed = self::normalise($installed);
        $package = self::normalise($package);

        // Dev builds (e.g. "dev-abc123") can't be meaningfully compared
        if (str_starts_with($installed, 'dev') || str_starts_with($package, 'dev')) {
            return self::UNKNOWN;
        }

        $cmp = version_compare($package, $installed);
        if ($cmp > 0) {
            return self::UPGRADE;
        }

        return $cmp === 0 ? self::SAME : self::DOWNGRADE;
    }

    private static function normalise(string $version): string
    {
        $version = trim($version);

        return preg_match('/^[vV]\d/', $version) === 1 ? substr($version, 1) : $version;
    }
}
