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
            'RewriteRule ^register/$ register/index.html [L]',
            $htaccess,
            'the shipped .htaccess no longer routes /register/ to the page (#796)'
        );

        $registerRule = strpos($htaccess, 'RewriteRule ^register');
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
     * **The slashless form redirects rather than being rewritten in place**
     * (#812).
     *
     * `PosterSecret::PATH` is `/register`, so that is the URL on every QR
     * poster. An internal rewrite leaves the browser at `/register`, where the
     * page's own `./register.css` and `./register.js` resolve to
     * `/register.css` and `/register.js` — siblings that are not files, so the
     * front controller answers with the SPA's HTML and the form renders
     * unstyled and inert, at 200, with nothing logged anywhere.
     *
     * mod_dir issues exactly this redirect for a directory; taking the path
     * over with a rule is what removed it, which is why the docker layout —
     * where nothing claims `/register` — never showed the failure.
     */
    public function test_the_htaccess_redirects_the_slashless_poster_url(): void
    {
        $htaccess = self::read('package/.htaccess');

        $this->assertStringContainsString(
            'RewriteRule ^register$ /register/ [R=301,L]',
            $htaccess,
            'the poster URL no longer redirects, so the page loads its assets from the wrong path (#812)'
        );

        $this->assertStringNotContainsString(
            'RewriteRule ^register/?$',
            $htaccess,
            'a rule matching both forms rewrites the slashless one in place again (#812)'
        );
    }

    /**
     * The page's two assets have stable names and no build step, so the
     * blanket year-long expiry that is right for the SPA's content-hashed
     * `assets/` is what strands a phone on last year's stylesheet (#812).
     */
    public function test_the_htaccess_does_not_cache_the_page_assets_for_a_year(): void
    {
        $htaccess = self::read('package/.htaccess');

        $carveOut = strpos($htaccess, '<FilesMatch "^register\.(css|js)$">');

        $this->assertIsInt(
            $carveOut,
            'the onboarding assets are back under the one-year expiry (#812)'
        );
        $this->assertStringContainsString(
            'Cache-Control "no-cache, must-revalidate"',
            substr($htaccess, $carveOut),
            'the carve-out no longer makes the assets revalidate (#812)'
        );
    }

    /**
     * And the reference the browser asks for changes when the file does —
     * which is the only thing that reaches a cache already holding a copy
     * stamped with a year.
     */
    public function test_the_build_stamps_the_assets_with_their_content_hash(): void
    {
        $build = self::read('scripts/build-package.sh');

        $this->assertStringContainsString('stamp_register_asset register.css', $build);
        $this->assertStringContainsString('stamp_register_asset register.js', $build);

        // The stamp rewrites exactly this reference form. If the page ever
        // writes its `<link>` differently, the build stops rather than
        // silently shipping an unversioned URL — this asserts the two still
        // agree, so that guard is never the first thing to find out.
        $page = self::read('backend/public/register/index.html');
        foreach (['register.css', 'register.js'] as $asset) {
            $this->assertStringContainsString(
                '"./' . $asset . '"',
                $page,
                "index.html no longer references \"./{$asset}\"; the build's cache-busting stamp cannot match it (#812)"
            );
        }
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
            "\$path === '/register/'",
            $index,
            'index.php no longer has the /register/ branch, so the SPA fallback claims it (#796)'
        );

        // And the slashless poster URL is sent on rather than served in place,
        // for the reason the .htaccess rule above is two rules (#812).
        $this->assertStringContainsString(
            "header('Location: /register/', true, 301)",
            $index,
            'the front controller serves /register in place again, so its assets resolve to the root (#812)'
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
