<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\PhpRuntime;
use PHPUnit\Framework\TestCase;

/**
 * What the drain records about its own interpreter (#403).
 *
 * The reason this class exists is that on mass hosting the panel's CLI PHP is
 * frequently not the web PHP, and a drain running under a build without
 * `openssl` cannot open a TLS connection to the mail server while the same code
 * reached through the browser can. These tests mostly pin the *shape* of the
 * answer, because the answer itself is whatever interpreter is running them.
 */
class PhpRuntimeTest extends TestCase
{
    public function test_the_required_set_is_the_sending_path_not_the_whole_application(): void
    {
        // Narrower than the installer's list on purpose. `fileinfo` types
        // uploads and `sodium` opens sealed IBANs; neither is on the way to an
        // SMTP socket, and naming them here would report a gap the cron has not
        // got.
        $this->assertSame(['pdo_mysql', 'json', 'mbstring', 'openssl'], PhpRuntime::REQUIRED_EXTENSIONS);
    }

    public function test_the_version_fits_the_column_that_stores_it(): void
    {
        $this->assertLessThanOrEqual(32, strlen(PhpRuntime::version()));
        $this->assertStringStartsWith(PHP_MAJOR_VERSION . '.', PhpRuntime::version());
    }

    public function test_missing_extensions_reports_only_what_is_actually_absent(): void
    {
        $missing = PhpRuntime::missingExtensions();

        $this->assertSame(array_values($missing), $missing, 'the column stores a list, not a sparse array');
        $this->assertEmpty(array_diff($missing, PhpRuntime::REQUIRED_EXTENSIONS));

        foreach (PhpRuntime::REQUIRED_EXTENSIONS as $extension) {
            $this->assertSame(
                !extension_loaded($extension),
                in_array($extension, $missing, true),
                "{$extension} is reported as missing if and only if it is not loaded"
            );
        }
    }

    public function test_the_summary_is_empty_rather_than_null_when_nothing_is_missing(): void
    {
        // '' means checked and complete; NULL in the column means never
        // checked. They are different statements and the drain must be able to
        // make the first one.
        $summary = PhpRuntime::missingExtensionsSummary();

        $this->assertSame(implode(',', PhpRuntime::missingExtensions()), $summary);
        $this->assertLessThanOrEqual(255, strlen($summary));
    }

    public function test_the_test_suite_itself_runs_on_the_cli(): void
    {
        // Which is also the guard `bin/cron.php` uses to refuse being reached
        // as a URL if the host ever stops honouring .htaccess.
        $this->assertTrue(PhpRuntime::isCli());
    }
}
