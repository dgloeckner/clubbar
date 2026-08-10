<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\FileModes;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0031 decision 4 is two claims, and this file is about the second one.
 *
 * The first — "the mode gets narrower" — is the easy half. The second is that
 * narrowing must never be what takes an installation down: the treasurer's club
 * loses its bar till over a permission bit that was set for their benefit. So
 * almost every test here checks *both* ends: the mode came down, **and** the
 * path still does the job it was doing before.
 */
class FileModesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/clubbar-filemodes-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Directories: storage/ and logs/
    // ------------------------------------------------------------------

    public function test_a_world_writable_directory_comes_down_to_owner_only_and_stays_writable(): void
    {
        $storage = $this->directory('storage', 0777);

        $outcome = FileModes::narrowDataDirectory($storage);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(0777, $outcome['original']);
        $this->assertSame(0700, $outcome['applied']);
        $this->assertSame(0700, $this->modeOf($storage));
        $this->assertNull($outcome['error']);
        // The half that matters more than the mode.
        $this->assertNotFalse(@file_put_contents($storage . '/mandate.pdf', 'x'));
    }

    /**
     * `0770` and `0777` are rungs on the ladder, not fallbacks to reach for: a
     * directory that is merely group-readable must not come out of this with
     * group *write* added.
     */
    public function test_narrowing_never_widens(): void
    {
        $logs = $this->directory('logs', 0755);

        $outcome = FileModes::narrowDataDirectory($logs);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(0700, $outcome['applied']);
        $this->assertSame(0700, $this->modeOf($logs));
    }

    /**
     * The no-op case, and the reason it is a case at all: `chmod` is the
     * owner's privilege, so calling it on a path that is already `0700` would
     * fail on a host whose PHP process does not own the files — and would be
     * reported as a refusal to harden something that needs no hardening.
     */
    public function test_a_directory_that_is_already_owner_only_is_left_untouched(): void
    {
        $storage = $this->directory('storage', 0700);

        $outcome = FileModes::narrowDataDirectory($storage);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(0700, $outcome['original']);
        $this->assertSame(0700, $outcome['applied']);
        $this->assertFalse($outcome['restored']);
    }

    public function test_a_mode_no_ladder_rung_can_narrow_is_reported_rather_than_forced(): void
    {
        // Group-only, owner-nothing: every rung would *grant* the owner rights
        // this directory does not currently give. Contrived, but it is the one
        // shape where "narrow" has no answer, and the answer must not be to
        // widen.
        $odd = $this->directory('odd', 0070);

        $outcome = FileModes::narrowDataDirectory($odd);

        $this->assertFalse($outcome['ok']);
        $this->assertSame(0070, $this->modeOf($odd), 'Nothing may be changed when nothing can be narrowed');
        $this->assertStringContainsString('0070', (string) $outcome['error']);
    }

    // ------------------------------------------------------------------
    // config.php
    // ------------------------------------------------------------------

    public function test_a_world_readable_config_comes_down_to_owner_only_with_its_contents_intact(): void
    {
        $config = $this->configFile(0644);

        $outcome = FileModes::narrowConfigFile($config);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(0600, $outcome['applied']);
        $this->assertSame(0600, $this->modeOf($config));
        // "Readable by PHP" is the acceptance criterion, so it is what is
        // asserted — not the mode a second time.
        $this->assertSame(['db' => ['pass' => 'secret']], require $config);
    }

    public function test_a_group_readable_config_still_loses_the_group_bit(): void
    {
        $config = $this->configFile(0640);

        $outcome = FileModes::narrowConfigFile($config);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(0600, $this->modeOf($config));
        $this->assertNull($outcome['error']);
    }

    public function test_a_path_that_is_not_there_is_reported_and_nothing_is_invented(): void
    {
        $outcome = FileModes::narrowConfigFile($this->root . '/absent.php');

        $this->assertFalse($outcome['ok']);
        $this->assertNull($outcome['original']);
        $this->assertNull($outcome['applied']);
        $this->assertStringContainsString('absent.php', (string) $outcome['error']);
    }

    /**
     * The host that ADR-0031's mitigation is written for: the files belong to
     * one user and PHP runs as another, so `chmod` is refused outright.
     *
     * A procfs file is the portable way to get that answer in a test: it is
     * `0644` and readable, so the ladder genuinely tries to narrow it, and the
     * kernel refuses `chmod` on it for everyone — root included. That is the
     * same "not yours to change" this code has to survive on real hosting. What
     * matters is the response: report it, change nothing, and let the security
     * self-check show the loose mode rather than pretend it was fixed.
     */
    public function test_a_chmod_the_host_refuses_is_reported_and_leaves_the_path_alone(): void
    {
        $refuses = '/proc/sys/kernel/hostname';
        if (!is_file($refuses) || !is_readable($refuses) || (fileperms($refuses) & 0077) === 0) {
            $this->markTestSkipped('Needs a readable procfs path with group or other bits set');
        }

        $before = fileperms($refuses) & 0777;

        $outcome = FileModes::narrowConfigFile($refuses);

        $this->assertFalse($outcome['ok']);
        $this->assertSame($before, $outcome['applied'], 'A refused chmod must report the mode that is still in force');
        $this->assertSame($before, fileperms($refuses) & 0777);
        $this->assertStringContainsString('owned by another user', (string) $outcome['error']);
    }

    // ------------------------------------------------------------------
    // The three paths together
    // ------------------------------------------------------------------

    public function test_hardening_covers_the_config_both_writable_directories_and_the_document_root(): void
    {
        $config  = $this->configFile(0666);
        $storage = $this->directory('storage', 0777);
        $logs    = $this->directory('logs', 0777);
        chmod($this->root, 0777);

        $outcomes = FileModes::hardenData($this->root, $config, ['storage', 'logs'], $this->root);

        $this->assertCount(4, $outcomes);
        $this->assertSame([], array_filter($outcomes, static fn(array $o): bool => !$o['ok']));
        $this->assertSame(0600, $this->modeOf($config));
        $this->assertSame(0700, $this->modeOf($storage));
        $this->assertSame(0700, $this->modeOf($logs));
        $this->assertSame(0755, $this->modeOf($this->root));
    }

    /**
     * The document root is the one path narrowed on the weaker rule, and both
     * halves matter: a `0777` document root is a place anything on the machine
     * can drop a `.php` file the webserver will run, while the read bits have to
     * survive — the webserver is serving from here.
     */
    public function test_a_world_writable_document_root_loses_its_write_bits_and_keeps_the_rest(): void
    {
        $root = $this->directory('docroot', 0777);

        $outcome = FileModes::narrowDocumentRoot($root);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(0755, $outcome['applied']);
        $this->assertSame(0755, $this->modeOf($root));
        $this->assertNotFalse(@file_put_contents($root . '/config.php', '<?php return [];'));
    }

    public function test_a_document_root_narrower_than_0755_is_left_exactly_as_it_is(): void
    {
        // 0755 is a ceiling, not a target: a host that serves a 0700 document
        // root is stricter than we ask for, and widening it to "harden" it would
        // be the bug.
        $root = $this->directory('docroot', 0700);

        $outcome = FileModes::narrowDocumentRoot($root);

        $this->assertTrue($outcome['ok']);
        $this->assertSame(0700, $outcome['applied']);
        $this->assertSame(0700, $this->modeOf($root));
    }

    /**
     * The installer calls this before there is a config to protect — the
     * prerequisite step runs against a data directory it has only just created.
     */
    public function test_hardening_skips_a_config_and_a_directory_that_do_not_exist_yet(): void
    {
        $this->directory('storage', 0777);

        $outcomes = FileModes::hardenData($this->root, $this->root . '/config.php', ['storage', 'logs']);

        $this->assertCount(1, $outcomes);
        $this->assertSame($this->root . '/storage', $outcomes[0]['path']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function directory(string $name, int $mode): string
    {
        $path = $this->root . '/' . $name;
        mkdir($path, 0700, true);
        chmod($path, $mode);

        return $path;
    }

    private function configFile(int $mode): string
    {
        $path = $this->root . '/config.php';
        file_put_contents($path, "<?php\n\nreturn ['db' => ['pass' => 'secret']];\n");
        chmod($path, $mode);

        return $path;
    }

    private function modeOf(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0777;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
