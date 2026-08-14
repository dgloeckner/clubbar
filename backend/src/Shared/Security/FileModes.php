<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Narrow the permission bits on the things that must not be world-readable,
 * and prove the installation still works at the narrower mode (#248,
 * ADR-0031 decision 4).
 *
 * Three paths carry everything this system would not survive leaking:
 * `config.php` (the database password and the key that encrypts every admin's
 * second factor), `storage/` (PHP session files — a readable one is a
 * logged-in admin) and `logs/`. The package used to ship the last two
 * `0777` and leave the first at whatever the default umask gave it, which on
 * mass hosting means every other customer on the box can read them.
 *
 * A `chmod` alone is not the fix, because a `chmod` can *break* the
 * installation: a host whose PHP process is not the user that owns the files
 * turns `0700` into "the application can no longer write its own logs", and
 * `chmod` reports success either way. So nothing here trusts a return value:
 *
 * 1. Read the current mode. Already free of group and other bits? Nothing to
 *    do — and in particular no `chmod`, which would fail on a file this process
 *    does not own and be reported as a problem that is not there.
 * 2. Apply the narrowest mode in the ladder.
 * 3. Ask the filesystem whether the path still does its job — a file must read
 *    back byte for byte, a directory must still be readable *and* writable,
 *    proven by writing to it.
 * 4. Not usable? Try the next rung. Out of rungs? Put the original mode back.
 *
 * The result is the narrowest mode this host tolerates, or an honest report
 * that it tolerated none of them — which the security self-check surfaces as a
 * finding rather than leaving as a silent pass (#247).
 *
 * Dependency-free on purpose, `use`-free beyond this namespace: `install.php`
 * and `upgrade.php` run long before Composer's autoloader exists and load this
 * file by path.
 */
final class FileModes
{
    /**
     * Modes to try on `config.php`, narrowest first.
     *
     * `0600` is the target and is what the installer gets on any sane host: it
     * writes the file itself, so it owns it. The wider rungs exist for the
     * installation that inherited a config from somewhere else — a restored
     * backup, an FTP upload — where owner-only would lock PHP out of its own
     * configuration.
     */
    public const CONFIG_LADDER = [0600, 0640, 0644];

    /**
     * Modes to try on `storage/` and `logs/`, narrowest first.
     *
     * `0777` is last rather than absent: a host that genuinely needs it is a
     * host where the alternative is an installation that cannot write a log or
     * a session file. It ends up reported, not silently accepted.
     */
    public const DIRECTORY_LADDER = [0700, 0770, 0777];

    /**
     * The one mode the document root is allowed.
     *
     * It is *served*, so it stays readable — the rule there is only that nobody
     * else may write into it, since every `.php` file below it is code the
     * webserver runs. `0755` therefore drops write bits and nothing else, which
     * is why the ladder has a single rung: it cannot take away a permission the
     * webserver was using.
     */
    public const DOCUMENT_ROOT_LADDER = [0755];

    /** Bits that must be clear for a path to be owner-only. */
    private const OTHER_THAN_OWNER = 0077;

    /** Bits that must be clear for a path only its owner can write. */
    private const WRITABLE_BY_OTHERS = 0022;

    /**
     * Tighten every path ADR-0031 decision 4 names, in one call.
     *
     * The data directory itself is deliberately left alone. In the relocated
     * layout the installer creates it `0700` already; in the in-document-root
     * fallback it is `backend/`, which holds the shipped source rather than any
     * secret and is read-only on plenty of installs.
     *
     * @param string|null  $documentRoot   Narrowed to 0755 when given.
     * @param list<string> $subdirectories Usually DataDirectory::SUBDIRECTORIES.
     * @return list<array{path:string,original:?int,applied:?int,ok:bool,restored:bool,error:?string}>
     */
    public static function hardenData(
        string $dataDirectory,
        ?string $configFile = null,
        array $subdirectories = ['storage', 'logs'],
        ?string $documentRoot = null
    ): array {
        $outcomes = [];

        if ($configFile !== null && is_file($configFile)) {
            $outcomes[] = self::narrowConfigFile($configFile);
        }

        foreach ($subdirectories as $subdirectory) {
            $path = rtrim($dataDirectory, '/') . '/' . $subdirectory;
            if (is_dir($path)) {
                $outcomes[] = self::narrowDataDirectory($path);
            }
        }

        if ($documentRoot !== null && is_dir($documentRoot)) {
            $outcomes[] = self::narrowDocumentRoot($documentRoot);
        }

        return $outcomes;
    }

    /**
     * @return array{path:string,original:?int,applied:?int,ok:bool,restored:bool,error:?string}
     */
    public static function narrowConfigFile(string $path): array
    {
        return self::narrow($path, self::CONFIG_LADDER, false, self::OTHER_THAN_OWNER);
    }

    /**
     * @return array{path:string,original:?int,applied:?int,ok:bool,restored:bool,error:?string}
     */
    public static function narrowDataDirectory(string $path): array
    {
        return self::narrow($path, self::DIRECTORY_LADDER, true, self::OTHER_THAN_OWNER);
    }

    /**
     * Take the write bits off a document root that has them for other users.
     *
     * Separate from the rest because the goal is different: not privacy — the
     * directory is served — but that nothing else on the machine can drop a
     * `.php` file where the webserver will execute it. Packages built before
     * ADR-0031 decision 4 shipped `0777` here, so this is the one thing an
     * upgrade of an existing installation can put right.
     *
     * @return array{path:string,original:?int,applied:?int,ok:bool,restored:bool,error:?string}
     */
    public static function narrowDocumentRoot(string $path): array
    {
        return self::narrow($path, self::DOCUMENT_ROOT_LADDER, true, self::WRITABLE_BY_OTHERS);
    }

    /**
     * @param list<int> $ladder    Candidate modes, narrowest first.
     * @param int       $forbidden Bits whose absence means there is nothing to do.
     * @return array{path:string,original:?int,applied:?int,ok:bool,restored:bool,error:?string}
     */
    private static function narrow(string $path, array $ladder, bool $isDirectory, int $forbidden): array
    {
        $original = self::modeOf($path);
        if ($original === null) {
            return self::outcome($path, null, null, false, false, "The mode of {$path} could not be read.");
        }

        // Nothing to take away. Returning here is not just an optimisation: a
        // `chmod` to the mode a path already has fails when this process does
        // not own it, and would be reported as a host that refuses to harden.
        if (($original & $forbidden) === 0) {
            return self::outcome($path, $original, $original, true, false, null);
        }

        // What the path has to still do afterwards, captured while it
        // demonstrably works.
        $fingerprint = $isDirectory ? null : self::fingerprint($path);
        if (!$isDirectory && $fingerprint === null) {
            return self::outcome($path, $original, $original, false, false, "{$path} could not be read.");
        }

        $attempted = false;
        foreach ($ladder as $candidate) {
            // Never widen: a rung that would grant a permission the path does
            // not already have is not a hardening step. Bit containment rather
            // than a numeric comparison, because `0700` is narrower than
            // `0644` while being the larger number.
            if (($candidate & ~$original) !== 0) {
                continue;
            }
            $attempted = true;

            if (!@chmod($path, $candidate)) {
                // chmod belongs to the owner, so this is the host answering
                // "not yours to harden" — the next rung would fail identically.
                return self::outcome(
                    $path,
                    $original,
                    $original,
                    false,
                    false,
                    "This host would not change the mode of {$path}; it is owned by another user."
                );
            }

            if (self::stillUsable($path, $isDirectory, $fingerprint)) {
                return self::outcome($path, $original, $candidate, true, false, null);
            }
        }

        if (!$attempted) {
            return self::outcome(
                $path,
                $original,
                $original,
                false,
                false,
                "{$path} is " . self::format($original) . ', which no mode this installation targets can '
                . 'narrow without also granting a permission it does not have.'
            );
        }

        // Every rung broke it. The installation matters more than the mode.
        @chmod($path, $original);
        $restored = self::modeOf($path) === $original;

        return self::outcome(
            $path,
            $original,
            $restored ? $original : self::modeOf($path),
            false,
            $restored,
            "{$path} stopped working at every mode narrower than " . self::format($original)
            . ', so the original mode was restored. PHP runs as a different user than the one that owns this path.'
        );
    }

    /**
     * Does the path still do the job it was doing before the `chmod`?
     *
     * Answered by using it, not by reading its mode back: `is_writable()` is a
     * mode-and-ownership calculation and gets a read-only mount, an ACL or an
     * `open_basedir` wrong — the exact hosts this ladder exists for.
     */
    private static function stillUsable(string $path, bool $isDirectory, ?string $fingerprint): bool
    {
        clearstatcache(true, $path);

        if (!$isDirectory) {
            return is_readable($path) && self::fingerprint($path) === $fingerprint;
        }

        if (!is_dir($path) || scandir($path) === false) {
            return false;
        }

        $probe = rtrim($path, '/') . '/.clubbar-mode-probe-' . bin2hex(random_bytes(4));
        $written = @file_put_contents($probe, "probe\n") !== false;
        @unlink($probe);

        return $written;
    }

    /** Content hash, so "still readable" means the bytes, not just the stat. */
    private static function fingerprint(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return $contents === false ? null : hash('sha256', $contents);
    }

    private static function modeOf(string $path): ?int
    {
        clearstatcache(true, $path);
        $perms = @fileperms($path);

        return $perms === false ? null : ($perms & 0777);
    }

    private static function format(int $mode): string
    {
        return sprintf('%04o', $mode);
    }

    /**
     * @return array{path:string,original:?int,applied:?int,ok:bool,restored:bool,error:?string}
     */
    private static function outcome(
        string $path,
        ?int $original,
        ?int $applied,
        bool $ok,
        bool $restored,
        ?string $error
    ): array {
        return [
            'path'     => $path,
            'original' => $original,
            'applied'  => $applied,
            'ok'       => $ok,
            'restored' => $restored,
            'error'    => $error,
        ];
    }
}
