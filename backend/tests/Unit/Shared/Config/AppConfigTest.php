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

    protected function setUp(): void
    {
        Env::reset();
        $this->serverBackup = $_SERVER;
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        Env::reset();
        $this->clearEnv();
        $_SERVER = $this->serverBackup;
    }

    private function clearEnv(): void
    {
        unset(
            $_ENV['SESSION_COOKIE_SECURE'],
            $_ENV['APP_URL'],
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['SERVER_PORT'],
        );
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
}
