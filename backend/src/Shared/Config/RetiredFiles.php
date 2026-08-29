<?php

declare(strict_types=1);

namespace App\Shared\Config;

/**
 * Files an earlier release put in the document root that this one does not
 * ship there any more, removed when an installation upgrades.
 *
 * ## Why an upgrade has to do this at all
 *
 * A release is unpacked *over* an installation. Both upgrade routes add files
 * and neither removes one: `README.txt` tells a club to upload the new ZIP
 * over the old one, and `docs/deployment.md` sends it to `install.php?update=1`
 * afterwards. So a file that stops shipping does not stop existing — it stays
 * in the document root, and therefore stays a URL, for the life of the
 * installation. (`upgrade.php`'s ZIP route sweeps stale files itself; this is
 * what covers the other two.)
 *
 * ## By name, never by inference
 *
 * The obvious implementation compares the document root against the package's
 * file list and deletes the difference. This does not, deliberately: it runs
 * against a live installation that also holds `config.php`, `data-path.php`,
 * whatever the host put there and whatever the club uploaded. An inferred list
 * is one bug away from deleting an installation's own data, and the bug is
 * silent until somebody needs the file. A fixed list of basenames cannot grow
 * an entry the author did not write.
 *
 * The same reasoning bounds the paths: {@see sweep()} refuses an empty document
 * root and any name carrying a separator, so no entry can escape the directory
 * it was handed — the failure mode `Tests\Support\TempTree` exists for, one
 * unlink at a time.
 *
 * Deliberately dependency-free and free of `use` statements beyond this
 * namespace: `install.php` and `upgrade.php` run before Composer's autoloader
 * is available and `require` this file by path.
 */
final class RetiredFiles
{
    /**
     * Basenames only, relative to the document root.
     *
     * `config.sample.php` (#751) is the `config.php` template `ConfigWriter`
     * substitutes into. The installer reads it off the disk and no browser ever
     * requests it, so its place beside `index.php` bought a URL and nothing
     * else — on a file that outlived the `install.php` clubs are told to
     * delete. It ships as `backend/config.sample.php` instead.
     *
     * @var list<string>
     */
    public const RETIRED = [
        'config.sample.php',
    ];

    /**
     * Remove whichever retired files this installation still has.
     *
     * Silent, like the file-mode hardening an upgrade also does: none of these
     * files is a secret and none is load-bearing, so a host that refuses the
     * unlink is not a reason to fail an upgrade over.
     *
     * @return list<string> the names actually removed, for a caller that wants
     *         to report them
     */
    public static function sweep(string $documentRoot): array
    {
        if ($documentRoot === '' || !is_dir($documentRoot)) {
            return [];
        }

        $removed = [];

        foreach (self::RETIRED as $name) {
            if ($name === '' || strpbrk($name, '/\\') !== false || $name === '.' || $name === '..') {
                continue;
            }

            $path = rtrim($documentRoot, '/') . '/' . $name;

            // is_file() is true for a symlink to a file and unlink() removes
            // the link rather than its target, which is the behaviour wanted:
            // whatever a retired name points at, the name is what goes.
            if (is_file($path) && @unlink($path)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }
}
