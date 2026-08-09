<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Measure the hardening this deployment actually got (#247, ADR-0031 decision 3).
 *
 * On mass hosting no configuration layer is guaranteed. `.htaccess` may not be
 * honoured, `.user.ini` may not be read, and a host can change either under a
 * running installation without telling anyone. Without this class a deployment
 * where every one of those was dropped is indistinguishable from a correct one:
 * `/api/health` answers `{"status":"ok"}` either way, while the scanned SEPA
 * mandates sit under a guessable URL.
 *
 * So nothing here reports what the application intended. Every row comes from
 * asking the running system:
 *
 * | Question | How it is answered |
 * |---|---|
 * | Are PHP's error and session directives in force? | `ini_get()` in this process |
 * | What will the session cookie carry? | `session_get_cookie_params()` |
 * | Where does the data live, and who can read it? | `stat()` on the real paths |
 * | Will the webserver hand out a mandate or a log? | a canary file, fetched over HTTP |
 * | Is the connection protected? | the request's own scheme, and the probe's headers |
 *
 * One engine, three surfaces (the ADR's table): the installer's prerequisite
 * step, the authenticated admin endpoint, and the package smoke test in CI.
 * What differs between them is a {@see SecurityCheckContext}, not the checks.
 *
 * Dependency-free on purpose: `install.php` runs before Composer's autoloader
 * exists and loads this file, {@see SecurityFinding}, {@see HttpProbe} and
 * {@see SecurityCheckContext} by path.
 */
final class SecuritySelfCheck
{
    public const CATEGORY_RUNTIME   = 'runtime';
    public const CATEGORY_SESSION   = 'session';
    public const CATEGORY_DATA      = 'data';
    public const CATEGORY_EXPOSURE  = 'exposure';
    public const CATEGORY_TRANSPORT = 'transport';

    /**
     * The `PHP_INI_ALL` directives this deployment depends on, and the state
     * they must be observed in.
     *
     * This table is the contract between the two halves of ADR-0031 decision 1:
     * {@see RuntimeHardening} sets these from code so no host can drop them,
     * and this class reads them back. A unit test asserts the two agree, so a
     * directive can never be hardened without being measured — or measured
     * without being hardened.
     *
     * @var array<string,bool> directive => expected on/off
     */
    public const EXPECTED_DIRECTIVES = [
        'display_errors'             => false,
        'display_startup_errors'     => false,
        'log_errors'                 => true,
        'zend.exception_ignore_args' => true,
        'session.use_strict_mode'    => true,
        'session.use_only_cookies'   => true,
        'session.use_trans_sid'      => false,
    ];

    /** Owner-only: no group or world bits at all (ADR-0031 decision 4). */
    private const OTHER_THAN_OWNER = 0077;

    /**
     * Every row, in reading order.
     *
     * @return list<SecurityFinding>
     */
    public static function run(SecurityCheckContext $context): array
    {
        // Nothing is fetched unless a row depends on it: the exposure rows are
        // answered from the filesystem when the data is not under the document
        // root, and HSTS is not measurable over plain HTTP. A self-request is
        // cheap but not free — this endpoint is reachable by every admin.
        $needsProbe = $context->https || !$context->dataIsOutsideDocumentRoot();
        $probe = $needsProbe ? self::resolveBaseUrl($context) : ['baseUrl' => null, 'headers' => []];

        return [
            ...self::runtimeFindings($context),
            ...self::sessionFindings($context),
            ...self::dataFindings($context),
            ...self::exposureFindings($context, $probe['baseUrl']),
            ...self::transportFindings($context, $probe),
        ];
    }

    /**
     * Counts by status, for a caller that needs a verdict rather than a table.
     *
     * @param list<SecurityFinding> $findings
     * @return array{pass:int,warn:int,fail:int,unknown:int}
     */
    public static function summarize(array $findings): array
    {
        $summary = [
            SecurityFinding::PASS    => 0,
            SecurityFinding::WARN    => 0,
            SecurityFinding::FAIL    => 0,
            SecurityFinding::UNKNOWN => 0,
        ];

        foreach ($findings as $finding) {
            $summary[$finding->status]++;
        }

        return $summary;
    }

    // ------------------------------------------------------------------
    // Runtime directives — ini_get() in this process
    // ------------------------------------------------------------------

    /**
     * @return list<SecurityFinding>
     */
    private static function runtimeFindings(SecurityCheckContext $context): array
    {
        $displayErrors = self::flagIsOn('display_errors') || self::flagIsOn('display_startup_errors');
        $findings = [];

        if (!$displayErrors) {
            $findings[] = SecurityFinding::pass(
                'display_errors',
                self::CATEGORY_RUNTIME,
                'PHP errors are not printed to the browser',
                'display_errors=Off, display_startup_errors=Off'
            );
        } elseif ($context->debug) {
            $findings[] = SecurityFinding::warn(
                'display_errors',
                self::CATEGORY_RUNTIME,
                'PHP errors are not printed to the browser',
                'display_errors=On (APP_DEBUG is enabled)',
                'This installation runs in debug mode, so PHP prints its errors — including the arguments of a '
                . 'failed database connection — to whoever made the request. Set debug to false in config.php.'
            );
        } else {
            $findings[] = SecurityFinding::fail(
                'display_errors',
                self::CATEGORY_RUNTIME,
                'PHP errors are not printed to the browser',
                'display_errors=On',
                'A fatal raised before the error handler is installed will print its stack trace to the browser. '
                . 'The application switches this off at startup, so something re-enabled it after boot — look for '
                . 'an ini_set() in a customised front controller.'
            );
        }

        $findings[] = self::flagIsOn('log_errors')
            ? SecurityFinding::pass(
                'log_errors',
                self::CATEGORY_RUNTIME,
                'PHP errors are written to the log',
                'log_errors=On'
            )
            : SecurityFinding::warn(
                'log_errors',
                self::CATEGORY_RUNTIME,
                'PHP errors are written to the log',
                'log_errors=Off',
                'Errors that are neither displayed nor logged leave nothing behind to diagnose. The application '
                . 'switches this on at startup; a value of Off means something switched it back.'
            );

        $findings[] = self::flagIsOn('zend.exception_ignore_args')
            ? SecurityFinding::pass(
                'exception_args',
                self::CATEGORY_RUNTIME,
                'Stack traces omit their arguments',
                'zend.exception_ignore_args=On'
            )
            : SecurityFinding::warn(
                'exception_args',
                self::CATEGORY_RUNTIME,
                'Stack traces omit their arguments',
                'zend.exception_ignore_args=Off',
                'With arguments retained, the trace of a failed database connection carries the database password '
                . 'into the log file. Switch debug off, or set zend.exception_ignore_args=1.'
            );

        return $findings;
    }

    // ------------------------------------------------------------------
    // Session — directives and the cookie as it will be emitted
    // ------------------------------------------------------------------

    /**
     * @return list<SecurityFinding>
     */
    private static function sessionFindings(SecurityCheckContext $context): array
    {
        $findings = [];

        $findings[] = self::flagIsOn('session.use_strict_mode')
            ? SecurityFinding::pass(
                'session_strict_mode',
                self::CATEGORY_SESSION,
                'PHP refuses session IDs it did not issue',
                'session.use_strict_mode=On'
            )
            : SecurityFinding::fail(
                'session_strict_mode',
                self::CATEGORY_SESSION,
                'PHP refuses session IDs it did not issue',
                'session.use_strict_mode=Off',
                'Without strict mode PHP adopts whatever session ID a client sends, which lets an attacker plant an '
                . 'ID before anyone logs in and then use the session that ID becomes (ADR-0016). The application '
                . 'sets this at startup; Off means the process reached this point with a session already open.'
            );

        $cookieOnly = self::flagIsOn('session.use_only_cookies') && !self::flagIsOn('session.use_trans_sid');
        $findings[] = $cookieOnly
            ? SecurityFinding::pass(
                'session_cookie_only',
                self::CATEGORY_SESSION,
                'The session ID travels only in the cookie',
                'session.use_only_cookies=On, session.use_trans_sid=Off'
            )
            : SecurityFinding::fail(
                'session_cookie_only',
                self::CATEGORY_SESSION,
                'The session ID travels only in the cookie',
                sprintf(
                    'session.use_only_cookies=%s, session.use_trans_sid=%s',
                    self::flagIsOn('session.use_only_cookies') ? 'On' : 'Off',
                    self::flagIsOn('session.use_trans_sid') ? 'On' : 'Off'
                ),
                'A session ID that travels in URLs leaks through Referer headers, browser history and the access '
                . 'log of every server it is linked from.'
            );

        if ($context->expectedSessionSavePath !== null) {
            $findings[] = self::sessionSavePathFinding($context->expectedSessionSavePath);
        }

        // What PHP will actually put on the wire. `session_get_cookie_params()`
        // reports the effective values, whether they came from the ini file,
        // from the host, or from the `session_set_cookie_params()` call the
        // application makes at startup.
        $params = session_get_cookie_params();

        $findings[] = !empty($params['httponly'])
            ? SecurityFinding::pass(
                'session_cookie_httponly',
                self::CATEGORY_SESSION,
                'The session cookie is HttpOnly',
                'HttpOnly'
            )
            : SecurityFinding::fail(
                'session_cookie_httponly',
                self::CATEGORY_SESSION,
                'The session cookie is HttpOnly',
                'not HttpOnly',
                'Without HttpOnly any script running in the admin panel can read the session cookie and copy an '
                . 'authenticated session out of the browser.'
            );

        $sameSite = (string) ($params['samesite'] ?? '');
        $findings[] = in_array(strtolower($sameSite), ['lax', 'strict'], true)
            ? SecurityFinding::pass(
                'session_cookie_samesite',
                self::CATEGORY_SESSION,
                'The session cookie is SameSite',
                "SameSite={$sameSite}"
            )
            : SecurityFinding::warn(
                'session_cookie_samesite',
                self::CATEGORY_SESSION,
                'The session cookie is SameSite',
                $sameSite === '' ? 'SameSite is not set' : "SameSite={$sameSite}",
                'SameSite=Lax stops another site from making the browser send this cookie along with a '
                . 'cross-site request. It is the second layer behind the CSRF token, not a replacement for it.'
            );

        $findings[] = self::sessionCookieSecureFinding($context, !empty($params['secure']));

        return $findings;
    }

    private static function sessionSavePathFinding(string $expected): SecurityFinding
    {
        $actual = (string) ini_get('session.save_path');
        // PHP allows "N;/path" and "N;mode;/path" — the path is the last field.
        $path = $actual === '' ? '' : (string) array_slice(explode(';', $actual), -1)[0];

        if ($path === rtrim($expected, '/')) {
            return SecurityFinding::pass(
                'session_save_path',
                self::CATEGORY_SESSION,
                'Session files are kept in this installation\'s own directory',
                $path
            );
        }

        return SecurityFinding::warn(
            'session_save_path',
            self::CATEGORY_SESSION,
            'Session files are kept in this installation\'s own directory',
            $path === '' ? 'the host default' : $path,
            "Sessions are being written outside {$expected}. On shared hosting the host's default session "
            . 'directory can be shared with other accounts on the machine, where a readable session file is an '
            . 'admin login. The application points PHP at its own directory unless that directory cannot be '
            . 'created or written — check the permissions of the data directory.'
        );
    }

    private static function sessionCookieSecureFinding(SecurityCheckContext $context, bool $secure): SecurityFinding
    {
        if ($secure) {
            return SecurityFinding::pass(
                'session_cookie_secure',
                self::CATEGORY_SESSION,
                'The session cookie is Secure',
                'Secure'
            );
        }

        // A Secure cookie is dropped by the browser over plain HTTP, so on an
        // installation that is not served over TLS this is a consequence of the
        // transport, reported there — not a second red row with no remedy.
        if (!$context->https) {
            return SecurityFinding::warn(
                'session_cookie_secure',
                self::CATEGORY_SESSION,
                'The session cookie is Secure',
                'not Secure (this request did not arrive over HTTPS)',
                'A browser discards a Secure cookie sent over plain HTTP, so the flag can only be set once this '
                . 'installation is reached over HTTPS. Until then the session cookie travels in clear text.'
            );
        }

        return SecurityFinding::fail(
            'session_cookie_secure',
            self::CATEGORY_SESSION,
            'The session cookie is Secure',
            'not Secure',
            'This request arrived over HTTPS but the session cookie is not marked Secure, so a single plain-HTTP '
            . 'request — a typed URL, a stale bookmark — sends an authenticated admin session in clear text. Set '
            . 'the app URL in config.php to its https:// address.'
        );
    }

    // ------------------------------------------------------------------
    // Data — where it is, and who may read it
    // ------------------------------------------------------------------

    /**
     * @return list<SecurityFinding>
     */
    private static function dataFindings(SecurityCheckContext $context): array
    {
        $findings = [];

        $findings[] = $context->dataIsOutsideDocumentRoot()
            ? SecurityFinding::pass(
                'data_directory',
                self::CATEGORY_DATA,
                'Secrets and member documents live outside the document root',
                $context->dataDirectory
            )
            : SecurityFinding::warn(
                'data_directory',
                self::CATEGORY_DATA,
                'Secrets and member documents live outside the document root',
                $context->dataDirectory . ' (inside the document root)',
                ($context->dataDirectoryReason ?? 'This host had no writable directory above the document root '
                    . 'when Club Bar was installed.')
                . ' The database password, the scanned mandates and the logs are kept off the web by .htaccess '
                . 'alone, which a host may stop honouring after a tariff or server change. If you can create a '
                . 'writable directory next to your document root, re-run the installer to move the data there.'
            );

        if ($context->configFile !== null) {
            $findings[] = self::modeFinding(
                'config_file_mode',
                'The configuration file is readable only by its owner',
                $context->configFile,
                'It holds the database password and the key that encrypts every admin\'s second factor.'
            );
        }

        $findings[] = self::modeFinding(
            'storage_directory_mode',
            'The document store is readable only by its owner',
            $context->dataDirectory . '/storage',
            'It holds the scanned SEPA mandates: per member a name, an IBAN and a signature.'
        );

        $findings[] = self::modeFinding(
            'log_directory_mode',
            'The log directory is readable only by its owner',
            $context->dataDirectory . '/logs',
            'Application logs carry request context, including identifiers of the members involved.'
        );

        return $findings;
    }

    /**
     * Report a path's permission bits as `stat()` sees them.
     *
     * `0777` on the writable directories is a build-time convenience that
     * survives into production (ADR-0031 decision 4), and a `chmod` the host
     * refused leaves no trace anywhere else — which is why this measures rather
     * than trusting the installer's return value.
     */
    private static function modeFinding(string $id, string $label, string $path, string $why): SecurityFinding
    {
        if (!file_exists($path)) {
            return SecurityFinding::unknown(
                $id,
                self::CATEGORY_DATA,
                $label,
                'does not exist',
                "{$path} was not found, so its permissions could not be read."
            );
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);
        if ($perms === false) {
            return SecurityFinding::unknown(
                $id,
                self::CATEGORY_DATA,
                $label,
                'could not be read',
                "This host would not report the permissions of {$path}."
            );
        }

        $mode = $perms & 0777;
        $observed = sprintf('%04o', $mode);

        if (($mode & self::OTHER_THAN_OWNER) === 0) {
            return SecurityFinding::pass($id, self::CATEGORY_DATA, $label, $observed);
        }

        return SecurityFinding::warn(
            $id,
            self::CATEGORY_DATA,
            $label,
            $observed,
            "{$path} can be read by other users on this machine, and on shared hosting those users are other "
            . "customers. {$why} A tighter mode is not always possible — some hosts run PHP as a different user "
            . 'than the one that owns the files, and narrowing the mode there breaks the installation.'
        );
    }

    // ------------------------------------------------------------------
    // Exposure — what the webserver hands out
    // ------------------------------------------------------------------

    /**
     * @return list<SecurityFinding>
     */
    private static function exposureFindings(SecurityCheckContext $context, ?string $baseUrl): array
    {
        // The stronger claim, and the one worth reporting when it holds: a file
        // that is not under the document root cannot be served by any rule,
        // honoured or ignored. Verified against the filesystem, so it does not
        // depend on this host letting us fetch our own URLs.
        if ($context->dataIsOutsideDocumentRoot()) {
            return [
                SecurityFinding::pass(
                    'mandate_not_served',
                    self::CATEGORY_EXPOSURE,
                    'A scanned mandate cannot be fetched over the web',
                    'the document store is not under the document root'
                ),
                SecurityFinding::pass(
                    'logs_not_served',
                    self::CATEGORY_EXPOSURE,
                    'The application log cannot be fetched over the web',
                    'the log directory is not under the document root'
                ),
            ];
        }

        return [
            self::canaryFinding(
                $context,
                $baseUrl,
                'mandate_not_served',
                'A scanned mandate cannot be fetched over the web',
                $context->dataDirectory . '/storage/mandates',
                // Shaped like the real thing: a mandate is named after the
                // member UUID the admin API already hands to the browser, which
                // is exactly why guessing the filename is not a defence.
                self::canaryName('.pdf'),
                'Every scanned SEPA mandate on this installation — a name, an IBAN and a handwritten signature — '
                . 'can be downloaded by anyone who can guess a member UUID.'
            ),
            self::canaryFinding(
                $context,
                $baseUrl,
                'logs_not_served',
                'The application log cannot be fetched over the web',
                $context->dataDirectory . '/logs',
                self::canaryName('.log'),
                'The application log can be downloaded by anyone.'
            ),
        ];
    }

    /**
     * Write a file where the real thing lives, ask the webserver for it, and
     * report what came back.
     *
     * A pass requires a refusal that was *observed*: 403 or 404 from a base URL
     * that was proven to serve this installation. Anything else — no usable
     * base URL, an unwritable directory — is `UNKNOWN`, because "we could not
     * ask" and "the answer was no" are different answers and only one of them
     * is safe to draw a green row from.
     */
    private static function canaryFinding(
        SecurityCheckContext $context,
        ?string $baseUrl,
        string $id,
        string $label,
        string $directory,
        string $filename,
        string $consequence
    ): SecurityFinding {
        $remedyForUnverified = 'Request ' . self::urlPath($context, $directory) . '/ in a browser yourself and '
            . 'make sure it is refused.';

        if ($baseUrl === null) {
            return SecurityFinding::unknown($id, self::CATEGORY_EXPOSURE, $label, 'could not be measured',
                'This host would not let Club Bar fetch its own URLs. ' . $remedyForUnverified);
        }

        $createdDirectory = !is_dir($directory);
        if ($createdDirectory && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return SecurityFinding::unknown($id, self::CATEGORY_EXPOSURE, $label, 'could not be measured',
                "{$directory} could not be created, so nothing could be placed there to ask for. "
                . $remedyForUnverified);
        }

        $canary = rtrim($directory, '/') . '/' . $filename;
        if (@file_put_contents($canary, "clubbar security self-check\n") === false) {
            if ($createdDirectory) {
                @rmdir($directory);
            }

            return SecurityFinding::unknown($id, self::CATEGORY_EXPOSURE, $label, 'could not be measured',
                "A temporary file could not be written to {$directory}. " . $remedyForUnverified);
        }

        $status = HttpProbe::fetch($baseUrl . self::urlPath($context, $canary))['status'];

        @unlink($canary);
        if ($createdDirectory) {
            @rmdir($directory);
        }

        if ($status === 403 || $status === 404) {
            return SecurityFinding::pass($id, self::CATEGORY_EXPOSURE, $label, "refused (HTTP {$status})");
        }

        if ($status === null) {
            return SecurityFinding::unknown($id, self::CATEGORY_EXPOSURE, $label, 'could not be measured',
                'The webserver did not answer the check. ' . $remedyForUnverified);
        }

        return SecurityFinding::fail($id, self::CATEGORY_EXPOSURE, $label, "SERVED (HTTP {$status})",
            "This host does not honour the .htaccess rules Club Bar ships, so {$consequence} Move the data "
            . 'directory above the document root — re-run the installer, which offers this when the host allows '
            . 'it — or ask your hosting provider to enable .htaccess overrides.');
    }

    /** The path a file under the document root is reachable at. */
    private static function urlPath(SecurityCheckContext $context, string $path): string
    {
        return substr($path, strlen(rtrim($context->documentRoot, '/')));
    }

    private static function canaryName(string $extension): string
    {
        return 'security-self-check-' . bin2hex(random_bytes(8)) . $extension;
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * @param array{baseUrl:string|null,headers:array<string,string>} $probe
     * @return list<SecurityFinding>
     */
    private static function transportFindings(SecurityCheckContext $context, array $probe): array
    {
        $findings = [];

        $findings[] = $context->https
            ? SecurityFinding::pass(
                'https',
                self::CATEGORY_TRANSPORT,
                'This installation is reached over HTTPS',
                'the request arrived over TLS'
            )
            : SecurityFinding::warn(
                'https',
                self::CATEGORY_TRANSPORT,
                'This installation is reached over HTTPS',
                'the request arrived over plain HTTP',
                'Admin passwords, the session cookie and every member record travel in clear text. Every hosting '
                . 'account this project targets includes a free certificate — enable it in the control panel and '
                . 'redirect HTTP to HTTPS (ADR-0016).'
            );

        $findings[] = self::hstsFinding($context, $probe);

        return $findings;
    }

    /**
     * @param array{baseUrl:string|null,headers:array<string,string>} $probe
     */
    private static function hstsFinding(SecurityCheckContext $context, array $probe): SecurityFinding
    {
        $label = 'Browsers are told to stay on HTTPS (HSTS)';

        if (!$context->https) {
            return SecurityFinding::unknown('hsts', self::CATEGORY_TRANSPORT, $label, 'not measurable over HTTP',
                'A browser ignores Strict-Transport-Security on a plain-HTTP response, so this cannot be '
                . 'measured — or be worth setting — until the row above is green.');
        }

        if ($probe['baseUrl'] === null) {
            return SecurityFinding::unknown('hsts', self::CATEGORY_TRANSPORT, $label, 'could not be measured',
                'This host would not let Club Bar fetch its own URLs, so the response headers could not be read.');
        }

        $header = $probe['headers']['strict-transport-security'] ?? '';
        if ($header !== '' && preg_match('/max-age\s*=\s*(\d+)/i', $header, $matches) && (int) $matches[1] > 0) {
            return SecurityFinding::pass('hsts', self::CATEGORY_TRANSPORT, $label, $header);
        }

        return SecurityFinding::warn('hsts', self::CATEGORY_TRANSPORT, $label,
            $header === '' ? 'the header is not sent' : "Strict-Transport-Security: {$header}",
            'Without it a browser that has only ever been told about this site over HTTPS will still follow a '
            . 'plain-HTTP link, and the first request of that visit can be intercepted. The .htaccess Club Bar '
            . 'ships sets the header; a host that ignores .htaccess drops it.');
    }

    // ------------------------------------------------------------------
    // Shared plumbing
    // ------------------------------------------------------------------

    /**
     * The first candidate base URL that provably serves *this* installation.
     *
     * Without the control fetch a base URL that is simply not reachable from
     * the server — or that belongs to somebody else's vhost — would answer 404
     * for the canary and be read as a refusal. The headers of that control
     * response are kept: they are the only place the deployment's own security
     * headers can be observed.
     *
     * @return array{baseUrl:string|null,headers:array<string,string>}
     */
    private static function resolveBaseUrl(SecurityCheckContext $context): array
    {
        foreach ($context->baseUrlCandidates as $base) {
            foreach ($context->controlPaths as $path) {
                $response = HttpProbe::fetch($base . $path);
                if ($response['status'] === 200) {
                    return ['baseUrl' => $base, 'headers' => $response['headers']];
                }

                // No status at all means nothing answered at this address, so
                // the remaining paths would time out against it too. This is
                // the difference between a bounded check and one that can hold
                // an admin's request open for every candidate in turn.
                if ($response['status'] === null) {
                    break;
                }
            }
        }

        return ['baseUrl' => null, 'headers' => []];
    }

    /**
     * Read a boolean ini directive the way PHP does: `''`, `'0'`, `'off'` and
     * `'false'` are off, everything else is on.
     */
    private static function flagIsOn(string $directive): bool
    {
        $raw = ini_get($directive);
        if ($raw === false) {
            return false;
        }

        return !in_array(strtolower(trim($raw)), ['', '0', 'off', 'false', 'no'], true);
    }
}
