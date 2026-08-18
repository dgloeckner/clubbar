<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The guard that keeps a cleanup routine inside the temp directory.
 *
 * This is a regression test for a real incident, not a hypothetical: a
 * `tearDown()` that ran after its `setUp()` had skipped globbed `'' . '/*'`,
 * which is `/`, and unlinked the root-level symlinks of the backend container
 * — `/lib64` among them, which is the ELF interpreter's path, after which no
 * dynamically linked binary in that container could be executed at all.
 */
class TempTreeTest extends TestCase
{
    use TempTree;

    public function test_a_temp_directory_and_its_contents_are_removed(): void
    {
        $dir = self::makeTempTree('temp-tree-test');
        file_put_contents($dir . '/file.txt', 'x');
        mkdir($dir . '/nested');
        file_put_contents($dir . '/nested/deep.txt', 'y');

        self::removeTempTree($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    /**
     * The incident itself. An unassigned path must delete nothing — least of
     * all the root directory, whose entries are exactly what was lost.
     */
    public function test_an_empty_path_deletes_nothing(): void
    {
        self::removeTempTree('');

        $this->assertTrue(file_exists('/etc'), '/etc must survive a cleanup with no path');
        $this->assertTrue(
            is_dir('/bin') || is_link('/bin'),
            '/bin must survive — on a merged-usr system it is a symlink, and unlink() removes those'
        );
    }

    public function test_the_root_directory_itself_is_refused(): void
    {
        self::removeTempTree('/');

        $this->assertTrue(file_exists('/etc'));
    }

    /**
     * Outside the temp directory the routine declines rather than guessing.
     * Written against a directory that really exists, because the guard has to
     * refuse on *location*, not merely on absence.
     */
    public function test_a_directory_outside_the_temp_directory_is_left_alone(): void
    {
        $outside = __DIR__ . '/temp-tree-guard-' . bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'x');

        try {
            self::removeTempTree($outside);

            $this->assertFileExists($outside . '/keep.txt');
        } finally {
            @unlink($outside . '/keep.txt');
            @rmdir($outside);
        }
    }

    public function test_a_path_that_does_not_exist_is_not_an_error(): void
    {
        self::removeTempTree(sys_get_temp_dir() . '/temp-tree-absent-' . bin2hex(random_bytes(4)));

        $this->addToAssertionCount(1);
    }
}
