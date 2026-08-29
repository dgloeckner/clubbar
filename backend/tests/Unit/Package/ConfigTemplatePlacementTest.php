<?php

declare(strict_types=1);

namespace Tests\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * Where the `config.php` template lives in an installed release (#751).
 *
 * `config.sample.php` is not documentation the package ships for reading — it
 * is the template {@see \App\Shared\Config\ConfigWriter} substitutes answers
 * into, which is why it has to be on disk long after the install itself: the
 * mail, backup and scheduler screens are all reachable through
 * `install.php?update=1` months later.
 *
 * It used to ship next to `index.php`. Nothing ever fetched it over HTTP — the
 * installer reads it off the disk — so its place in the document root bought
 * nothing and left a URL, on a file whose own header tells the operator never
 * to leave a copy next to `index.php`. Worse, it outlived the script that
 * needs it: `docs/deployment.md` tells clubs to delete `install.php` after
 * setup, and the template stayed behind for the life of the installation.
 *
 * So it ships inside `backend/`, denied wholesale by the shipped `.htaccess`
 * and beside `backend/config.php`, which is where the real config lands on a
 * host with no writable parent directory (ADR-0031 decision 2).
 *
 * These are structural guards, for the same reason as
 * {@see InstallerStructureTest}: the installer cannot be executed by a unit
 * test, and the build script even less so. What can be checked is that the two
 * halves still name the same path — a build that ships the template somewhere
 * the installer does not look produces a wizard that fails on the screen where
 * a club types its database password.
 */
class ConfigTemplatePlacementTest extends TestCase
{
    /**
     * A path in the repository, whether phpunit runs from a checkout or from
     * the container the workflow documents — which mounts the repo at /repo and
     * the backend at /app, so `../` out of the backend resolves to neither.
     */
    private static function repositoryPath(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/../' . $relative;
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            $real = '/repo/' . $relative;
        }

        return $real;
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::repositoryPath($relative));
    }

    /** Where `install.php` looks for the template, relative to the document root. */
    private static function installerTemplatePath(): string
    {
        preg_match(
            "/const INSTALLER_CONFIG_TEMPLATE = __DIR__ \. '\/([^']+)';/",
            self::read('package/install.php'),
            $m
        );

        self::assertNotEmpty($m, 'install.php no longer declares INSTALLER_CONFIG_TEMPLATE');

        return $m[1];
    }

    /** Where `build-package.sh` puts it, relative to the package root. */
    private static function shippedTemplatePath(): string
    {
        preg_match(
            '/config\.sample\.php" "\$PKG_DIR\/([^"]+)"/',
            self::read('scripts/build-package.sh'),
            $m
        );

        self::assertNotEmpty($m, 'build-package.sh no longer ships config.sample.php at all');

        return $m[1];
    }

    /**
     * **The guard the whole change rests on.** A template the build puts
     * somewhere the installer does not look is a wizard that dies on step 2,
     * on a host nobody can reproduce, with the club's database password in the
     * form it was submitting.
     */
    public function test_the_installer_reads_the_template_from_where_the_build_ships_it(): void
    {
        $this->assertSame(
            self::shippedTemplatePath(),
            self::installerTemplatePath(),
            'install.php and build-package.sh disagree about where config.sample.php lives'
        );
    }

    /**
     * And that agreed path is not in the document root.
     *
     * `backend/` specifically: `.htaccess` denies it with a single rewrite
     * rule, which is the same protection `backend/config.php` relies on.
     */
    public function test_the_template_ships_inside_backend(): void
    {
        $this->assertSame(
            'backend/config.sample.php',
            self::installerTemplatePath(),
            'the template is back in the document root, where it is a URL for no reason (#751)'
        );
    }

    /**
     * No second copy sneaks back in beside `index.php`. The build has one line
     * for this file; a second `cp` is how the old placement would return
     * without the guard above ever going red.
     */
    public function test_the_build_ships_exactly_one_copy_of_the_template(): void
    {
        $build = self::read('scripts/build-package.sh');

        $this->assertSame(
            1,
            substr_count($build, 'config.sample.php" "$PKG_DIR'),
            'build-package.sh copies config.sample.php more than once'
        );
        $this->assertStringNotContainsString(
            'config.sample.php" "$PKG_DIR/config.sample.php"',
            $build,
            'the template is being shipped to the document root again (#751)'
        );
    }

    /**
     * Every writer in the installer goes through the constant.
     *
     * There are five `ConfigWriter` constructions — database, mail, backup,
     * scheduler and the cron-secret rotation — and each one used to spell the
     * path out. One left behind pointing at the old location is a screen that
     * throws where the other four work, which is exactly the kind of defect a
     * club hits months after installing.
     */
    public function test_every_config_write_resolves_the_template_through_the_constant(): void
    {
        $installer = self::read('package/install.php');

        $this->assertSame(
            substr_count($installer, 'new ConfigWriter('),
            substr_count($installer, 'new ConfigWriter(INSTALLER_CONFIG_TEMPLATE)'),
            'an installer screen builds a ConfigWriter on a hand-written path'
        );
        $this->assertStringNotContainsString(
            "__DIR__ . '/config.sample.php'",
            $installer,
            'install.php still looks for the template beside itself (#751)'
        );
    }

    /**
     * An installation upgraded from an older release must lose its copy, by
     * every route an upgrade can take.
     *
     * The ZIP route's stale sweep in `extractPackage()` deletes anything the
     * new package does not ship — so the file must *not* be on its protected
     * list. The other two routes delete nothing on their own: `README.txt`
     * says to upload the new release over the old one, and
     * `docs/deployment.md` then sends the club to `install.php?update=1`
     * rather than to `upgrade.php`. Both of those end at a migrate step, and
     * both migrate steps sweep.
     */
    public function test_every_upgrade_route_removes_the_document_root_copy(): void
    {
        $upgrade = self::read('package/upgrade.php');

        preg_match('/\$protectedFiles = array_merge\(\$excluded, \[(.*?)\]\);/s', $upgrade, $m);
        $this->assertNotEmpty($m, 'the stale sweep no longer has a protected-file list');
        $this->assertStringNotContainsString(
            'config.sample.php',
            $m[1],
            'protecting the old copy leaves it in the document root for the life of the installation'
        );

        $this->assertSame(
            2,
            substr_count($upgrade, 'RetiredFiles::sweep('),
            'upgrade.php must sweep on both of its migrate paths — the wizard and the API'
        );
        $this->assertStringContainsString(
            'RetiredFiles::sweep(',
            self::read('package/install.php'),
            'the manual upgrade route documented in docs/deployment.md ends at install.php?update=1, '
            . 'which would then leave the old copy in place'
        );
    }

    /**
     * Both scripts run before Composer exists and load shared classes by path,
     * and `upgrade.php` is uploaded on its own ahead of the package it
     * extracts. A `use` with no `require_once` is a fatal error on the one run
     * that matters; a `require_once` with no `bootstrapSharedClass()` is a
     * fatal error on an installation whose `backend/src` predates the class.
     */
    public function test_both_scripts_load_the_sweep_by_path(): void
    {
        $path = 'backend/src/Shared/Config/RetiredFiles.php';

        foreach (['package/install.php', 'package/upgrade.php'] as $script) {
            $this->assertStringContainsString(
                "require_once __DIR__ . '/" . $path . "'",
                self::read($script),
                $script . ' uses RetiredFiles without requiring it by path'
            );
        }

        $this->assertStringContainsString(
            "bootstrapSharedClass(__DIR__, __DIR__ . '/.upgrade-package.zip', '" . $path . "')",
            self::read('package/upgrade.php'),
            'an upgrade.php uploaded ahead of its package would fatal on the require above'
        );
    }

    /**
     * The `.htaccess` denial stays, and it is not the load-bearing part.
     *
     * A club that unpacked an older release by hand, or put the file back, is
     * covered by the `FilesMatch` — the belt for a host that honours it. The
     * braces is that the release no longer puts the file there at all.
     */
    public function test_a_stray_copy_in_the_document_root_is_still_denied(): void
    {
        $this->assertStringContainsString(
            'config(\.sample)?\.php',
            self::read('package/.htaccess'),
            'a copy left over from an older release would be served as source'
        );
    }
}
