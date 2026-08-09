<?php

declare(strict_types=1);

namespace App\Shared\Config;

/**
 * Where an installation keeps the three things that must never be served:
 * `config.php`, `storage/` (scanned SEPA mandates) and `logs/`.
 *
 * Until now those lived inside the document root and were kept off the web by
 * `.htaccess` alone — a `RewriteRule ^backend/` plus two `Require all denied`
 * files. `.htaccess` is honoured at the host's discretion: a tariff migration,
 * a webserver change or an `AllowOverride None` turns every one of those paths
 * into a plain URL, with no application error and nothing in the logs to
 * notice. The payload is the worst this system holds — the club's banking
 * credentials, and per member a name, an IBAN and a signature image whose
 * filename is the member UUID the admin API already hands to the browser.
 *
 * ADR-0031 decision 2 answers that by resolving a location instead of assuming
 * one: above the document root where the host has a writable parent, in it
 * where the host has none. The fallback stays supported — a tariff with no
 * writable parent must remain installable — but it is a *degraded* state the
 * deployment reports about itself rather than the silent default it is today.
 *
 * Deliberately dependency-free and free of `use` statements beyond this
 * namespace: `install.php` and `upgrade.php` run before Composer's autoloader
 * is available and `require` this file by path.
 */
final class DataDirectory
{
    /**
     * Names the data directory to the front controller.
     *
     * A `.php` file rather than a dotfile with a path in it, for the same
     * reason this class exists: if `.htaccess` is ignored, a dotfile is served
     * as text. A PHP file is executed instead, and a host that serves PHP
     * source has broken far more than this.
     */
    public const POINTER_FILE = 'data-path.php';

    /** What the installer names the directory it creates above the document root. */
    public const DIRECTORY_NAME = 'clubbar-data';

    /** Subdirectories every data directory holds. */
    public const SUBDIRECTORIES = ['storage', 'logs'];

    /**
     * The effective data directory for a deployment rooted at $documentRoot.
     *
     * The pointer wins when it names a directory that exists. Otherwise
     * `backend/` — where `storage/` and `logs/` have always been, so an
     * installation that predates the pointer resolves to exactly the layout it
     * already has.
     */
    public static function resolve(string $documentRoot): string
    {
        $pointed = self::readPointer($documentRoot);

        return $pointed ?? self::inDocumentRoot($documentRoot);
    }

    /** The fallback: inside the document root, behind the `^backend/` denial. */
    public static function inDocumentRoot(string $documentRoot): string
    {
        return rtrim($documentRoot, '/') . '/backend';
    }

    public static function pointerPath(string $documentRoot): string
    {
        return rtrim($documentRoot, '/') . '/' . self::POINTER_FILE;
    }

    /**
     * The path the pointer names, or null when there is none to honour.
     *
     * A pointer naming a directory that no longer exists is ignored rather than
     * obeyed: a half-restored backup or a reverted move must degrade to the
     * in-document-root layout, which still works, instead of taking the
     * installation down.
     */
    public static function readPointer(string $documentRoot): ?string
    {
        $pointer = self::pointerPath($documentRoot);
        if (!is_file($pointer)) {
            return null;
        }

        $path = @include $pointer;

        return is_string($path) && $path !== '' && is_dir($path) ? rtrim($path, '/') : null;
    }

    public static function writePointer(string $documentRoot, string $dataDirectory): bool
    {
        $contents = "<?php\n\n"
            . "// Written by the Club Bar installer. Names the directory holding\n"
            . "// config.php, storage/ and logs/ — see ADR-0031 decision 2.\n"
            . "// Delete this file to fall back to the in-document-root layout.\n\n"
            . 'return ' . var_export(rtrim($dataDirectory, '/'), true) . ";\n";

        return file_put_contents(self::pointerPath($documentRoot), $contents) !== false;
    }

    public static function removePointer(string $documentRoot): bool
    {
        $pointer = self::pointerPath($documentRoot);

        return !is_file($pointer) || @unlink($pointer);
    }

    /**
     * Where `config.php` belongs when the data directory is $dataDirectory.
     *
     * In the relocated layout it sits in the data directory with the rest.
     * In the fallback it keeps its historical place next to `index.php`:
     * `backend/` holds the shipped source and is not writable on every host,
     * and an in-document-root installation is the *degraded* state ADR-0031
     * decision 2 leaves exactly as it was and reports on — not one to reshuffle
     * for a protection it cannot actually gain.
     */
    public static function configPathIn(string $documentRoot, string $dataDirectory): string
    {
        return rtrim($dataDirectory, '/') === self::inDocumentRoot($documentRoot)
            ? rtrim($documentRoot, '/') . '/config.php'
            : rtrim($dataDirectory, '/') . '/config.php';
    }

    /**
     * Where `config.php` actually is for this deployment.
     *
     * The layout decides, but a config found in the *other* layout still wins
     * over one that does not exist: an installation caught between the two —
     * mid-move, or restored from a backup taken before the move — has to keep
     * booting.
     */
    public static function configPath(string $documentRoot): string
    {
        $expected = self::configPathIn($documentRoot, self::resolve($documentRoot));
        if (is_file($expected)) {
            return $expected;
        }

        $legacy = rtrim($documentRoot, '/') . '/config.php';

        return is_file($legacy) ? $legacy : $expected;
    }

    /**
     * Look for somewhere better than the document root to put the data.
     *
     * Only one candidate is considered — a sibling of the document root — and
     * it has to be provably writable. Guessing further up the tree on a host we
     * cannot see would risk writing member data somewhere the treasurer does
     * not know to back up or protect.
     *
     * @return array{path:string,outside:bool,reason:string}
     */
    public static function probe(string $documentRoot): array
    {
        $documentRoot = rtrim($documentRoot, '/');
        $parent = dirname($documentRoot);
        $fallback = [
            'path'    => self::inDocumentRoot($documentRoot),
            'outside' => false,
        ];

        if ($parent === $documentRoot || $parent === '/' || $parent === '.') {
            return $fallback + ['reason' => 'The document root has no usable parent directory on this host.'];
        }

        if (!is_dir($parent) || !is_writable($parent)) {
            return $fallback + ['reason' => "No writable directory above the document root ({$parent} is not writable)."];
        }

        $candidate = $parent . '/' . self::DIRECTORY_NAME;

        // Paranoia, not politeness: a symlinked or oddly-mounted parent that
        // resolves back inside the document root would put the mandates under a
        // URL again — the exact outcome this is here to prevent.
        if (str_starts_with($candidate . '/', $documentRoot . '/')) {
            return $fallback + ['reason' => "The directory above the document root resolves back inside it ({$candidate})."];
        }

        if (is_dir($candidate) && !is_writable($candidate)) {
            return $fallback + ['reason' => "{$candidate} exists but is not writable."];
        }

        return [
            'path'    => $candidate,
            'outside' => true,
            'reason'  => "Data will be kept in {$candidate}, outside the document root.",
        ];
    }

    /**
     * Create the data directory and its subdirectories.
     *
     * `0700` where the host allows it: on shared hosting the neighbours are the
     * threat model, and these directories hold mandates and logs.
     *
     * @return array{ok:bool,error:?string}
     */
    public static function prepare(string $dataDirectory): array
    {
        $dataDirectory = rtrim($dataDirectory, '/');

        foreach ([$dataDirectory, ...array_map(fn($d) => "{$dataDirectory}/{$d}", self::SUBDIRECTORIES)] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                return ['ok' => false, 'error' => "Could not create {$path}."];
            }
        }

        // Only the subdirectories are written to unconditionally. The data
        // directory itself needs to be writable only where `config.php` goes
        // into it, which the caller checks — in the in-document-root fallback
        // that directory is `backend/`, which holds the shipped source and is
        // read-only on plenty of installs.
        foreach (self::SUBDIRECTORIES as $subdirectory) {
            if (!is_writable("{$dataDirectory}/{$subdirectory}")) {
                return ['ok' => false, 'error' => "{$dataDirectory}/{$subdirectory} is not writable by the web server."];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Move an installation's data to $target and point the front controller at it.
     *
     * Copy-then-verify-then-remove, in that order, and `config.php` last: an
     * upgrade that dies halfway must leave an installation that still boots,
     * and the config is what decides whether it does. Nothing is deleted from
     * the old location until its copy has been read back.
     *
     * @return array{ok:bool,error:?string,moved:list<string>}
     */
    public static function relocate(string $documentRoot, string $target): array
    {
        $source = self::resolve($documentRoot);
        $target = rtrim($target, '/');

        if ($source === $target) {
            return ['ok' => true, 'error' => null, 'moved' => []];
        }

        $prepared = self::prepare($target);
        if (!$prepared['ok']) {
            return ['ok' => false, 'error' => $prepared['error'], 'moved' => []];
        }

        $moved = [];
        foreach (self::SUBDIRECTORIES as $subdirectory) {
            $from = "{$source}/{$subdirectory}";
            if (!is_dir($from)) {
                continue;
            }
            if (!self::copyTree($from, "{$target}/{$subdirectory}")) {
                return ['ok' => false, 'error' => "Failed to copy {$from}.", 'moved' => $moved];
            }
            $moved[] = $subdirectory;
        }

        $configSource = self::configPath($documentRoot);
        $configTarget = self::configPathIn($documentRoot, $target);
        if (is_file($configSource) && $configSource !== $configTarget && !is_writable(dirname($configTarget))) {
            return ['ok' => false, 'error' => dirname($configTarget) . ' is not writable.', 'moved' => $moved];
        }
        if (is_file($configSource) && $configSource !== $configTarget) {
            if (!@copy($configSource, $configTarget) || !is_file($configTarget)) {
                return ['ok' => false, 'error' => 'Failed to copy config.php.', 'moved' => $moved];
            }
            @chmod($configTarget, 0600);
            $moved[] = 'config.php';
        }

        if (!self::writePointer($documentRoot, $target)) {
            return ['ok' => false, 'error' => 'Failed to write ' . self::POINTER_FILE . '.', 'moved' => $moved];
        }

        // The copies are in place and the pointer is live, so the originals are
        // now the exposed duplicates this whole exercise exists to remove.
        foreach (self::SUBDIRECTORIES as $subdirectory) {
            self::removeTreeContents("{$source}/{$subdirectory}");
        }
        if (is_file($configSource) && $configSource !== $configTarget) {
            @unlink($configSource);
        }

        return ['ok' => true, 'error' => null, 'moved' => $moved];
    }

    private static function copyTree(string $from, string $to): bool
    {
        if (!is_dir($to) && !@mkdir($to, 0700, true) && !is_dir($to)) {
            return false;
        }

        foreach (scandir($from) ?: [] as $entry) {
            // `.htaccess` belongs to the layout, not to the data. The package
            // ships one inside storage/ and logs/ to deny access in the
            // document root; it means nothing outside it, and copying it back
            // fails on a host where those shipped files are not writable by the
            // web server — which is most of them.
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
                continue;
            }

            $source = "{$from}/{$entry}";
            $target = "{$to}/{$entry}";

            if (is_dir($source)) {
                if (!self::copyTree($source, $target)) {
                    return false;
                }
                continue;
            }

            if (!@copy($source, $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Empty a directory but keep it: the package ships `.htaccess` denials
     * inside `storage/` and `logs/`, and the fallback layout still needs them
     * if the installation is ever moved back.
     */
    private static function removeTreeContents(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
                continue;
            }

            $path = "{$directory}/{$entry}";
            if (is_dir($path)) {
                self::removeTreeContents($path);
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }
    }
}
