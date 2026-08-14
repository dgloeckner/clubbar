<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Process;

use App\Shared\Process\FileLock;
use PHPUnit\Framework\TestCase;

/**
 * The lock that stops one drain run being overtaken by the next (#403).
 *
 * `flock` is per open file description, so two `FileLock` objects in one
 * process contend exactly the way two processes do — which is what makes this
 * testable without spawning anything. The end-to-end version, over a real
 * `bin/cron.php` subprocess, is in
 * {@see \Tests\Feature\Modules\Notifications\CronScriptTest}.
 */
class FileLockTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/file-lock-' . bin2hex(random_bytes(6)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_a_second_holder_is_turned_away_rather_than_made_to_wait(): void
    {
        // Non-blocking on purpose: a second run that waits is still a second
        // process held open, which is the thing being avoided.
        $first = new FileLock($this->path);
        $second = new FileLock($this->path);

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire());
    }

    public function test_the_lock_is_available_again_once_released(): void
    {
        $first = new FileLock($this->path);
        $first->acquire();
        $first->release();

        $this->assertTrue((new FileLock($this->path))->acquire());
    }

    public function test_releasing_a_lock_that_was_never_taken_is_harmless(): void
    {
        $lock = new FileLock($this->path);
        $lock->release();
        $lock->release();

        $this->assertTrue($lock->acquire());
    }

    public function test_acquiring_twice_from_the_same_holder_is_idempotent(): void
    {
        $lock = new FileLock($this->path);

        $this->assertTrue($lock->acquire());
        $this->assertTrue($lock->acquire());
    }

    public function test_a_released_lock_leaves_its_file_behind(): void
    {
        // Deliberate: unlinking races. Another process may already have opened
        // this inode and be about to lock it, and would then hold a lock on a
        // file nobody else can see.
        $lock = new FileLock($this->path);
        $lock->acquire();
        $lock->release();

        $this->assertFileExists($this->path);
    }

    public function test_an_unwritable_location_throws_instead_of_looking_locked(): void
    {
        // Treating "cannot create the file" as "somebody else holds it" would
        // stop every future run and present as a queue that never moves.
        $lock = new FileLock(sys_get_temp_dir() . '/definitely/not/a/directory/cron.lock');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/writable/');

        $lock->acquire();
    }

    public function test_the_lock_file_names_the_process_that_holds_it(): void
    {
        // For a human reading it after a run that never finished. Nothing reads
        // it back.
        $lock = new FileLock($this->path);
        $lock->acquire();

        $this->assertSame((string) getmypid(), file_get_contents($this->path));
    }

    public function test_going_out_of_scope_releases_the_lock(): void
    {
        (function (): void {
            $lock = new FileLock($this->path);
            $lock->acquire();
        })();

        $this->assertTrue((new FileLock($this->path))->acquire());
    }
}
