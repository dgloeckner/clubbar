<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\RetiredFiles;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The sweep that takes a file an older release shipped out of the document
 * root when an installation upgrades (#751).
 *
 * Every case here is about what it must *not* delete. The sweep runs against a
 * live installation — `config.php`, `data-path.php`, whatever the club
 * uploaded — so the interesting assertions are the neighbours that survive.
 */
class RetiredFilesTest extends TestCase
{
    use TempTree;

    private string $documentRoot = '';

    protected function setUp(): void
    {
        $this->documentRoot = self::makeTempTree('retired-files');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->documentRoot);
    }

    private function write(string $relative, string $contents = 'x'): string
    {
        $path = $this->documentRoot . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_removes_the_retired_template_and_says_so(): void
    {
        $sample = $this->write('config.sample.php');

        $removed = RetiredFiles::sweep($this->documentRoot);

        $this->assertFalse(is_file($sample), 'the retired copy survived the sweep');
        $this->assertSame(['config.sample.php'], $removed);
    }

    /**
     * The one that matters. `config.php` is the installation's own file — the
     * database password, the TOTP encryption key — and it differs from the
     * retired name by six characters.
     */
    public function test_it_leaves_the_installations_own_files_alone(): void
    {
        $keep = [
            'config.php',
            'data-path.php',
            'index.php',
            'spa.html',
            'README.txt',
            'backend/config.sample.php',
        ];
        foreach ($keep as $name) {
            $this->write($name);
        }

        RetiredFiles::sweep($this->documentRoot);

        foreach ($keep as $name) {
            $this->assertTrue(
                is_file($this->documentRoot . '/' . $name),
                sprintf('the sweep deleted %s, which the installation needs', $name)
            );
        }
    }

    /**
     * The template lives in `backend/` now, and the sweep is given the document
     * root — so a sweep that resolved names loosely would delete the very file
     * the installer reads when it writes `config.php`.
     */
    public function test_it_does_not_reach_into_backend(): void
    {
        $shipped = $this->write('backend/config.sample.php');

        RetiredFiles::sweep($this->documentRoot);

        $this->assertTrue(is_file($shipped), 'the sweep deleted the template the installer reads');
    }

    public function test_an_installation_that_never_had_the_file_is_untouched(): void
    {
        $this->assertSame([], RetiredFiles::sweep($this->documentRoot));
    }

    /**
     * An unusable document root deletes nothing rather than resolving to `/`.
     * This project has already lost a container to `'' . '/*'` — see the
     * destructive-cleanup rule in CLAUDE.md, and `TempTree`.
     */
    public function test_an_empty_or_missing_document_root_deletes_nothing(): void
    {
        $this->assertSame([], RetiredFiles::sweep(''));
        $this->assertSame([], RetiredFiles::sweep($this->documentRoot . '/does-not-exist'));
    }

    /** A trailing slash is a path, not a second sweep target. */
    public function test_a_trailing_slash_is_handled(): void
    {
        $sample = $this->write('config.sample.php');

        $this->assertSame(['config.sample.php'], RetiredFiles::sweep($this->documentRoot . '/'));
        $this->assertFalse(is_file($sample));
    }

    /**
     * The list is basenames, and nothing in it may escape the directory it is
     * joined to. A guard on the data rather than on the caller, because the
     * caller is a document root and the data is what a future entry edits.
     */
    public function test_every_retired_name_is_a_plain_basename(): void
    {
        foreach (RetiredFiles::RETIRED as $name) {
            $this->assertSame(
                basename($name),
                $name,
                sprintf('%s is not a plain basename — it can escape the document root', $name)
            );
            $this->assertNotSame('', $name);
        }
    }

    /**
     * A directory sharing a retired name is left alone: `unlink()` cannot
     * remove one, and a sweep that reached for `rmdir()` would be deleting a
     * tree it never enumerated.
     */
    public function test_a_directory_with_a_retired_name_is_left_alone(): void
    {
        $path = $this->documentRoot . '/config.sample.php';
        mkdir($path);
        file_put_contents($path . '/inside.txt', 'x');

        $this->assertSame([], RetiredFiles::sweep($this->documentRoot));
        $this->assertTrue(is_dir($path));

        unlink($path . '/inside.txt');
        rmdir($path);
    }
}
