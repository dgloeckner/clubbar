<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\ConfigSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The format that carries `config.php` inside the archive.
 *
 * Two properties matter and both are easy to lose by accident: the block is
 * **comments**, so a whole-file import stays safe, and it round-trips
 * **byte for byte**, because what it carries are keys — a snapshot that is
 * nearly right is a restore that nearly works.
 *
 * Part of #692, epic #686.
 */
class ConfigSnapshotTest extends TestCase
{
    use TempTree;

    /** Assigned before anything that could skip — CLAUDE.md, destructive test cleanup. */
    private string $tempTree = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempTree = self::makeTempTree('clubbar-config-snapshot');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->tempTree);
        parent::tearDown();
    }

    /**
     * The whole point: what goes in comes back out unchanged.
     *
     * The fixture is deliberately hostile. It contains the close marker as
     * literal text, which is the case base64 exists to defend against — a club
     * whose `config.php` carries a comment about the backup format must not be
     * able to truncate its own snapshot.
     */
    public function test_a_config_file_round_trips_byte_for_byte(): void
    {
        $original = <<<'PHP'
        <?php
        // A comment mentioning -- <<< CONFIG on purpose.
        return [
            'security' => ['totp_encryption_key' => "b\x00inary\xfe\xff", 'iban_fingerprint_key' => 'ümlaut'],
            'db' => ['password' => "line\nbreak; and a -- comment"],
        ];
        PHP;

        $path = $this->tempTree . '/config.php';
        file_put_contents($path, $original);

        $block = (new ConfigSnapshot($path))->render();

        $this->assertSame($original, ConfigSnapshot::extract("-- dump\n" . $block));
    }

    /**
     * Every line is a comment, so importing the dump whole cannot execute it.
     *
     * This is what buys the single-file restore path: the operator pastes one
     * `.sql` into phpMyAdmin and the configuration rides along harmlessly.
     */
    public function test_every_line_of_the_block_is_a_sql_comment(): void
    {
        $path = $this->tempTree . '/config.php';
        file_put_contents($path, "<?php\nreturn ['db' => ['password' => 'DROP TABLE members;']];\n");

        $block = (new ConfigSnapshot($path))->render();

        foreach (explode("\n", trim($block, "\n")) as $line) {
            $this->assertStringStartsWith(
                '--',
                $line,
                'A line of the config block is not a comment, so importing the dump would execute it.'
            );
        }
    }

    /**
     * No config is not an error.
     *
     * A backup that refused to run because `config.php` was outside the
     * process's reach would be a backup that stopped happening — strictly worse
     * than one that carries the database and says so. The header's
     * `config_included` is how a reader tells the two apart.
     */
    public function test_an_absent_or_unreadable_config_carries_nothing_and_does_not_throw(): void
    {
        foreach ([null, '', $this->tempTree . '/not-here.php', $this->tempTree] as $path) {
            $snapshot = new ConfigSnapshot($path);

            $this->assertFalse($snapshot->isAvailable(), var_export($path, true));
            $this->assertSame('', $snapshot->render(), var_export($path, true));
        }
    }

    /** A dump with no block reads as "there is none", not as an empty config. */
    public function test_a_dump_without_a_block_extracts_to_null(): void
    {
        $this->assertNull(ConfigSnapshot::extract("-- Club Bar database dump\nSET NAMES utf8mb4;\n"));
    }
}
