<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Safe removal of a directory a test created.
 *
 * Test cleanup is the one place in a suite that deletes without being asked
 * to, and the one place that runs even when the test did not: PHPUnit calls
 * `tearDown()` after a *skipped* test too, at which point the property holding
 * the path may never have been assigned.
 *
 * `DeployRequestScriptTest` is the case that proved it. Its `setUp()` skipped
 * before assigning `$docRoot` on any machine without `jq`, and its cleanup then
 * ran `glob('' . '/*')` — the **root directory** — unlinking `/bin`, `/lib`,
 * `/lib64`, `/sbin` and `/entrypoint`, which are symlinks and which `unlink()`
 * removes without complaint. Losing `/lib64` takes the ELF interpreter with it,
 * so every dynamically linked binary in the backend container stopped being
 * executable: `docker compose exec` and the healthcheck both failed with `no
 * such file or directory` while the already-running php-fpm carried on serving.
 *
 * Two conditions, both required, both cheap: the path is non-empty, and it
 * resolves to somewhere *under* the system temp directory. Anything else is
 * left alone rather than deleted.
 */
trait TempTree
{
    /** Create a uniquely named temp directory and return its path. */
    protected static function makeTempTree(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * Delete a temp directory and its immediate contents.
     *
     * A no-op for an empty path, a path that does not exist, or any path
     * outside the system temp directory — including `/` itself.
     */
    protected static function removeTempTree(string $path): void
    {
        $tmp = realpath(sys_get_temp_dir());
        $target = $path === '' ? false : realpath($path);

        if ($tmp === false || $target === false || !str_starts_with($target, $tmp . DIRECTORY_SEPARATOR)) {
            return;
        }

        foreach (glob($target . '/*') ?: [] as $entry) {
            if (is_link($entry) || is_file($entry)) {
                unlink($entry);
            } elseif (is_dir($entry)) {
                self::removeTempTree($entry);
            }
        }

        if (is_dir($target)) {
            rmdir($target);
        }
    }
}
