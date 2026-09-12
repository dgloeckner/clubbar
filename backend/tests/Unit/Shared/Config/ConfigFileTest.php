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

    /**
     * A package install's whole environment is `config.php`, so a key that is
     * not mapped here cannot be set at all. `CORS_ORIGINS` was one (#875):
     * `ServiceFactory` read it with a `*` default, nothing published it, and
     * every self-hosted deployment answered `Access-Control-Allow-Origin: *`
     * with no way to change it.
     */
    public function test_the_allowed_browser_origins_reach_the_environment(): void
    {
        $config = $this->config();
        $config['app']['cors_origins'] = ['https://panel.example.com', 'https://terminal.example.com'];

        ConfigFile::applyToEnvironment($config, '/srv/clubbar-data');

        $this->assertSame('https://panel.example.com,https://terminal.example.com', $_ENV['CORS_ORIGINS']);
    }

    /**
     * A list reads better in `config.php`; the environment is flat. Somebody
     * who writes the flat form anyway is not punished for it.
     */
    public function test_a_hand_written_string_of_origins_is_taken_as_it_is(): void
    {
        $config = $this->config();
        $config['app']['cors_origins'] = 'https://panel.example.com';

        ConfigFile::applyToEnvironment($config, '/srv/clubbar-data');

        $this->assertSame('https://panel.example.com', $_ENV['CORS_ORIGINS']);
    }

    /**
     * Published as an empty string rather than left unset, unlike the timezone
     * below. `AppConfig` reads empty as "derive it from the app URL", and an
     * absent key would instead let a stray `CORS_ORIGINS` in the process
     * environment answer for an installation that never mentioned one.
     */
    public function test_an_installation_that_says_nothing_publishes_an_empty_value(): void
    {
        $_ENV['CORS_ORIGINS'] = 'https://left.over.example';

        ConfigFile::applyToEnvironment($this->config(), '/srv/clubbar-data');

        $this->assertSame('', $_ENV['CORS_ORIGINS']);
    }

    /**
     * Reverse-proxy addresses trusted to name the real client via
     * X-Forwarded-For (#886) — the same flattening `cors_origins` gets, for
     * the same reason: a package install's whole environment is this file.
     */
    public function test_the_trusted_proxies_list_reaches_the_environment(): void
    {
        $config = $this->config();
        $config['app']['trusted_proxies'] = ['10.0.0.1', '172.16.0.0/12'];

        ConfigFile::applyToEnvironment($config, '/srv/clubbar-data');

        $this->assertSame('10.0.0.1,172.16.0.0/12', $_ENV['TRUSTED_PROXIES']);
    }

    public function test_a_hand_written_string_of_trusted_proxies_is_taken_as_it_is(): void
    {
        $config = $this->config();
        $config['app']['trusted_proxies'] = '10.0.0.1';

        ConfigFile::applyToEnvironment($config, '/srv/clubbar-data');

        $this->assertSame('10.0.0.1', $_ENV['TRUSTED_PROXIES']);
    }

    /**
     * Published as an empty string rather than left unset, for the same
     * reason `cors_origins` is: a stray `TRUSTED_PROXIES` left in the process
     * environment must not answer for an installation that never configured
     * one.
     */
    public function test_an_installation_that_says_nothing_publishes_an_empty_trusted_proxies_value(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        ConfigFile::applyToEnvironment($this->config(), '/srv/clubbar-data');

        $this->assertSame('', $_ENV['TRUSTED_PROXIES']);
    }

    /**
     * The club's zone reaches the environment from `config.php`, which is the
     * only place a self-hosted installation can set it — it governs every
     * surface that states the club's books, not just the mails.
     */
    public function test_the_club_timezone_reaches_the_environment(): void
    {
        $config = $this->config();
        $config['app']['timezone'] = 'Europe/Vienna';

        ConfigFile::applyToEnvironment($config, '/srv/clubbar-data');

        $this->assertSame('Europe/Vienna', $_ENV['CLUB_TIMEZONE']);
    }

    /**
     * Absent, blank or whitespace publishes nothing rather than an empty
     * string: ClubTimeZone's own default is then what applies, and `''` would
     * read as a configured value on its way through `Env::get()`.
     */
    public function test_an_unset_or_blank_timezone_publishes_nothing(): void
    {
        unset($_ENV['CLUB_TIMEZONE']);
        ConfigFile::applyToEnvironment($this->config(), '/srv/clubbar-data');
        $this->assertArrayNotHasKey('CLUB_TIMEZONE', $_ENV);

        $config = $this->config();
        $config['app']['timezone'] = '   ';
        ConfigFile::applyToEnvironment($config, '/srv/clubbar-data');
        $this->assertArrayNotHasKey('CLUB_TIMEZONE', $_ENV);
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

    /**
     * The recipient keys are a *list* in `config.php` and the environment is
     * flat, so they travel as one newline-separated string and are parsed back
     * by `BackupKeyring`. The separator can appear in neither half — a label is
     * `[A-Za-z0-9_-]` and a key is hex.
     */
    public function test_the_backup_recipient_list_survives_the_flattening(): void
    {
        ConfigFile::applyToEnvironment(
            $this->config() + ['backup' => ['recipient_public_keys' => ['admin:aa', 'vorstand:bb']]],
            '/srv/clubbar-data'
        );

        $this->assertSame("admin:aa\nvorstand:bb", $_ENV['BACKUP_RECIPIENT_PUBLIC_KEYS']);
    }

    /**
     * A hand-edited `config.php` that wrote one key as a bare string rather
     * than a one-element list must still boot. It is the likeliest way to get
     * this wrong, and the cost of being strict here is a fatal in a scheduled
     * job nobody is watching — `BackupKeyring` reports a malformed entry as a
     * finding instead, which is where a human can see it.
     */
    public function test_a_single_recipient_written_as_a_bare_string_still_boots(): void
    {
        ConfigFile::applyToEnvironment(
            $this->config() + ['backup' => ['recipient_public_keys' => 'admin:aa']],
            '/srv/clubbar-data'
        );

        $this->assertSame('admin:aa', $_ENV['BACKUP_RECIPIENT_PUBLIC_KEYS']);
    }

    /**
     * An installation that has never configured backups still boots, and the
     * absence reads as "no recipient" rather than as a confidently wrong value.
     * With no key nothing is written at all — never a plaintext archive, and
     * (ADR-0049 decision 2) nothing is attempted either: the absence of a key
     * *is* backups being off.
     */
    public function test_an_installation_without_a_backup_section_publishes_empty_values(): void
    {
        ConfigFile::applyToEnvironment($this->config(), '/srv/clubbar-data');

        $this->assertSame('', $_ENV['BACKUP_RECIPIENT_PUBLIC_KEYS']);
        $this->assertSame('', $_ENV['BACKUP_DSN']);
        $this->assertSame('', $_ENV['BACKUP_HEARTBEAT_URL']);
    }

    /**
     * Retention is compiled in and these override it, so an installation that
     * says nothing must publish nothing — an empty string, which `AppConfig`
     * reads as "use the default" rather than as zero. A `0` here would be a
     * retention of zero days: tonight's archive deleted at the end of tonight's
     * run.
     */
    public function test_absent_retention_overrides_publish_nothing_rather_than_zero(): void
    {
        ConfigFile::applyToEnvironment($this->config(), '/srv/clubbar-data');

        $this->assertSame('', $_ENV['BACKUP_LOCAL_RETENTION_DAYS']);
        $this->assertSame('', $_ENV['BACKUP_LOCAL_MAX_BYTES']);
        $this->assertSame('', $_ENV['BACKUP_REMOTE_RETENTION_DAYS']);
    }

    public function test_a_club_that_wants_a_different_window_can_say_so(): void
    {
        ConfigFile::applyToEnvironment(
            $this->config() + ['backup' => ['local_retention_days' => 14]],
            '/srv/clubbar-data'
        );

        $this->assertSame('14', $_ENV['BACKUP_LOCAL_RETENTION_DAYS']);
    }

    /**
     * Every setting the backend reads is either reachable from `config.php` or
     * listed below with a reason.
     *
     * This is the test #875 did not have. `CORS_ORIGINS` was read by
     * `ServiceFactory` with a `*` default and published by nothing, so a
     * package install — whose entire environment is this mapping — could not
     * set it, and answered `Access-Control-Allow-Origin: *` forever. Nothing
     * about that was visible in either file: the reader looked complete, the
     * mapping looked complete, and only the pair of them was wrong.
     *
     * The check runs against the source rather than against a list somebody
     * has to remember to extend, so the next unreachable setting fails the
     * unit suite on the commit that introduces it.
     */
    public function test_every_setting_the_backend_reads_is_reachable_from_config_php(): void
    {
        // Set by the deployment, never by config.php — the file cannot
        // configure the thing that decides whether it is read at all, and the
        // rest are development switches that must stay impossible to turn on
        // from a club's own configuration.
        $unreachableOnPurpose = [
            // Development and CI only, and stated as such in .env.example: all
            // three remove a control that is the last thing standing between a
            // guessed or captured TOTP code and an admin session or a
            // sensitive action.
            'DISABLE_LOGIN_RATE_LIMITING',
            'DISABLE_TERMINAL_RATE_LIMITING',
            'DISABLE_TOTP_REPLAY_PROTECTION',
            // Guards the *development* installer (backend/public/install.php).
            // The package has its own, and ADR-0031 blocks that route in
            // .htaccess rather than gating it on a key in the file it writes.
            'INSTALL_KEY',
        ];

        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Shared/Config/ConfigFile.php');
        preg_match_all("/'([A-Z][A-Z0-9_]+)'/", $source, $matches);
        $published = array_unique($matches[1]);

        $unreachable = [];
        foreach ($this->settingsTheBackendReads() as $key => $files) {
            if (in_array($key, $published, true) || in_array($key, $unreachableOnPurpose, true)) {
                continue;
            }

            $unreachable[] = $key . ' (read by ' . implode(', ', $files) . ')';
        }

        $this->assertSame(
            [],
            $unreachable,
            "These settings are read by the backend but published by nothing, so a package install cannot set them. "
            . "Map them in ConfigFile::applyToEnvironment() and document the key in config.sample.php — or, if the "
            . "setting must not be configurable by a club, add it to \$unreachableOnPurpose above with the reason."
        );
    }

    /**
     * @return array<string, list<string>> setting => the files reading it
     */
    private function settingsTheBackendReads(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/Env::get\(\s*'([A-Z][A-Z0-9_]+)'/", $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $key) {
                $found[$key][$file->getBasename()] = true;
            }
        }

        $reads = [];
        foreach ($found as $key => $byFile) {
            $reads[$key] = array_keys($byFile);
        }
        ksort($reads);

        return $reads;
    }
}
