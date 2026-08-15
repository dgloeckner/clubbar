<?php

declare(strict_types=1);

namespace App\Shared\Config;

class AppConfig
{
    public readonly string $env;
    public readonly bool   $debug;
    public readonly int    $sessionMaxAge;
    public readonly int    $sessionRegenInterval;
    public readonly bool   $sessionCookieSecure;
    public readonly string $sessionCookieName;
    public readonly string $sessionSavePath;
    public readonly int    $tokenTtlDays;
    public readonly string $dataDir;
    public readonly string $storageDir;
    public readonly string $logDir;
    public readonly string $installKey;
    public readonly string $appUrl;
    /**
     * The one field that selects a mail transport (ADR-0038). It carries an
     * SMTP password, so it belongs with the DB password and the TOTP key in
     * config.php rather than in an admin-editable table. Absent disables mail
     * silently.
     */
    public readonly ?string $mailDsn;
    /**
     * Authorises the URL drain trigger (ADR-0038 rule 3, #403). Absent leaves
     * the route unmounted, which is the right default: a tariff with a CLI cron
     * has no reason to expose a second, unauthenticated way in.
     */
    public readonly ?string $cronSecret;
    /**
     * Where the drain reports that it ran, and how it went (#406, ADR-0038
     * rule 6).
     *
     * A push-monitor check URL — healthchecks.io is the reference, Uptime Kuma,
     * Cronitor and Better Stack take the same shape — deliberately named
     * provider-neutrally, because clubs self-host. The UUID in the URL *is* the
     * credential, which is why it belongs here rather than in an admin-editable
     * table.
     *
     * Absent switches the alarm off, and that is the right default: a fresh
     * install has no monitor account, and a missing URL must not make the drain
     * noisy or slow.
     *
     * The whole point of an external monitor is that the alarm channel does not
     * depend on what it monitors — a report of "SMTP is dead" delivered over
     * the dead SMTP would never arrive.
     */
    public readonly ?string $cronHeartbeatUrl;
    /**
     * The deployment's document root — the directory `backend/` sits in.
     *
     * Needed to *print* a path rather than to read one: the scheduler setup
     * instructions (#405) name `backend/bin/cron.php` by absolute path, and
     * that path is the single thing an admin has to paste into a hosting
     * panel's cron form. Deriving it here rather than asking the admin to
     * work it out is the difference between a copyable line and a support
     * question.
     */
    public readonly string $documentRoot;

    public function __construct()
    {
        $this->env                  = Env::get('APP_ENV', 'production');
        $this->debug                = filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
        $this->sessionMaxAge        = (int) Env::get('SESSION_MAX_AGE', '7200');
        $this->sessionRegenInterval = (int) Env::get('SESSION_REGEN_INTERVAL', '900');
        $this->tokenTtlDays         = self::readTokenTtlDays();
        // Resolved before the paths below — every one of them hangs off it.
        $this->dataDir              = self::resolveDataDir();
        $this->storageDir           = $this->dataDir . '/storage';
        $this->logDir               = $this->dataDir . '/logs';
        $this->sessionSavePath      = self::resolveSessionSavePath($this->storageDir);
        $this->installKey           = Env::get('INSTALL_KEY', '');
        $this->appUrl               = Env::get('APP_URL', 'http://localhost:8080');
        $this->mailDsn              = trim(Env::get('MAIL_DSN', '')) ?: null;
        $this->cronSecret           = trim(Env::get('CRON_SECRET', '')) ?: null;
        $this->cronHeartbeatUrl     = trim(Env::get('CRON_HEARTBEAT_URL', '')) ?: null;
        $this->documentRoot         = self::resolveDocumentRoot();

        // Resolved last — the default depends on $this->appUrl.
        $this->sessionCookieSecure  = self::resolveSessionCookieSecure($this->appUrl);
        $this->sessionCookieName    = self::resolveSessionCookieName($this->sessionCookieSecure);
    }

    public function isProduction(): bool
    {
        return $this->env === 'production';
    }

    /**
     * Decide whether the session cookie carries the `Secure` attribute.
     *
     * Without it the `_session` cookie travels in clear text the moment a
     * browser reaches the app over plain HTTP (a typed URL, a stale bookmark),
     * handing anyone on the same network a fully authenticated admin session.
     *
     * The flag cannot simply be hard-coded to true: a Secure cookie is dropped
     * by the browser over HTTP, which would break local development and the
     * E2E suite, both of which run on http://localhost. So it is derived:
     *
     * 1. `SESSION_COOKIE_SECURE` wins when set to a recognizable boolean — the
     *    escape hatch for setups the derivation gets wrong (and the way to
     *    force it on). An unset or unparseable value falls through.
     * 2. Otherwise true when `APP_URL` is an https:// URL, i.e. the deployment
     *    describes itself as HTTPS-facing.
     * 3. Otherwise true when *this* request arrived over TLS, so an install
     *    that never set APP_URL still gets Secure cookies for the sessions it
     *    establishes over HTTPS.
     * 4. Otherwise false — plain-HTTP development.
     */
    private static function resolveSessionCookieSecure(string $appUrl): bool
    {
        $raw = Env::get('SESSION_COOKIE_SECURE', '');
        if ($raw !== '') {
            // FILTER_NULL_ON_FAILURE, not the plain cast: a typo in a security
            // flag must fall through to the derivation below rather than
            // silently switch it off. (The empty string is filtered out above —
            // this filter would read it as an explicit false.)
            $explicit = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($explicit !== null) {
                return $explicit;
            }
        }

        if (str_starts_with(strtolower($appUrl), 'https://')) {
            return true;
        }

        return self::requestIsHttps();
    }

    /**
     * The session cookie's name (issue #251, ADR-0031 decision 1).
     *
     * `__Host-` is a browser-enforced prefix: it refuses any `Set-Cookie` for
     * the cookie that is not `Secure`, `Path=/` and free of a `Domain`
     * attribute — exactly the attributes `RuntimeHardening` already sets. That
     * turns a convention this app follows into one the browser enforces
     * against every sibling subdomain of a shared-hosting account, which is
     * the cookie-injection vector ADR-0025 names in its threat model.
     *
     * The prefix only works on a Secure cookie, so it follows the same flag:
     * plain-HTTP development and the E2E suite keep the current `_session`
     * name and keep working.
     */
    private static function resolveSessionCookieName(bool $secure): string
    {
        return $secure ? '__Host-session' : '_session';
    }

    private static function requestIsHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        // Behind a TLS-terminating reverse proxy the request itself is plain
        // HTTP; the proxy reports the browser-facing scheme in this header.
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwarded !== '') {
            $first = trim(explode(',', (string) $forwarded)[0]);
            return strtolower($first) === 'https';
        }

        return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
    }

    /**
     * The directory holding `config.php`, `storage/` and `logs/` (#245,
     * ADR-0031 decision 2).
     *
     * `DATA_DIR` is written by the package front controller, which resolves it
     * from the pointer the installer left — on a host with a writable parent
     * that is a directory *above* the document root, where no `.htaccess`
     * mistake can turn a scanned SEPA mandate into a URL.
     *
     * The default is the backend directory itself, which is both the
     * in-document-root fallback and exactly where `storage/` and `logs/` have
     * always been, so a development checkout and an installation that predates
     * the pointer resolve to the layout they already have.
     */
    private static function resolveDataDir(): string
    {
        $configured = trim(Env::get('DATA_DIR', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return dirname(__DIR__, 3);
    }

    /**
     * The directory `backend/` lives in.
     *
     * Derived from this file's own location rather than from an environment
     * variable, for the same reason `bin/cron.php` derives it as
     * `dirname(__DIR__, 2)`: it is a fact about where the code was unpacked,
     * and a configured copy of it could disagree with reality. `DOCUMENT_ROOT`
     * from the webserver is deliberately not consulted — on a CLI run there is
     * none, and on a host with an aliased root it names the URL space rather
     * than the deployment.
     *
     * `backend/src/Shared/Config` → `backend/src/Shared` → `backend/src` →
     * `backend` → the document root.
     */
    private static function resolveDocumentRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Where PHP writes session files (#246, ADR-0031 decision 1).
     *
     * Left to the host, sessions land in a directory shared with every other
     * account on the machine, where a readable session file is an admin login.
     * The default follows the data directory, so an installation whose data
     * lives above the document root keeps its sessions there too.
     *
     * `SESSION_SAVE_PATH` still overrides it, for a host that wants sessions
     * somewhere else entirely.
     */
    private static function resolveSessionSavePath(string $storageDir): string
    {
        $configured = trim(Env::get('SESSION_SAVE_PATH', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $storageDir . '/sessions';
    }

    /**
     * Lifetime of a terminal API token, in days (#106).
     *
     * The default is the shared credential cryptoperiod (ADR-0036): one year,
     * the same figure the IBAN encryption keys carry, warned about on the same
     * 90/30/7 tiers. It was 90 days, which is a sensible number for a secret a
     * human retypes and a punitive one for a device in a cupboard — four
     * unannounced outages a year, each ending with a bar that cannot sell. The
     * answer to a long-lived credential is a rotation an admin can prepare
     * ahead of the deadline (#395), not a short one nobody schedules.
     *
     * A zero or negative value is not honoured: it would either mint tokens
     * that are already expired or, read the other way, invite "0 = never" —
     * and "never" is the state this setting exists to end. The default stands
     * in instead.
     */
    private static function readTokenTtlDays(): int
    {
        $default = \App\Shared\Security\CredentialLifecycle::LIFETIME_DAYS;
        $days = (int) Env::get('API_TOKEN_TTL_DAYS', (string) $default);

        return $days > 0 ? $days : $default;
    }
}
