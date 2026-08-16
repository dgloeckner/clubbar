<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Config\AppConfig;
use App\Shared\Middleware\ErrorHandler;

/**
 * The PHP runtime settings this deployment depends on, applied from code.
 *
 * Club Bar is unpacked into the document root of a mass-hosting account, where
 * there is no `php.ini` we may edit and no guarantee that a `.user.ini` we ship
 * is read at all. ADR-0031 decision 1 answers that with a test: *if the config
 * file we ship were deleted on the host, would the deployment still be
 * hardened?* Everything below is a `PHP_INI_ALL` directive, which means the
 * application can set it on any host — so the answer is yes, and this class is
 * where that promise lives.
 *
 * It runs from `bootstrap.php` before anything else, because the window it
 * closes opens immediately: the `new PDO(...)` a few lines later is *outside*
 * Slim's error handler, so on a host that defaults `display_errors=On` a
 * database outage prints the connection arguments to the browser.
 *
 * What is deliberately not here: `expose_php`, `disable_functions` and
 * `allow_url_fopen` are `PHP_INI_SYSTEM`, unreachable from code *and* from any
 * file we ship on shared hosting. `upload_max_filesize` and `post_max_size` are
 * `PHP_INI_PERDIR` — they belong to the `.user.ini` of #249, which is advisory
 * by definition.
 */
final class RuntimeHardening
{
    /**
     * Error types after which nothing else runs, so the shutdown function is
     * the last chance to answer the request at all.
     */
    private const FATAL_ERROR_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * Mirrors the Content-Security-Policy line in `package/.htaccess` — the
     * two are kept in sync by hand; see that file's comment for what each
     * directive is for and why (#250, ADR-0031 decision 1).
     */
    private const CSP_HEADER = "Content-Security-Policy: default-src 'self'; script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        . "worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; "
        . "frame-ancestors 'none'";

    /** Mirrors the Strict-Transport-Security line in `package/.htaccess` and `backend/public/.htaccess`. */
    private const HSTS_HEADER = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';

    /**
     * Apply every directive, and report what the host would not allow.
     *
     * Returns human-readable warnings rather than throwing or logging: this
     * runs before the Logger exists (constructing one touches the filesystem,
     * which is itself something a fatal can come out of), so `bootstrap.php`
     * logs the result once it has one. An empty array means fully applied.
     *
     * `$registerFatalHandler` defaults to "yes, unless we are on the CLI",
     * where a shutdown function that prints JSON would land in the middle of a
     * test runner's output. Tests that assert the handler pass `true`.
     *
     * @return list<string>
     */
    public static function apply(AppConfig $config, ?bool $registerFatalHandler = null): array
    {
        self::applyErrorReporting($config->debug);
        $warnings = self::applySessionDirectives($config);
        self::removeVersionHeader();
        self::applySecurityHeaders();

        if ($registerFatalHandler ?? (PHP_SAPI !== 'cli')) {
            self::registerFatalErrorResponder($config->debug);
        }

        return $warnings;
    }

    /**
     * Errors go to the log, never to the browser — unless this install asked
     * for the opposite with APP_DEBUG.
     *
     * `zend.exception_ignore_args` is the reason this is not just
     * `display_errors`: with args retained, the trace of a failed
     * `new PDO($dsn, $user, $pass)` carries the database password, and that
     * trace reaches the log even when nothing displays it.
     */
    private static function applyErrorReporting(bool $debug): void
    {
        ini_set('display_errors', $debug ? '1' : '0');
        // Separate directive, and the one that actually matters here: the
        // failures this guards against happen during startup.
        ini_set('display_startup_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('zend.exception_ignore_args', $debug ? '0' : '1');
    }

    /**
     * Session directives that no host may decide for us.
     *
     * `session.use_strict_mode` is required by ADR-0016 and is off in PHP's
     * compiled default: without it PHP adopts and initialises whatever session
     * ID a client sends, which is the half of session fixation that
     * `session_regenerate_id()` (ADR-0025) cannot cover — the attacker plants
     * the ID before anyone logs in.
     *
     * `session.save_path` moves the session files off whatever shared directory
     * the host points at — on mass hosting that can be a path other tenants can
     * read, and a readable session file is an admin login.
     *
     * @return list<string>
     */
    private static function applySessionDirectives(AppConfig $config): array
    {
        // ini_set() on a session directive fails with a warning once a session
        // is active, and would silently not apply. In-process test cases can
        // leave one open; a web request cannot reach here with one.
        if (session_status() !== PHP_SESSION_NONE) {
            return ['Session directives not applied: a session was already active when the application booted.'];
        }

        ini_set('session.use_strict_mode', '1');
        // A session ID may only travel in the cookie. `use_trans_sid` would put
        // it in URLs, from where it leaks through Referer headers and logs.
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $config->sessionMaxAge);

        session_set_cookie_params([
            'lifetime' => $config->sessionMaxAge,
            'path'     => '/',
            'secure'   => $config->sessionCookieSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return self::applySessionSavePath($config->sessionSavePath);
    }

    /**
     * Point the session handler at an app-private directory.
     *
     * Applied only once the directory is proven writable. An unwritable
     * `save_path` is not a weaker deployment, it is an outage — no session can
     * be written, so nobody can log in — and the host default is the safer
     * failure. The gap is reported instead, which is what the self-check of
     * #247 surfaces.
     *
     * @return list<string>
     */
    private static function applySessionSavePath(string $path): array
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            return ["session.save_path left at the host default: {$path} could not be created."];
        }

        if (!is_writable($path)) {
            return ["session.save_path left at the host default: {$path} is not writable."];
        }

        ini_set('session.save_path', $path);

        // Taking over the save path means taking over its cleanup. Distributions
        // that sweep the shared session directory from cron ship
        // `session.gc_probability=0`, and inheriting that here would leave every
        // session file this app ever wrote lying in the package forever.
        if ((int) ini_get('session.gc_probability') < 1) {
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }

        return [];
    }

    /**
     * Stop announcing the PHP version.
     *
     * `expose_php` is `PHP_INI_SYSTEM`, so on mass hosting no file we ship can
     * switch it off; removing the header PHP already queued is the only lever
     * the application has. It says nothing an attacker cannot find out another
     * way — it just says it to everyone, unprompted, including scanners
     * matching a patch level against a CVE list.
     */
    private static function removeVersionHeader(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }

    /**
     * Content-Security-Policy for the admin SPA, and Strict-Transport-Security
     * for the connection carrying it — set from code, not only from
     * `.htaccess` (#250, #383; ADR-0031 decision 1, promoting these two rows
     * from L2 to L0).
     *
     * `.htaccess` is honoured at the host's discretion: a tariff or webserver
     * change can silently drop the whole `<IfModule mod_headers.c>` block,
     * which is exactly what the security self-check found on a live
     * installation (#383) — both headers simply absent, with no application
     * error to notice it by. Setting them from PHP means a host that stops
     * honouring `.htaccess` loses nothing here. `.htaccess` keeps shipping the
     * same two lines as a redundant second source: `Header set` overwrites
     * rather than duplicates a header PHP already sent, so there is no
     * double-header to clean up.
     *
     * Called both from {@see apply()} — every request through `bootstrap.php`,
     * including the `/api/health` probe the security self-check reads these
     * headers from — and directly by `package/index.php`'s SPA fallback,
     * which serves the admin panel's HTML without ever reaching
     * `bootstrap.php`.
     */
    public static function applySecurityHeaders(): void
    {
        if (!headers_sent()) {
            header(self::CSP_HEADER);
            header(self::HSTS_HEADER);
        }
    }

    /**
     * Answer a fatal that happened before — or instead of — Slim's error handler.
     *
     * `display_errors=0` keeps such a failure off the screen, which on its own
     * leaves the caller a blank 500 that is indistinguishable from a network
     * fault. This turns it into the same generic JSON body the middleware would
     * have produced, without the detail that made it fatal.
     *
     * In debug mode it stands aside: PHP's own output is the point there.
     */
    private static function registerFatalErrorResponder(bool $debug): void
    {
        register_shutdown_function(static function () use ($debug): void {
            self::respondToFatalError(error_get_last(), $debug);
        });
    }

    /**
     * The shutdown function's body.
     *
     * Public only so a test can reach it: called from the shutdown function it
     * is unreachable by anything that wants to still be running afterwards, and
     * the subprocess test that proves the real path cannot report coverage.
     *
     * @internal
     * @param array{type:int,message:string,file:string,line:int}|null $error
     */
    public static function respondToFatalError(?array $error, bool $debug): void
    {
        if ($error === null || !in_array($error['type'], self::FATAL_ERROR_TYPES, true)) {
            return;
        }

        // A normal response is already on the wire, or debug mode asked for
        // PHP's own output. Either way this has nothing to add.
        if ($debug || headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => 'internal_error',
            'message' => ErrorHandler::GENERIC_500_MESSAGE,
        ]);
    }
}
