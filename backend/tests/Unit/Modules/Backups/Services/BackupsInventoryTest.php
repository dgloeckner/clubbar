<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\BackupsInventory;
use App\Shared\Security\BackupSealedBox;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The list a club walks its key register against (#693, ADR-0049).
 *
 * #703 removed the application's key register along with the tracking tables:
 * custody lives on paper, where a restore cannot rewrite it. What went with
 * them was the answer to a question a treasurer eventually asks — *"which of
 * these private keys may we finally destroy?"*
 *
 * That answer needs no register, because every archive names the keys that open
 * it in a header readable **with no key at all**. This derives it.
 *
 * Part of #693, epic #686.
 */
class BackupsInventoryTest extends TestCase
{
    use TempTree;

    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('libsodium is required to seal a fixture archive');
        }

        // Assigned before any skip can happen (CLAUDE.md's destructive-cleanup
        // rule): tearDown runs for skipped tests too, and an unset path here
        // would hand a delete loop the filesystem root.
        $this->dir = self::makeTempTree('backups-inventory');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    public function test_an_empty_directory_holds_nothing_and_names_no_keys(): void
    {
        $inventory = new BackupsInventory($this->dir);

        $this->assertSame([], $inventory->archives());
        $this->assertSame([], $inventory->keys());
    }

    /**
     * The header is readable without a private key — that property is what the
     * whole page rests on, so it is asserted here rather than assumed from the
     * container's own tests.
     */
    public function test_it_reads_what_each_archive_says_about_itself(): void
    {
        $this->seal('clubbar-20260825-030000-1a2b3c4d.cbb', [['label' => 'vorstand', 'key' => $this->key()]]);

        $archives = (new BackupsInventory($this->dir))->archives();

        $this->assertCount(1, $archives);
        $this->assertTrue($archives[0]['readable']);
        $this->assertSame('clubbar-20260825-030000-1a2b3c4d.cbb', $archives[0]['name']);
        $this->assertSame(['vorstand'], array_column($archives[0]['recipients'], 'label'));
        $this->assertNotNull($archives[0]['created_at']);
    }

    /**
     * **Aggregated by fingerprint, never by label.** A label is what somebody
     * typed into `config.php` and can be reused or reassigned; the fingerprint
     * is the key. Two envelopes both marked `vorstand` from different years are
     * two keys, and merging them would tell a club it was safe to destroy one.
     */
    public function test_two_keys_sharing_a_label_are_two_keys(): void
    {
        $first = $this->key();
        $second = $this->key();

        $this->seal('clubbar-20260825-030000-aaaaaaaa.cbb', [['label' => 'vorstand', 'key' => $first]]);
        $this->seal('clubbar-20260826-030000-bbbbbbbb.cbb', [['label' => 'vorstand', 'key' => $second]]);

        $keys = (new BackupsInventory($this->dir))->keys();

        $this->assertCount(2, $keys, 'the same word on two envelopes is not the same key');
        $this->assertSame(['vorstand', 'vorstand'], array_column($keys, 'label'));
        $this->assertCount(2, array_unique(array_column($keys, 'fingerprint')));
    }

    /**
     * The span is what makes the list actionable: a key whose newest archive
     * has aged out opens nothing this installation still holds, which is the
     * fact somebody needs before shredding a paper envelope.
     */
    public function test_a_key_carries_the_span_of_archives_it_still_opens(): void
    {
        $key = $this->key();

        $this->seal('clubbar-20260825-030000-aaaaaaaa.cbb', [['label' => 'vorstand', 'key' => $key]]);
        $this->seal('clubbar-20260827-030000-bbbbbbbb.cbb', [['label' => 'vorstand', 'key' => $key]]);

        $keys = (new BackupsInventory($this->dir))->keys();

        $this->assertCount(1, $keys);
        $this->assertSame(2, $keys[0]['archives']);
        $this->assertLessThan(
            $keys[0]['last_seen'],
            $keys[0]['first_seen'],
            'first_seen is the oldest archive this key opens, last_seen the newest'
        );
    }

    /** An archive sealed to several recipients names each of them. */
    public function test_every_recipient_of_an_archive_is_listed(): void
    {
        $this->seal('clubbar-20260825-030000-aaaaaaaa.cbb', [
            ['label' => 'vorstand', 'key' => $this->key()],
            ['label' => 'kassenwart', 'key' => $this->key()],
        ]);

        $keys = (new BackupsInventory($this->dir))->keys();

        $this->assertEqualsCanonicalizing(['vorstand', 'kassenwart'], array_column($keys, 'label'));
    }

    /**
     * **Shown, never hidden.** Omitting an archive whose header will not parse
     * is the worst behaviour available: the one file most worth investigating
     * would silently leave the list, and the club would count backups it does
     * not have.
     */
    public function test_an_unreadable_archive_is_listed_and_marked(): void
    {
        file_put_contents($this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb', 'not a sealed container');

        $archives = (new BackupsInventory($this->dir))->archives();

        $this->assertCount(1, $archives);
        $this->assertFalse($archives[0]['readable']);
        $this->assertSame([], $archives[0]['recipients']);
        // The facts the filesystem knows survive: a club can still see it is
        // there and how big it is.
        $this->assertGreaterThan(0, $archives[0]['bytes']);
    }

    /** A note an operator left in the directory is not an archive. */
    public function test_a_foreign_file_is_not_listed(): void
    {
        file_put_contents($this->dir . '/notes.txt', 'the key is in the safe');

        $this->assertSame([], (new BackupsInventory($this->dir))->archives());
    }

    // ---------------------------------------------------------------- helpers

    private function key(): string
    {
        return sodium_crypto_box_publickey(sodium_crypto_box_keypair());
    }

    /** @param list<array{label: string, key: string}> $recipients */
    private function seal(string $name, array $recipients): void
    {
        $sealed = BackupSealedBox::seal(
            'SELECT 1;',
            array_map(
                static fn (array $r): array => ['label' => $r['label'], 'public_key' => $r['key']],
                $recipients
            ),
            ['instance_id' => 'test', 'instance_name' => 'Test', 'database' => 'clubbar'],
        );

        file_put_contents($this->dir . '/' . $name, $sealed);
    }
}
