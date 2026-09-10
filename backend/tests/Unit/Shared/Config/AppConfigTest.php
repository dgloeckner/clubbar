<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use PHPUnit\Framework\TestCase;

/**
 * Covers the session cookie `Secure` flag (issue #105): without it the
 * `_session` cookie travels in clear text over plain HTTP.
 */
class AppConfigTest extends TestCase
{
    private array $serverBackup;

    /**
     * `Env::get()` falls back to the *process* environment, and the backend
     * container sets `CORS_ORIGINS` — so a test that only clears `$_ENV` would
     * read the container's value and pass or fail for reasons that have
     * nothing to do with the code.
     */
    private string|false $corsBackup;

    protected function setUp(): void
    {
        Env::reset();
        $this->serverBackup = $_SERVER;
        $this->corsBackup = getenv('CORS_ORIGINS');
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        Env::reset();
        $this->clearEnv();
        $_SERVER = $this->serverBackup;

        if ($this->corsBackup === false) {
            putenv('CORS_ORIGINS');
        } else {
            putenv('CORS_ORIGINS=' . $this->corsBackup);
        }
    }

    private function clearEnv(): void
    {
        unset(
            $_ENV['SESSION_COOKIE_SECURE'],
            $_ENV['DATA_DIR'],
            $_ENV['SESSION_SAVE_PATH'],
            $_ENV['APP_URL'],
            $_ENV['CORS_ORIGINS'],
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['SERVER_PORT'],
        );

        putenv('CORS_ORIGINS');
    }

    public function test_cookie_is_secure_when_app_url_is_https(): void
    {
        $_ENV['APP_URL'] = 'https://admin.club.de';

        $this->assertTrue((new AppConfig())->sessionCookieSecure);
    }

    public function test_cookie_is_secure_for_uppercase_https_app_url(): void
    {
        $_ENV['APP_URL'] = 'HTTPS://admin.club.de';

        $this->assertTrue((new AppConfig())->sessionCookieSecure);
    }

    public function test_cookie_is_not_secure_for_plain_http_development(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';

        $this->assertFalse(
            (new AppConfig())->sessionCookieSecure,
            'A Secure cookie is dropped over HTTP — local development and the E2E suite must keep working'
        );
    }

    public function test_cookie_is_secure_when_the_request_arrived_over_tls(): void
    {
        // APP_URL was never configured, but the browser is on HTTPS.
        $_ENV['APP_URL'] = 'http://localhost:8080';
        $_SERVER['HTTPS'] = 'on';

        $this->assertTrue((new AppConfig())->sessionCookieSecure);
    }

    public function test_https_server_var_set_to_off_does_not_count_as_tls(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';
        $_SERVER['HTTPS'] = 'off';

        $this->assertFalse((new AppConfig())->sessionCookieSecure);
    }

    public function test_cookie_is_secure_behind_a_tls_terminating_proxy(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';

        $this->assertTrue((new AppConfig())->sessionCookieSecure);
    }

    public function test_forwarded_proto_http_does_not_mark_the_cookie_secure(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

        $this->assertFalse((new AppConfig())->sessionCookieSecure);
    }

    public function test_cookie_is_secure_when_served_on_port_443(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';
        $_SERVER['SERVER_PORT'] = '443';

        $this->assertTrue((new AppConfig())->sessionCookieSecure);
    }

    public function test_explicit_true_overrides_a_plain_http_app_url(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';
        $_ENV['SESSION_COOKIE_SECURE'] = 'true';

        $this->assertTrue((new AppConfig())->sessionCookieSecure);
    }

    public function test_explicit_false_overrides_an_https_app_url(): void
    {
        $_ENV['APP_URL'] = 'https://admin.club.de';
        $_ENV['SESSION_COOKIE_SECURE'] = 'false';

        $this->assertFalse(
            (new AppConfig())->sessionCookieSecure,
            'The escape hatch must be able to switch the flag off, e.g. for a proxy setup the derivation gets wrong'
        );
    }

    public function test_explicit_override_accepts_the_usual_boolean_spellings(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';

        foreach (['1', 'on', 'yes', 'TRUE'] as $value) {
            $_ENV['SESSION_COOKIE_SECURE'] = $value;
            $this->assertTrue((new AppConfig())->sessionCookieSecure, "value: {$value}");
        }
    }

    public function test_empty_override_falls_back_to_the_derivation(): void
    {
        $_ENV['APP_URL'] = 'https://admin.club.de';
        $_ENV['SESSION_COOKIE_SECURE'] = '';

        $this->assertTrue((new AppConfig())->sessionCookieSecure);
    }

    public function test_unparseable_override_falls_back_instead_of_disabling_the_flag(): void
    {
        $_ENV['APP_URL'] = 'https://admin.club.de';
        $_ENV['SESSION_COOKIE_SECURE'] = 'ja';

        $this->assertTrue(
            (new AppConfig())->sessionCookieSecure,
            'A typo in a security flag must not silently switch it off'
        );
    }

    // ── the session cookie name (#251, ADR-0031 decision 1) ───────────

    public function test_cookie_name_gets_the_host_prefix_when_secure(): void
    {
        $_ENV['APP_URL'] = 'https://admin.club.de';

        $config = new AppConfig();

        $this->assertTrue($config->sessionCookieSecure);
        $this->assertSame('__Host-session', $config->sessionCookieName);
    }

    public function test_cookie_name_stays_plain_for_http_development(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';

        $config = new AppConfig();

        $this->assertFalse($config->sessionCookieSecure);
        $this->assertSame(
            '_session',
            $config->sessionCookieName,
            'the __Host- prefix only works on a Secure cookie — plain HTTP must keep the current name'
        );
    }

    public function test_cookie_name_follows_the_explicit_secure_override(): void
    {
        $_ENV['APP_URL'] = 'https://admin.club.de';
        $_ENV['SESSION_COOKIE_SECURE'] = 'false';

        $this->assertSame('_session', (new AppConfig())->sessionCookieName);
    }

    // ── the data directory (#245, ADR-0031 decision 2) ────────────────

    /**
     * The package front controller resolves the installer's placement and
     * hands it over in DATA_DIR. Everything written to disk — the scanned
     * mandates above all — has to follow it in one move, or half the
     * installation ends up back under a URL.
     */
    public function test_every_writable_path_follows_the_configured_data_directory(): void
    {
        $_ENV['DATA_DIR'] = '/home/club/clubbar-data';

        $config = new AppConfig();

        $this->assertSame('/home/club/clubbar-data', $config->dataDir);
        $this->assertSame('/home/club/clubbar-data/storage', $config->storageDir);
        $this->assertSame('/home/club/clubbar-data/logs', $config->logDir);
        $this->assertSame('/home/club/clubbar-data/storage/sessions', $config->sessionSavePath);
    }

    public function test_a_trailing_slash_does_not_leak_into_the_derived_paths(): void
    {
        $_ENV['DATA_DIR'] = '/home/club/clubbar-data/';

        $this->assertSame('/home/club/clubbar-data/storage', (new AppConfig())->storageDir);
    }

    /**
     * Unset, the paths must resolve to the layout a development checkout and
     * every pre-#245 installation already have: storage/ and logs/ under
     * backend/.
     */
    public function test_without_a_configured_directory_the_existing_layout_holds(): void
    {
        unset($_ENV['DATA_DIR']);
        Env::reset();

        $config = new AppConfig();
        $backend = dirname(__DIR__, 4);

        $this->assertSame($backend, $config->dataDir);
        $this->assertSame($backend . '/storage', $config->storageDir);
        $this->assertSame($backend . '/logs', $config->logDir);
    }

    public function test_an_explicit_session_path_still_wins_over_the_data_directory(): void
    {
        $_ENV['DATA_DIR'] = '/home/club/clubbar-data';
        $_ENV['SESSION_SAVE_PATH'] = '/var/lib/clubbar-sessions';

        $this->assertSame('/var/lib/clubbar-sessions', (new AppConfig())->sessionSavePath);
    }

    // ---------------------------------------------------------------------
    // Which origins may read an API response (#875)
    // ---------------------------------------------------------------------

    /**
     * The bug this replaces: `CORS_ORIGINS` defaulted to `*`, and a package
     * install had no key that could set it — so every self-hosted deployment
     * answered `Access-Control-Allow-Origin: *` and could not stop.
     */
    public function test_saying_nothing_allows_this_installation_and_nobody_else(): void
    {
        $_ENV['APP_URL'] = 'https://bar.example.org';

        $this->assertSame(['https://bar.example.org'], (new AppConfig())->corsAllowedOrigins);
    }

    /**
     * A default port is never in the `Origin` header a browser sends, so it
     * must not be in what we compare against.
     */
    public function test_the_derived_origin_is_the_form_a_browser_sends(): void
    {
        $_ENV['APP_URL'] = 'HTTPS://Bar.Example.ORG:443/clubbar/';

        $this->assertSame(['https://bar.example.org'], (new AppConfig())->corsAllowedOrigins);
    }

    public function test_a_non_default_port_is_part_of_the_origin(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8080';

        $this->assertSame(['http://localhost:8080'], (new AppConfig())->corsAllowedOrigins);
    }

    /**
     * Fail closed (ADR-0031 rule 3): a deployment that cannot say who it is
     * does not get to answer for everyone.
     */
    public function test_an_unusable_app_url_allows_nothing_rather_than_everything(): void
    {
        $_ENV['APP_URL'] = 'not a url';

        $this->assertSame([], (new AppConfig())->corsAllowedOrigins);
    }

    public function test_a_configured_list_wins_and_is_split_on_commas(): void
    {
        $_ENV['APP_URL'] = 'https://bar.example.org';
        $_ENV['CORS_ORIGINS'] = 'http://localhost:5173, http://localhost:5174';

        $this->assertSame(
            ['http://localhost:5173', 'http://localhost:5174'],
            (new AppConfig())->corsAllowedOrigins
        );
    }

    /**
     * Hand-written config carries hand-written URLs. A trailing slash that
     * matched nothing would be a CORS failure with no error anywhere on the
     * server — the browser is the only participant that sees it.
     */
    public function test_a_configured_origin_is_normalised_like_the_derived_one(): void
    {
        $_ENV['CORS_ORIGINS'] = 'https://Panel.Club.de:443/admin/';

        $this->assertSame(['https://panel.club.de'], (new AppConfig())->corsAllowedOrigins);
    }

    public function test_a_deliberate_wildcard_is_still_possible(): void
    {
        $_ENV['APP_URL'] = 'https://bar.example.org';
        $_ENV['CORS_ORIGINS'] = '*';

        $this->assertSame(['*'], (new AppConfig())->corsAllowedOrigins);
    }

    /**
     * An empty value means "not configured", not "allow nothing" — a container
     * that passes `CORS_ORIGINS=` through, and every package install, whose
     * `ConfigFile` publishes the key whether or not `config.php` names it.
     */
    public function test_an_empty_value_falls_through_to_the_derived_origin(): void
    {
        $_ENV['APP_URL'] = 'https://bar.example.org';
        $_ENV['CORS_ORIGINS'] = '  ,  ';

        $this->assertSame(['https://bar.example.org'], (new AppConfig())->corsAllowedOrigins);
    }
}
