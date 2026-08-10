<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\SecurityCheckContext;
use App\Shared\Security\SecurityFinding;
use App\Shared\Security\SecuritySelfCheck;
use PHPUnit\Framework\TestCase;

/**
 * The self-check is only worth having if it *measures* (#247, ADR-0031
 * decision 3), so almost every test here does the same thing: change the
 * effective state, run the engine, and assert the row changed with it. A check
 * that returned a constant would pass a test that only asserted "the row is
 * green on a correct install" — and would go on being green on the day the host
 * stopped honouring anything.
 *
 * The other half is the rule that makes the report trustworthy: a row is green
 * only when the state was observed. Anything unmeasurable — a webserver that
 * will not answer, a file that is not there — has to come back UNKNOWN, never
 * PASS.
 */
class SecuritySelfCheckTest extends TestCase
{
    /** Captured so one test's flipped directive cannot leak into the next. */
    private const MANAGED_DIRECTIVES = [
        'display_errors',
        'display_startup_errors',
        'log_errors',
        'zend.exception_ignore_args',
        'session.use_strict_mode',
        'session.use_only_cookies',
        'session.use_trans_sid',
        'session.save_path',
        'session.cookie_httponly',
        'session.cookie_secure',
        'session.cookie_samesite',
    ];

    /** @var array<string,string|false> */
    private array $iniBackup = [];

    private string $documentRoot;
    private string $dataDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // PHP refuses every session directive while a session is open, and an
        // earlier test in this process may have left one behind.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }

        foreach (self::MANAGED_DIRECTIVES as $directive) {
            $this->iniBackup[$directive] = ini_get($directive);
        }

        // A throwaway document root shaped like the real thing: the shared
        // hosting package puts index.php, backend/ and the data directory in
        // one tree, and the placement rows are about exactly that geometry.
        $this->documentRoot = sys_get_temp_dir() . '/clubbar-selfcheck-' . bin2hex(random_bytes(6));
        $this->dataDirectory = $this->documentRoot . '/backend';
        mkdir($this->dataDirectory . '/storage', 0700, true);
        mkdir($this->dataDirectory . '/logs', 0700, true);

        $this->applyHardenedDirectives();
    }

    protected function tearDown(): void
    {
        foreach ($this->iniBackup as $directive => $value) {
            if ($value !== false) {
                @ini_set($directive, $value);
            }
        }

        $this->removeTree($this->documentRoot);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Runtime directives
    // ------------------------------------------------------------------

    public function test_errors_printed_to_the_browser_are_reported_as_a_failure(): void
    {
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('display_errors'));

        ini_set('display_errors', '1');

        $this->assertSame(
            SecurityFinding::FAIL,
            $this->statusOf('display_errors'),
            'Flipping the directive must flip the row — otherwise the report is decoration'
        );
    }

    /**
     * An installation that asked for debug output is not broken, it is
     * configured that way. The row still cannot be green: the effective state
     * really is "errors reach the browser".
     */
    public function test_debug_mode_downgrades_the_display_errors_row_to_a_warning(): void
    {
        ini_set('display_errors', '1');

        $finding = $this->finding('display_errors', $this->context(debug: true));

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('APP_DEBUG', $finding->observed);
        $this->assertNotNull($finding->remedy);
    }

    public function test_startup_errors_alone_are_enough_to_fail_the_row(): void
    {
        // The window this closes is the `new PDO(...)` in bootstrap.php, which
        // fails before Slim's error handler exists — a startup error.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '1');

        $this->assertSame(SecurityFinding::FAIL, $this->statusOf('display_errors'));
    }

    public function test_errors_that_are_not_logged_are_reported(): void
    {
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('log_errors'));

        ini_set('log_errors', '0');

        $this->assertSame(SecurityFinding::WARN, $this->statusOf('log_errors'));
    }

    public function test_traces_that_keep_their_arguments_are_reported(): void
    {
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('exception_args'));

        ini_set('zend.exception_ignore_args', '0');

        $finding = $this->finding('exception_args');
        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('password', (string) $finding->remedy);
    }

    // ------------------------------------------------------------------
    // Session
    // ------------------------------------------------------------------

    public function test_a_host_without_strict_session_mode_is_reported_as_a_failure(): void
    {
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('session_strict_mode'));

        ini_set('session.use_strict_mode', '0');

        $this->assertSame(SecurityFinding::FAIL, $this->statusOf('session_strict_mode'));
    }

    public function test_session_ids_allowed_into_urls_are_reported_as_a_failure(): void
    {
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('session_cookie_only'));

        ini_set('session.use_trans_sid', '1');

        $this->assertSame(SecurityFinding::FAIL, $this->statusOf('session_cookie_only'));
    }

    public function test_the_save_path_row_follows_the_directive_and_not_the_intention(): void
    {
        $expected = $this->dataDirectory . '/storage/sessions';
        ini_set('session.save_path', $expected);

        $this->assertSame(
            SecurityFinding::PASS,
            $this->statusOf('session_save_path', $this->context(expectedSessionSavePath: $expected))
        );

        // The state RuntimeHardening leaves behind when it cannot create or
        // write the directory: the host default, silently.
        ini_set('session.save_path', sys_get_temp_dir());

        $finding = $this->finding('session_save_path', $this->context(expectedSessionSavePath: $expected));
        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertSame(sys_get_temp_dir(), $finding->observed);
    }

    public function test_the_save_path_row_reads_phps_depth_prefixed_form(): void
    {
        $expected = $this->dataDirectory . '/storage/sessions';
        // PHP accepts "N;/path" and "N;mode;/path"; the path is the last field.
        ini_set('session.save_path', "2;0600;{$expected}");

        $this->assertSame(
            SecurityFinding::PASS,
            $this->statusOf('session_save_path', $this->context(expectedSessionSavePath: $expected))
        );
    }

    public function test_the_save_path_row_is_left_out_when_the_surface_has_no_expectation(): void
    {
        // The installer's own session is not the application's, and a row about
        // a path nothing has chosen yet would be a finding about nothing.
        $this->assertNull($this->find('session_save_path', SecuritySelfCheck::run($this->context())));
    }

    public function test_the_cookie_rows_report_the_attributes_php_will_emit(): void
    {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);

        $this->assertSame(SecurityFinding::PASS, $this->statusOf('session_cookie_httponly'));
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('session_cookie_samesite'));
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('session_cookie_secure', $this->context(https: true)));

        session_set_cookie_params(['httponly' => false, 'samesite' => '', 'secure' => false]);

        $this->assertSame(SecurityFinding::FAIL, $this->statusOf('session_cookie_httponly'));
        $this->assertSame(SecurityFinding::WARN, $this->statusOf('session_cookie_samesite'));
    }

    /**
     * A browser drops a Secure cookie sent over plain HTTP, so on an
     * installation that is not served over TLS the missing flag is a
     * consequence of the transport — reported there, not as a second red row
     * with no remedy attached.
     */
    public function test_a_cookie_without_secure_fails_only_when_the_request_was_https(): void
    {
        session_set_cookie_params(['secure' => false, 'httponly' => true, 'samesite' => 'Lax']);

        $this->assertSame(SecurityFinding::WARN, $this->statusOf('session_cookie_secure', $this->context(https: false)));
        $this->assertSame(SecurityFinding::FAIL, $this->statusOf('session_cookie_secure', $this->context(https: true)));
    }

    // ------------------------------------------------------------------
    // Data placement and permissions
    // ------------------------------------------------------------------

    public function test_data_inside_the_document_root_is_a_warning_that_carries_the_hosts_reason(): void
    {
        $finding = $this->finding('data_directory', $this->context(
            dataDirectoryReason: 'No writable directory above the document root (/tmp is not writable).'
        ));

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('is not writable', (string) $finding->remedy);
    }

    public function test_data_above_the_document_root_passes(): void
    {
        $outside = $this->documentRoot . '-data';
        mkdir($outside . '/storage', 0700, true);
        mkdir($outside . '/logs', 0700, true);

        try {
            $this->assertSame(SecurityFinding::PASS, $this->statusOf('data_directory', $this->context(dataDirectory: $outside)));
        } finally {
            $this->removeTree($outside);
        }
    }

    public function test_a_world_readable_data_directory_is_reported(): void
    {
        chmod($this->dataDirectory . '/storage', 0700);
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('storage_directory_mode'));

        // What the package build leaves behind, and what ADR-0031 decision 4
        // is about: a convenience that survives into production.
        chmod($this->dataDirectory . '/storage', 0777);

        $finding = $this->finding('storage_directory_mode');
        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertSame('0777', $finding->observed);
    }

    /**
     * The document root is the one path measured by a different rule, and the
     * rule has to hold in both directions: `0755` is what a served directory
     * looks like and must not be a finding, while a write bit for anyone else
     * means any foothold on the machine can drop in a `.php` file the webserver
     * will execute (#248).
     */
    public function test_a_world_writable_document_root_is_reported_while_a_served_one_passes(): void
    {
        chmod($this->documentRoot, 0755);
        $this->assertSame(SecurityFinding::PASS, $this->statusOf('document_root_mode'));

        chmod($this->documentRoot, 0777);

        $finding = $this->finding('document_root_mode');
        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertSame('0777', $finding->observed);
        $this->assertStringContainsString('can be written by other users', (string) $finding->remedy);
    }

    public function test_the_configuration_files_mode_is_measured_when_there_is_one(): void
    {
        $configFile = $this->documentRoot . '/config.php';
        file_put_contents($configFile, "<?php return [];\n");
        chmod($configFile, 0600);

        $this->assertSame(SecurityFinding::PASS, $this->statusOf('config_file_mode', $this->context(configFile: $configFile)));

        chmod($configFile, 0644);

        $this->assertSame(SecurityFinding::WARN, $this->statusOf('config_file_mode', $this->context(configFile: $configFile)));
    }

    public function test_a_file_that_is_not_there_is_unknown_rather_than_passed(): void
    {
        $finding = $this->finding('config_file_mode', $this->context(
            configFile: $this->documentRoot . '/config.php'
        ));

        $this->assertSame(SecurityFinding::UNKNOWN, $finding->status);
    }

    // ------------------------------------------------------------------
    // Exposure
    // ------------------------------------------------------------------

    /**
     * The stronger claim, and the one that does not depend on the host letting
     * us fetch our own URLs: a file that is not under the document root cannot
     * be served by any rule, honoured or ignored.
     */
    public function test_data_outside_the_document_root_needs_no_http_probe(): void
    {
        $outside = $this->documentRoot . '-data';
        mkdir($outside . '/storage', 0700, true);
        mkdir($outside . '/logs', 0700, true);

        try {
            $context = $this->context(dataDirectory: $outside, baseUrlCandidates: ['http://127.0.0.1:1']);
            $findings = SecuritySelfCheck::run($context);

            $this->assertSame(SecurityFinding::PASS, $this->find('mandate_not_served', $findings)?->status);
            $this->assertSame(SecurityFinding::PASS, $this->find('logs_not_served', $findings)?->status);
        } finally {
            $this->removeTree($outside);
        }
    }

    /**
     * The rule the whole report rests on. A webserver that cannot be reached
     * says nothing about whether it would hand out a mandate, and "we could not
     * ask" must never be rendered as "the answer was no".
     */
    public function test_an_unreachable_webserver_yields_unknown_and_never_a_pass(): void
    {
        // Port 1: nothing listens there, so the control fetch cannot succeed.
        $findings = SecuritySelfCheck::run($this->context(baseUrlCandidates: ['http://127.0.0.1:1']));

        foreach (['mandate_not_served', 'logs_not_served'] as $id) {
            $finding = $this->find($id, $findings);
            $this->assertSame(SecurityFinding::UNKNOWN, $finding?->status, $id);
            $this->assertNotNull($finding?->remedy, "{$id} must say what to check by hand");
        }
    }

    public function test_the_probe_leaves_no_canary_behind(): void
    {
        SecuritySelfCheck::run($this->context(baseUrlCandidates: ['http://127.0.0.1:1']));

        $this->assertSame([], glob($this->dataDirectory . '/logs/*') ?: []);
        $this->assertDirectoryDoesNotExist(
            $this->dataDirectory . '/storage/mandates',
            'A directory the check created to place a canary in is removed again'
        );
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    public function test_plain_http_is_reported_and_takes_hsts_with_it(): void
    {
        $findings = SecuritySelfCheck::run($this->context(https: false));

        $this->assertSame(SecurityFinding::WARN, $this->find('https', $findings)?->status);
        $this->assertSame(
            SecurityFinding::UNKNOWN,
            $this->find('hsts', $findings)?->status,
            'A browser ignores HSTS on a plain-HTTP response, so there is nothing to measure yet'
        );
    }

    public function test_https_passes_while_a_missing_hsts_header_warns(): void
    {
        $findings = SecuritySelfCheck::run($this->context(https: true, baseUrlCandidates: ['https://127.0.0.1:1']));

        $this->assertSame(SecurityFinding::PASS, $this->find('https', $findings)?->status);
        // Nothing answered, so the header could not be read — unknown, not a pass.
        $this->assertSame(SecurityFinding::UNKNOWN, $this->find('hsts', $findings)?->status);
    }

    // ------------------------------------------------------------------
    // The report as a whole
    // ------------------------------------------------------------------

    public function test_every_row_that_is_not_green_says_what_to_do_about_it(): void
    {
        ini_set('display_errors', '1');
        ini_set('session.use_strict_mode', '0');
        chmod($this->dataDirectory . '/storage', 0777);

        foreach (SecuritySelfCheck::run($this->context()) as $finding) {
            if ($finding->isPass()) {
                $this->assertNull($finding->remedy, "{$finding->id} passed and needs no remedy");
                continue;
            }

            $this->assertNotEmpty($finding->remedy, "{$finding->id} is not green and must say why it matters");
        }
    }

    public function test_the_summary_counts_every_row_exactly_once(): void
    {
        $findings = SecuritySelfCheck::run($this->context());
        $summary = SecuritySelfCheck::summarize($findings);

        $this->assertSame(count($findings), array_sum($summary));
    }

    public function test_row_ids_are_unique_so_a_surface_can_key_on_them(): void
    {
        $ids = array_map(fn(SecurityFinding $f) => $f->id, SecuritySelfCheck::run($this->context()));

        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function context(
        ?string $dataDirectory = null,
        ?string $configFile = null,
        ?string $dataDirectoryReason = null,
        ?string $expectedSessionSavePath = null,
        bool $https = false,
        bool $debug = false,
        array $baseUrlCandidates = [],
    ): SecurityCheckContext {
        return new SecurityCheckContext(
            documentRoot: $this->documentRoot,
            dataDirectory: $dataDirectory ?? $this->dataDirectory,
            configFile: $configFile,
            dataDirectoryReason: $dataDirectoryReason,
            expectedSessionSavePath: $expectedSessionSavePath,
            https: $https,
            debug: $debug,
            baseUrlCandidates: $baseUrlCandidates,
        );
    }

    /** The state RuntimeHardening leaves the process in, as a starting point. */
    private function applyHardenedDirectives(): void
    {
        foreach (SecuritySelfCheck::EXPECTED_DIRECTIVES as $directive => $enabled) {
            ini_set($directive, $enabled ? '1' : '0');
        }
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => false]);
    }

    private function statusOf(string $id, ?SecurityCheckContext $context = null): string
    {
        return $this->finding($id, $context)->status;
    }

    private function finding(string $id, ?SecurityCheckContext $context = null): SecurityFinding
    {
        $finding = $this->find($id, SecuritySelfCheck::run($context ?? $this->context()));
        $this->assertNotNull($finding, "No finding with id {$id}");

        return $finding;
    }

    /** @param list<SecurityFinding> $findings */
    private function find(string $id, array $findings): ?SecurityFinding
    {
        foreach ($findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        return null;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$directory}/{$entry}";
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
