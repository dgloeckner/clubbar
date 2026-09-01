<?php

declare(strict_types=1);

namespace Tests\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * The public self-registration page has to be *in* the release (#796).
 *
 * ADR-0052 decision 11 says the backend serves `/register` "as part of the
 * deployment", and in the dev and CI layouts it does — the document root there
 * *is* `backend/public`, so the directory is served without anybody arranging
 * it. The shipped package has its own document root assembled file by file by
 * `build-package.sh`, that line was never written, and the front controller
 * answers `spa.html` for every path that is not `/api/`. The result was a 200
 * with the wrong page: a member scanning the club's QR poster was shown the
 * admin login form, and nothing anywhere reported an error.
 *
 * The behavioural guard is in `tests/package/package-smoke.spec.ts`, against
 * Apache and the shipped `.htaccess`, because rewrite ordering is what was
 * wrong. These are the cheap structural halves of it, in the spirit of
 * {@see ConfigTemplatePlacementTest}: they run in the unit suite, with no
 * stack, and they fail the moment one of the three places that has to name
 * `register/` stops naming it.
 */
class RegistrationPagePlacementTest extends TestCase
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

        if ($real === false) {
            $real = '/repo/' . $relative;
        }

        return $real;
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::repositoryPath($relative));
    }

    /**
     * The page itself, where the terminal-free layout keeps it.
     */
    public function test_the_page_and_its_two_assets_exist_in_the_repository(): void
    {
        foreach (['index.html', 'register.css', 'register.js'] as $file) {
            $this->assertFileExists(
                self::repositoryPath('backend/public/register/' . $file),
                "backend/public/register/{$file} is gone; the package copies this directory wholesale"
            );
        }
    }

    /**
     * **The guard the bug would have tripped.** The build assembles the
     * document root by hand; a file it does not copy does not exist on any
     * installed release, however well it works in docker.
     */
    public function test_the_build_copies_the_page_into_the_package_root(): void
    {
        $build = self::read('scripts/build-package.sh');

        $this->assertStringContainsString(
            'cp -R "$PROJECT_ROOT/backend/public/register/." "$PKG_DIR/register/"',
            $build,
            'build-package.sh no longer ships the self-registration page (#796)'
        );
    }

    /**
     * And it goes to the package root, not inside `backend/`, which
     * `.htaccess` denies wholesale with `RewriteRule ^backend/ - [F,L]`.
     * Shipping it there would replace a login form with a 403.
     */
    public function test_the_page_does_not_ship_inside_backend(): void
    {
        $build = self::read('scripts/build-package.sh');

        $this->assertStringNotContainsString(
            '$PKG_DIR/backend/register',
            $build,
            'the page ships behind the .htaccess rule that denies /backend/ (#796)'
        );
    }

    /**
     * `/register` is a *directory*, so the `-f` rule that serves the SPA's
     * assets never matches it and the request falls through to the front
     * controller. Without an explicit rule, shipping the files changes
     * nothing — which is why this assertion is separate from the one above.
     */
    public function test_the_htaccess_serves_the_page_before_the_front_controller(): void
    {
        $htaccess = self::read('package/.htaccess');

        $this->assertStringContainsString(
            'RewriteRule ^register/?$ register/index.html [L]',
            $htaccess,
            'the shipped .htaccess no longer routes /register to the page (#796)'
        );

        $registerRule = strpos($htaccess, 'RewriteRule ^register/?$');
        $frontController = strpos($htaccess, 'RewriteRule ^ index.php [L,QSA]');

        $this->assertIsInt($registerRule);
        $this->assertIsInt($frontController);
        $this->assertLessThan(
            $frontController,
            $registerRule,
            'the front controller now claims /register before the page rule can (#796)'
        );
    }

    /**
     * The belt: a request that reaches the front controller anyway — a host
     * that rewrites differently, a rule edited later — gets the page rather
     * than the admin panel.
     */
    public function test_the_front_controller_serves_the_page_rather_than_the_spa(): void
    {
        $index = self::read('package/index.php');

        $this->assertStringContainsString(
            "\$path === '/register' || \$path === '/register/'",
            $index,
            'index.php no longer has the /register branch, so the SPA fallback claims it (#796)'
        );

        $registerBranch = strpos($index, "'/register'");
        $spaFallback = strpos($index, "spa.html");

        $this->assertIsInt($registerBranch);
        $this->assertIsInt($spaFallback);
        $this->assertLessThan(
            $spaFallback,
            $registerBranch,
            'the SPA fallback is reached before the /register branch (#796)'
        );
    }
}
