<?php

declare(strict_types=1);

namespace App\Shared\Config;

/**
 * Turns the array in `config.php` into the environment {@see Env} reads.
 *
 * The mapping used to live inside the package front controller, which was fine
 * while the front controller was the only thing that booted the application.
 * It stopped being fine with the cron entrypoint (ADR-0038 rule 3, #403): a CLI
 * run has no `$_SERVER`, no front controller and no `.env`, and it has to read
 * exactly the same file the web does. Two copies of this mapping would drift on
 * the first key somebody adds, and the failure would be a cron that silently
 * sends with the wrong sender — or does not send at all.
 *
 * Deliberately dependency-free and free of `use` statements beyond this
 * namespace, for the same reason {@see DataDirectory} is: `index.php` requires
 * both by path, before Composer's autoloader exists.
 */
final class ConfigFile
{
    /**
     * Publish a parsed `config.php` into `$_ENV`.
     *
     * Only keys the file actually carries are set, so an absent optional
     * section (`mail`, `cron`) leaves the variable unset rather than setting
     * it to an empty string — `AppConfig` treats both as "off", but the
     * distinction survives for anything that checks presence.
     *
     * @param array<string,mixed> $config  The array returned by `config.php`
     * @param string              $dataDir Where storage/, logs/ and sessions live
     */
    public static function applyToEnvironment(array $config, string $dataDir): void
    {
        // The backend resolves storage/, logs/ and the session directory from this.
        $_ENV['DATA_DIR'] = $dataDir;

        $_ENV['DB_HOST'] = $config['db']['host'] ?? '';
        $_ENV['DB_PORT'] = (string) ($config['db']['port'] ?? 3306);
        $_ENV['DB_NAME'] = $config['db']['name'] ?? '';
        $_ENV['DB_USER'] = $config['db']['user'] ?? '';
        $_ENV['DB_PASS'] = $config['db']['pass'] ?? '';

        $_ENV['APP_ENV'] = $config['app']['env'] ?? 'production';
        $_ENV['APP_DEBUG'] = ($config['app']['debug'] ?? false) ? 'true' : 'false';
        $_ENV['APP_URL'] = $config['app']['url'] ?? '';

        $_ENV['SESSION_MAX_AGE'] = (string) ($config['session']['max_age'] ?? 7200);
        $_ENV['SESSION_REGEN_INTERVAL'] = (string) ($config['session']['regeneration_interval'] ?? 900);

        // Optional override. Left unset, AppConfig derives the cookie's Secure
        // flag from the app URL and the request scheme.
        if (isset($config['session']['cookie_secure'])) {
            $_ENV['SESSION_COOKIE_SECURE'] = $config['session']['cookie_secure'] ? 'true' : 'false';
        }

        // Optional override. Left unset, sessions are written to the data
        // directory's storage/sessions instead of the host's shared session
        // directory (#246).
        if (!empty($config['session']['save_path'])) {
            $_ENV['SESSION_SAVE_PATH'] = $config['session']['save_path'];
        }

        $_ENV['API_TOKEN_TTL_DAYS'] = (string) ($config['api_token']['ttl_days'] ?? 365);
        $_ENV['TOTP_ENCRYPTION_KEY'] = $config['security']['totp_encryption_key'] ?? '';
        $_ENV['IBAN_FINGERPRINT_KEY'] = $config['security']['iban_fingerprint_key'] ?? '';

        $_ENV['MAIL_DSN'] = $config['mail']['dsn'] ?? '';
        if (!empty($config['mail']['drain_batch_size'])) {
            $_ENV['MAIL_DRAIN_BATCH_SIZE'] = (string) $config['mail']['drain_batch_size'];
        }
        if (!empty($config['mail']['drain_budget_seconds'])) {
            $_ENV['MAIL_DRAIN_BUDGET_SECONDS'] = (string) $config['mail']['drain_budget_seconds'];
        }

        // Absent leaves the URL drain trigger unmounted — see CronController.
        $_ENV['CRON_SECRET'] = $config['cron']['secret'] ?? '';

        // Absent switches the external alarm off (#406). It is a capability
        // URL — the UUID in it *is* the credential — which is why it is a
        // config key and not an admin-panel field.
        $_ENV['CRON_HEARTBEAT_URL'] = $config['cron']['heartbeat_url'] ?? '';
    }
}
