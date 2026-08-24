<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Repositories\BackupConfigRepository;
use App\Modules\Backups\Repositories\BackupKeysRepository;
use App\Modules\Backups\Repositories\BackupRunsRepository;
use PDO;
use Tests\Feature\DatabaseTestCase;

/**
 * The two record tables, and the properties that only matter later.
 *
 * Most of what these repositories do is exercised through a real run
 * ({@see BackupServiceTest}). What is tested here is the half that has **no
 * caller yet** and would otherwise reach production untried: the key lifecycle
 * the admin panel drives (#693), and the questions the panel asks of
 * `backup_runs` once an archive is gone.
 *
 * That is deliberate rather than coverage-chasing. `compromised_at` is a
 * blocklist, `verified_at` is what stops the panel calling an unproven keypair
 * a backup, and both are written exactly once, by hand, under stress. A bug in
 * either surfaces at the worst possible moment.
 *
 * Part of #690, epic #686.
 */
class BackupRepositoriesTest extends DatabaseTestCase
{
    private BackupRunsRepository $runs;
    private BackupKeysRepository $keys;

    /** A fingerprint is 64 hex characters; these are not real keys. */
    private string $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runs = new BackupRunsRepository($this->db);
        $this->keys = new BackupKeysRepository($this->db);
        $this->fingerprint = hash('sha256', $this->generateUuid());

        $this->db->exec('DELETE FROM backup_runs');
        $this->db->exec('DELETE FROM backup_keys');
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM backup_runs');
        $this->db->exec('DELETE FROM backup_keys');

        parent::tearDown();
    }

    public function test_a_failed_run_records_why_rather_than_staying_running_forever(): void
    {
        $id = $this->generateUuid();
        $this->runs->start($id, 'cli', gmdate('Y-m-d H:i:s'));

        $this->runs->markFailed($id, 'the disk is full', gmdate('Y-m-d H:i:s'));

        $row = $this->row($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('the disk is full', $row['last_error']);
        $this->assertNotNull(
            $row['finished_at'],
            'A row left reading "running" would be reported as a stalled scheduler rather '
            . 'than as the failure it was.'
        );
    }

    /**
     * `last_error` is truncated to the column rather than allowed to overflow
     * and drop the UPDATE. What matters is that the first line survives; the
     * full exception is in the application log with a stack trace.
     */
    public function test_an_enormous_error_is_truncated_rather_than_lost(): void
    {
        $id = $this->generateUuid();
        $this->runs->start($id, 'cli', gmdate('Y-m-d H:i:s'));

        $this->runs->markFailed($id, 'BOOM ' . str_repeat('x', 5000), gmdate('Y-m-d H:i:s'));

        $stored = (string) $this->row($id)['last_error'];
        $this->assertSame(1000, mb_strlen($stored));
        $this->assertStringStartsWith('BOOM ', $stored);
    }

    /**
     * The property the whole table exists for: after the artifact is gone, the
     * row still says which private keys open it. A rotation retires a key by
     * waiting for retention to drain, and discarding the retired private half
     * before then destroys the still-existing archives silently.
     */
    public function test_a_pruned_run_still_names_the_keys_that_opened_it(): void
    {
        $id = $this->generateUuid();
        $this->runs->start($id, 'cli', gmdate('Y-m-d H:i:s'));
        $this->runs->markLocal(
            $id,
            'archive.cbb',
            1234,
            str_repeat('a', 64),
            [$this->fingerprint],
            ['members' => 7],
            gmdate('Y-m-d H:i:s'),
        );

        $this->runs->markPruned($id, gmdate('Y-m-d H:i:s'));

        $row = $this->row($id);
        $this->assertNotNull($row['pruned_at']);
        $this->assertSame([$this->fingerprint], json_decode((string) $row['key_fingerprints'], true));
        $this->assertSame(
            [],
            $this->runs->unprunedArchives(),
            'A pruned archive is no longer on disk, so pruning must not offer it again.'
        );
    }

    /**
     * Keyed on attempts rather than successes, because the resource the
     * interval protects — quota, and CPU on a shared tariff — is spent by an
     * attempt. Keying on success would let a failing run be triggered in a loop.
     */
    public function test_the_interval_clock_counts_a_failed_run_too(): void
    {
        $id = $this->generateUuid();
        $startedAt = gmdate('Y-m-d H:i:s');
        $this->runs->start($id, 'url', $startedAt);
        $this->runs->markFailed($id, 'nope', $startedAt);

        $this->assertSame($startedAt, $this->runs->lastStartedAt());
        $this->assertNull($this->runs->lastSuccessful(), 'A failed run produced no archive.');
    }

    public function test_recent_returns_the_newest_first(): void
    {
        $older = $this->generateUuid();
        $newer = $this->generateUuid();
        $this->runs->start($older, 'cli', gmdate('Y-m-d H:i:s', time() - 3600));
        $this->runs->start($newer, 'cli', gmdate('Y-m-d H:i:s'));

        $this->assertSame(
            [$newer, $older],
            array_column($this->runs->recent(10), 'id')
        );
        $this->assertCount(1, $this->runs->recent(1), 'The limit is a bound, not a suggestion.');
    }

    public function test_a_key_is_new_on_first_use_and_not_on_the_second(): void
    {
        $first = gmdate('Y-m-d H:i:s', time() - 60);

        $this->assertTrue($this->keys->recordUse($this->fingerprint, 'admin', $first));
        $this->assertFalse($this->keys->recordUse($this->fingerprint, 'admin', gmdate('Y-m-d H:i:s')));
    }

    /**
     * The label lives in `config.php`, so renaming a recipient there should
     * change what the decryptor prints — not leave the panel showing a name
     * nobody uses any more.
     */
    public function test_a_renamed_recipient_keeps_its_first_seen_date(): void
    {
        $first = gmdate('Y-m-d H:i:s', time() - 86400);
        $this->keys->recordUse($this->fingerprint, 'admin', $first);

        $this->keys->recordUse($this->fingerprint, 'vorstand', gmdate('Y-m-d H:i:s'));

        $row = $this->keys->all()[0];
        $this->assertSame('vorstand', $row['label']);
        $this->assertSame($first, $row['first_seen_at']);
    }

    public function test_verifying_an_unknown_fingerprint_reports_that_it_changed_nothing(): void
    {
        $this->assertFalse(
            $this->keys->markVerified($this->fingerprint, gmdate('Y-m-d H:i:s')),
            'Verifying a key no archive was ever sealed to is a mistake worth surfacing, '
            . 'not a silent no-op.'
        );

        $this->keys->recordUse($this->fingerprint, 'admin', gmdate('Y-m-d H:i:s'));
        $this->assertTrue($this->keys->markVerified($this->fingerprint, gmdate('Y-m-d H:i:s')));
        $this->assertNotNull($this->keys->all()[0]['verified_at']);
    }

    /**
     * The compromise date bounds which archives are affected, so a second click
     * must not move it forward and shrink the set somebody has to purge.
     */
    public function test_marking_a_key_compromised_twice_keeps_the_first_date(): void
    {
        $first = gmdate('Y-m-d H:i:s', time() - 86400);

        $this->keys->markCompromised($this->fingerprint, 'admin', $first);
        $this->keys->markCompromised($this->fingerprint, 'admin', gmdate('Y-m-d H:i:s'));

        $this->assertSame($first, $this->keys->all()[0]['compromised_at']);
        $this->assertSame([$this->fingerprint], $this->keys->compromisedFingerprints());
    }

    /** A key can be blocklisted before it has ever sealed anything. */
    public function test_a_key_can_be_marked_compromised_without_a_prior_row(): void
    {
        $this->keys->markCompromised($this->fingerprint, 'stolen-laptop', gmdate('Y-m-d H:i:s'));

        $this->assertSame([$this->fingerprint], $this->keys->compromisedFingerprints());
    }

    /**
     * An installation whose singleton row somebody deleted must degrade to the
     * shipped policy rather than to a fatal in a scheduled job nobody watches.
     */
    public function test_the_config_falls_back_to_shipped_defaults_when_the_row_is_missing(): void
    {
        $saved = (new BackupConfigRepository($this->db))->get();
        $this->db->exec('DELETE FROM backup_config WHERE id = 1');

        try {
            $settings = (new BackupConfigRepository($this->db))->get();

            $this->assertSame(30, (int) $settings['local_retention_days']);
            $this->assertSame(0, (int) $settings['enabled'], 'Absent config must not start backing up.');
        } finally {
            $this->db->prepare(
                'INSERT INTO backup_config (id, enabled, cadence, local_retention_days,
                                            local_max_bytes, remote_retention_days, budget_seconds)
                 VALUES (1, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $saved['enabled'], $saved['cadence'], $saved['local_retention_days'],
                $saved['local_max_bytes'], $saved['remote_retention_days'], $saved['budget_seconds'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM backup_runs WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
