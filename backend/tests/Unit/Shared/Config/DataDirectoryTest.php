<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\DataDirectory;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0031 decision 2. The thing under test is a placement decision made once,
 * on a host nobody can log into afterwards, about the two assets this system
 * exists to protect — so every branch of it is exercised against a real
 * directory tree rather than a mocked filesystem.
 */
class DataDirectoryTest extends TestCase
{
    private string $root;
    private string $documentRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // A stand-in for a hosting account: a document root with a parent the
        // installer may or may not be allowed to write to.
        $this->root = sys_get_temp_dir() . '/clubbar-datadir-' . bin2hex(random_bytes(6));
        $this->documentRoot = $this->root . '/htdocs';
        mkdir($this->documentRoot . '/backend/storage/mandates', 0777, true);
        mkdir($this->documentRoot . '/backend/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
        parent::tearDown();
    }

    // ── resolution ────────────────────────────────────────────────────

    public function test_without_a_pointer_the_data_stays_where_it_has_always_been(): void
    {
        $this->assertSame(
            $this->documentRoot . '/backend',
            DataDirectory::resolve($this->documentRoot),
            'An installation that predates the pointer must resolve to its own layout'
        );
    }

    public function test_a_pointer_moves_the_data_directory(): void
    {
        $target = $this->root . '/clubbar-data';
        mkdir($target);

        DataDirectory::writePointer($this->documentRoot, $target);

        $this->assertSame($target, DataDirectory::resolve($this->documentRoot));
    }

    /**
     * A half-restored backup or a reverted move must degrade to the layout that
     * still works, not take the installation down.
     */
    public function test_a_pointer_to_a_missing_directory_is_ignored(): void
    {
        DataDirectory::writePointer($this->documentRoot, $this->root . '/was-deleted');

        $this->assertSame($this->documentRoot . '/backend', DataDirectory::resolve($this->documentRoot));
    }

    public function test_the_pointer_is_php_so_a_host_ignoring_htaccess_cannot_serve_it(): void
    {
        DataDirectory::writePointer($this->documentRoot, $this->root);

        $contents = (string) file_get_contents(DataDirectory::pointerPath($this->documentRoot));

        $this->assertStringStartsWith('<?php', $contents);
        $this->assertStringEndsWith('.php', DataDirectory::POINTER_FILE);
    }

    public function test_removing_the_pointer_falls_back_to_the_document_root(): void
    {
        $target = $this->root . '/clubbar-data';
        mkdir($target);
        DataDirectory::writePointer($this->documentRoot, $target);

        DataDirectory::removePointer($this->documentRoot);

        $this->assertSame($this->documentRoot . '/backend', DataDirectory::resolve($this->documentRoot));
    }

    // ── the config lookup ─────────────────────────────────────────────

    public function test_a_config_in_the_data_directory_wins(): void
    {
        $target = $this->root . '/clubbar-data';
        mkdir($target);
        file_put_contents($target . '/config.php', '<?php return [];');
        file_put_contents($this->documentRoot . '/config.php', '<?php return [];');
        DataDirectory::writePointer($this->documentRoot, $target);

        $this->assertSame($target . '/config.php', DataDirectory::configPath($this->documentRoot));
    }

    public function test_a_pre_existing_config_next_to_index_php_is_still_found(): void
    {
        file_put_contents($this->documentRoot . '/config.php', '<?php return [];');

        $this->assertSame(
            $this->documentRoot . '/config.php',
            DataDirectory::configPath($this->documentRoot),
            'An install made before #245 has to keep booting after the upgrade'
        );
    }

    public function test_with_no_config_anywhere_the_data_directory_is_where_it_goes(): void
    {
        $this->assertSame(
            $this->documentRoot . '/config.php',
            DataDirectory::configPath($this->documentRoot)
        );
    }

    // ── probing ───────────────────────────────────────────────────────

    public function test_a_writable_parent_is_used(): void
    {
        $probe = DataDirectory::probe($this->documentRoot);

        $this->assertTrue($probe['outside']);
        $this->assertSame($this->root . '/' . DataDirectory::DIRECTORY_NAME, $probe['path']);
    }

    public function test_an_unwritable_parent_degrades_to_the_document_root_with_a_reason(): void
    {
        chmod($this->root, 0555);

        $probe = DataDirectory::probe($this->documentRoot);

        // Running the suite as root defeats permission bits entirely — the
        // branch is real, this process just cannot be refused.
        if (is_writable($this->root)) {
            $this->markTestSkipped('Running as a user that bypasses directory permissions');
        }

        $this->assertFalse($probe['outside']);
        $this->assertSame($this->documentRoot . '/backend', $probe['path']);
        $this->assertStringContainsString('not writable', $probe['reason']);

        chmod($this->root, 0755);
    }

    public function test_a_document_root_at_the_filesystem_root_degrades(): void
    {
        $probe = DataDirectory::probe('/');

        $this->assertFalse($probe['outside']);
        $this->assertNotSame('', $probe['reason']);
    }

    public function test_the_degraded_choice_always_explains_itself(): void
    {
        $probe = DataDirectory::probe('/');

        $this->assertStringContainsString('document root', $probe['reason']);
    }

    // ── preparing ─────────────────────────────────────────────────────

    public function test_preparing_creates_the_subdirectories_the_app_expects(): void
    {
        $target = $this->root . '/clubbar-data';

        $result = DataDirectory::prepare($target);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertDirectoryExists($target . '/storage');
        $this->assertDirectoryExists($target . '/logs');
    }

    public function test_preparing_reports_a_directory_it_cannot_create(): void
    {
        $result = DataDirectory::prepare($this->documentRoot . '/backend/logs/nested/deeper');

        // The parent is a writable directory, so this must succeed; the failure
        // path is asserted below against a file standing in for a directory.
        $this->assertTrue($result['ok'], (string) $result['error']);

        $blocked = $this->root . '/not-a-directory';
        file_put_contents($blocked, 'x');

        $result = DataDirectory::prepare($blocked . '/data');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Could not create', (string) $result['error']);
    }

    // ── relocation ────────────────────────────────────────────────────

    public function test_relocating_moves_config_mandates_and_logs_out_of_the_document_root(): void
    {
        $this->seedInstallation();
        $target = $this->root . '/clubbar-data';

        $result = DataDirectory::relocate($this->documentRoot, $target);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertFileExists($target . '/config.php');
        $this->assertFileExists($target . '/storage/mandates/member.pdf');
        $this->assertFileExists($target . '/logs/2026-08-09.log');
        $this->assertSame($target, DataDirectory::resolve($this->documentRoot));
    }

    /** The point of the move: what is left behind must not be the secret itself. */
    public function test_relocating_leaves_nothing_readable_behind(): void
    {
        $this->seedInstallation();

        DataDirectory::relocate($this->documentRoot, $this->root . '/clubbar-data');

        $this->assertFileDoesNotExist($this->documentRoot . '/config.php');
        $this->assertFileDoesNotExist($this->documentRoot . '/backend/storage/mandates/member.pdf');
        $this->assertFileDoesNotExist($this->documentRoot . '/backend/logs/2026-08-09.log');
    }

    /**
     * The `.htaccess` denials the build writes into storage/ and logs/ are the
     * only protection the fallback layout has. A move must not strip them —
     * moving back would otherwise land the mandates in an undefended directory.
     */
    public function test_relocating_keeps_the_denials_in_the_emptied_directories(): void
    {
        $this->seedInstallation();
        file_put_contents($this->documentRoot . '/backend/storage/.htaccess', "Require all denied\n");

        DataDirectory::relocate($this->documentRoot, $this->root . '/clubbar-data');

        $this->assertFileExists($this->documentRoot . '/backend/storage/.htaccess');
    }

    /**
     * The denial is the package's, not the installation's. Carrying it along
     * also breaks the move back: on most hosts the shipped `.htaccess` is not
     * writable by the web server, so copying over it fails and takes the whole
     * relocation with it.
     */
    public function test_the_denials_are_not_carried_into_the_new_location(): void
    {
        $this->seedInstallation();
        file_put_contents($this->documentRoot . '/backend/storage/.htaccess', "Require all denied\n");
        $target = $this->root . '/clubbar-data';

        DataDirectory::relocate($this->documentRoot, $target);

        $this->assertFileDoesNotExist($target . '/storage/.htaccess');
        $this->assertFileExists($target . '/storage/mandates/member.pdf');
    }

    public function test_relocation_is_reversible(): void
    {
        $this->seedInstallation();
        $target = $this->root . '/clubbar-data';
        DataDirectory::relocate($this->documentRoot, $target);

        $back = DataDirectory::relocate($this->documentRoot, DataDirectory::inDocumentRoot($this->documentRoot));
        DataDirectory::removePointer($this->documentRoot);

        $this->assertTrue($back['ok'], (string) $back['error']);
        $this->assertFileExists($this->documentRoot . '/config.php');
        $this->assertFileExists($this->documentRoot . '/backend/storage/mandates/member.pdf');
        $this->assertSame($this->documentRoot . '/backend', DataDirectory::resolve($this->documentRoot));
    }

    public function test_relocating_to_the_current_location_does_nothing(): void
    {
        $this->seedInstallation();

        $result = DataDirectory::relocate($this->documentRoot, $this->documentRoot . '/backend');

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['moved']);
        $this->assertFileExists($this->documentRoot . '/config.php');
    }

    /**
     * A failed move must leave an installation that still boots — which means
     * the originals are still there and the pointer still names them.
     */
    public function test_a_failed_move_deletes_nothing(): void
    {
        $this->seedInstallation();
        $blocked = $this->root . '/not-a-directory';
        file_put_contents($blocked, 'x');

        $result = DataDirectory::relocate($this->documentRoot, $blocked . '/data');

        $this->assertFalse($result['ok']);
        $this->assertFileExists($this->documentRoot . '/config.php');
        $this->assertFileExists($this->documentRoot . '/backend/storage/mandates/member.pdf');
        $this->assertSame($this->documentRoot . '/backend', DataDirectory::resolve($this->documentRoot));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function seedInstallation(): void
    {
        file_put_contents($this->documentRoot . '/config.php', "<?php return ['db' => []];");
        file_put_contents($this->documentRoot . '/backend/storage/mandates/member.pdf', '%PDF-1.4');
        file_put_contents($this->documentRoot . '/backend/logs/2026-08-09.log', '{"level":"INFO"}');
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = "{$path}/{$entry}";
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
