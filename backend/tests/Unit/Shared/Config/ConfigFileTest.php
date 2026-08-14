<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\ConfigFile;
use PHPUnit\Framework\TestCase;

/**
 * The `config.php` → `$_ENV` mapping (#403).
 *
 * This used to live inside the package front controller and was moved out when
 * `bin/cron.php` needed the same thing: a CLI run has no front controller, no
 * `$_SERVER` and no `.env`, and it has to read exactly the file the web reads.
 * Two copies of the mapping would drift on the first key somebody adds, and the
 * symptom would be a cron sending with the wrong sender — or not sending.
 *
 * So what is under test here is mostly *absence*: that an installed
 * config.php which predates a feature still boots, and that a missing optional
 * section does not become a confidently wrong value.
 */
class ConfigFileTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;

        parent::tearDown();
    }

    public function test_it_maps_the_settings_the_application_boots_on(): void
    {
        ConfigFile::applyToEnvironment($this->config(), '/srv/clubbar-data');

        $this->assertSame('/srv/clubbar-data', $_ENV['DATA_DIR']);
        $this->assertSame('db.example.org', $_ENV['DB_HOST']);
        $this->assertSame('3307', $_ENV['DB_PORT']);
        $this->assertSame('clubbar', $_ENV['DB_NAME']);
        $this->assertSame('secret', $_ENV['DB_PASS']);
        $this->assertSame('production', $_ENV['APP_ENV']);
        $this->assertSame('https://bar.example.org', $_ENV['APP_URL']);
        $this->assertSame('false', $_ENV['APP_DEBUG']);
    }

    public function test_a_config_written_before_a_feature_existed_still_boots(): void
    {
        // The shape install.php has always written: no mail section, no cron
        // section. Every installation upgrading into this release has one.
        $legacy = [
            'db' => ['host' => 'localhost', 'name' => 'clubbar', 'user' => 'u', 'pass' => 'p'],
            'app' => ['env' => 'production', 'debug' => false, 'url' => 'https://example.org'],
        ];

        ConfigFile::applyToEnvironment($legacy, '/data');

        $this->assertSame('', $_ENV['MAIL_DSN'], 'absent mail configuration disables sending, silently');
        $this->assertSame('', $_ENV['CRON_SECRET'], 'absent cron secret leaves the URL trigger unmounted');
        $this->assertSame('3306', $_ENV['DB_PORT']);
        $this->assertSame('7200', $_ENV['SESSION_MAX_AGE']);
        $this->assertSame('365', $_ENV['API_TOKEN_TTL_DAYS']);
    }

    public function test_the_mail_dsn_and_cron_secret_reach_the_environment(): void
    {
        $config = $this->config() + [];
        $config['mail'] = ['dsn' => 'smtp://user:pass@mail.example.org:587'];
        $config['cron'] = ['secret' => 'abc123'];

        ConfigFile::applyToEnvironment($config, '/data');

        $this->assertSame('smtp://user:pass@mail.example.org:587', $_ENV['MAIL_DSN']);
        $this->assertSame('abc123', $_ENV['CRON_SECRET']);
    }

    public function test_the_drain_limits_are_only_published_when_the_file_sets_them(): void
    {
        // An empty value is not a limit of zero — it is no opinion, and the
        // defaults on DrainService carry the reasoning.
        unset($_ENV['MAIL_DRAIN_BATCH_SIZE'], $_ENV['MAIL_DRAIN_BUDGET_SECONDS']);

        ConfigFile::applyToEnvironment($this->config(), '/data');
        $this->assertArrayNotHasKey('MAIL_DRAIN_BATCH_SIZE', $_ENV);

        $config = $this->config();
        $config['mail'] = ['dsn' => '', 'drain_batch_size' => 10, 'drain_budget_seconds' => 20];
        ConfigFile::applyToEnvironment($config, '/data');

        $this->assertSame('10', $_ENV['MAIL_DRAIN_BATCH_SIZE']);
        $this->assertSame('20', $_ENV['MAIL_DRAIN_BUDGET_SECONDS']);
    }

    public function test_optional_session_overrides_stay_unset_when_absent(): void
    {
        // Left unset, AppConfig derives the cookie's Secure flag and writes
        // sessions into the data directory. Publishing a default here would
        // quietly take both decisions away from it.
        unset($_ENV['SESSION_COOKIE_SECURE'], $_ENV['SESSION_SAVE_PATH']);

        ConfigFile::applyToEnvironment($this->config(), '/data');

        $this->assertArrayNotHasKey('SESSION_COOKIE_SECURE', $_ENV);
        $this->assertArrayNotHasKey('SESSION_SAVE_PATH', $_ENV);
    }

    public function test_an_explicit_false_cookie_secure_is_published_as_false(): void
    {
        $config = $this->config();
        $config['session']['cookie_secure'] = false;

        ConfigFile::applyToEnvironment($config, '/data');

        $this->assertSame('false', $_ENV['SESSION_COOKIE_SECURE']);
    }

    public function test_debug_true_is_published_as_the_string_the_reader_expects(): void
    {
        $config = $this->config();
        $config['app']['debug'] = true;

        ConfigFile::applyToEnvironment($config, '/data');

        $this->assertSame('true', $_ENV['APP_DEBUG']);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return [
            'db' => [
                'host' => 'db.example.org',
                'port' => 3307,
                'name' => 'clubbar',
                'user' => 'clubbar',
                'pass' => 'secret',
            ],
            'app' => [
                'env' => 'production',
                'debug' => false,
                'url' => 'https://bar.example.org',
            ],
            'session' => [
                'max_age' => 7200,
                'regeneration_interval' => 900,
            ],
            'security' => [
                'totp_encryption_key' => 'aa',
                'iban_fingerprint_key' => 'bb',
            ],
        ];
    }
}
