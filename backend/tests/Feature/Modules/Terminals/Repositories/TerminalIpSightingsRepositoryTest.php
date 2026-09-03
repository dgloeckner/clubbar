<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Terminals\Repositories;

use App\Modules\Terminals\Repositories\TerminalIpSightingsRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * Where a terminal's requests came from, against a real database (ADR-0041 §1).
 *
 * Mocks cannot check any of what matters here. The bucket key, the
 * `ON DUPLICATE KEY UPDATE` that turns 300 requests an hour into 12 rows, the
 * `GREATEST` that stops an out-of-order write shrinking an interval, and the
 * `GROUP BY` that collapses buckets back into one interval per address — all of
 * it is SQL, and all of it is what the detector's overlap arithmetic is fed.
 */
class TerminalIpSightingsRepositoryTest extends DatabaseTestCase
{
    private TerminalIpSightingsRepository $repository;
    private string $terminalId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TerminalIpSightingsRepository($this->db);
        $this->terminalId = $this->createTerminal();
    }

    protected function tearDown(): void
    {
        // The sightings cascade with the terminal, but the delete is explicit so
        // a failure here cannot leave rows behind for the next run to trip over.
        $this->db->prepare('DELETE FROM terminal_ip_sightings WHERE terminal_id = ?')->execute([$this->terminalId]);
        $this->db->prepare('DELETE FROM terminals WHERE id = ?')->execute([$this->terminalId]);
        parent::tearDown();
    }

    private function createTerminal(): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO terminals (id, name, device_id, is_active) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$id, 'Sightings test', 'sightings-' . substr($id, 0, 8)]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM terminal_ip_sightings WHERE terminal_id = ? ORDER BY bucket_start ASC, ip_address ASC'
        );
        $stmt->execute([$this->terminalId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function test_the_bucket_is_the_five_minute_boundary_at_or_before_the_request(): void
    {
        // 12:07:43 belongs to the 12:05 bucket.
        $this->assertSame(
            strtotime('2026-08-15 12:05:00'),
            TerminalIpSightingsRepository::bucketFor(strtotime('2026-08-15 12:07:43')),
        );

        // A request exactly on a boundary stays in its own bucket.
        $this->assertSame(
            strtotime('2026-08-15 12:05:00'),
            TerminalIpSightingsRepository::bucketFor(strtotime('2026-08-15 12:05:00')),
        );
    }

    public function test_a_first_request_creates_one_row(): void
    {
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-08-15 12:07:43'));

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('203.0.113.10', $rows[0]['ip_address']);
        $this->assertSame('2026-08-15 12:05:00', $rows[0]['bucket_start']);
        $this->assertSame(1, (int) $rows[0]['request_count']);
        $this->assertSame('2026-08-15 12:07:43', $rows[0]['first_seen_at']);
        $this->assertSame('2026-08-15 12:07:43', $rows[0]['last_seen_at']);
    }

    /**
     * The whole reason for bucketing: a terminal issues ~300 authenticated
     * requests an hour, and this must not be 300 rows.
     */
    public function test_requests_in_one_bucket_collapse_into_a_single_row(): void
    {
        foreach (['12:05:01', '12:06:00', '12:07:43', '12:09:59'] as $time) {
            $this->repository->record($this->terminalId, '203.0.113.10', strtotime("2026-08-15 {$time}"));
        }

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(4, (int) $rows[0]['request_count']);
        $this->assertSame('2026-08-15 12:05:01', $rows[0]['first_seen_at'], 'the lower edge must not move');
        $this->assertSame('2026-08-15 12:09:59', $rows[0]['last_seen_at']);
    }

    public function test_a_new_bucket_starts_a_new_row(): void
    {
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-08-15 12:07:00'));
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-08-15 12:12:00'));

        $this->assertCount(2, $this->rows());
    }

    public function test_two_addresses_in_one_bucket_are_two_rows(): void
    {
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-08-15 12:07:00'));
        $this->repository->record($this->terminalId, '198.51.100.7', strtotime('2026-08-15 12:07:00'));

        $this->assertCount(2, $this->rows());
    }

    /**
     * A request that reaches the database out of order must not shrink an
     * interval that was already observed — the overlap arithmetic reads these
     * edges directly.
     */
    public function test_an_out_of_order_write_does_not_pull_the_last_seen_edge_backwards(): void
    {
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-08-15 12:09:00'));
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-08-15 12:06:00'));

        $rows = $this->rows();

        $this->assertSame('2026-08-15 12:09:00', $rows[0]['last_seen_at']);
        $this->assertSame(2, (int) $rows[0]['request_count']);
    }

    public function test_intervals_collapse_across_buckets_into_one_row_per_address(): void
    {
        foreach (['11:36:00', '11:42:00', '12:21:00'] as $time) {
            $this->repository->record($this->terminalId, '203.0.113.10', strtotime("2026-08-15 {$time}"));
        }
        $this->repository->record($this->terminalId, '198.51.100.7', strtotime('2026-08-15 11:56:00'));

        $intervals = array_values(array_filter(
            $this->repository->activeIntervalsSince('2026-08-15 11:00:00'),
            fn(array $row) => $row['terminal_id'] === $this->terminalId,
        ));

        $this->assertCount(2, $intervals, 'one row per address, not per bucket');

        $byIp = [];
        foreach ($intervals as $interval) {
            $byIp[$interval['ip_address']] = $interval;
        }

        // Labelled UTC on the way out, like every other instant the API emits:
        // a bare datetime is read as local time by whatever parses it (#365).
        $this->assertSame('2026-08-15T11:36:00Z', $byIp['203.0.113.10']['first_seen_at']);
        $this->assertSame('2026-08-15T12:21:00Z', $byIp['203.0.113.10']['last_seen_at']);
        $this->assertSame(3, $byIp['203.0.113.10']['request_count']);
        $this->assertSame(1, $byIp['198.51.100.7']['request_count']);
    }

    public function test_intervals_outside_the_window_are_not_returned(): void
    {
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-08-15 09:00:00'));

        $intervals = array_filter(
            $this->repository->activeIntervalsSince('2026-08-15 11:00:00'),
            fn(array $row) => $row['terminal_id'] === $this->terminalId,
        );

        $this->assertSame([], $intervals);
    }

    public function test_pruning_removes_only_what_is_older_than_the_cutoff(): void
    {
        $this->repository->record($this->terminalId, '203.0.113.10', strtotime('2026-07-01 12:00:00'));
        $this->repository->record($this->terminalId, '203.0.113.11', strtotime('2026-08-15 12:00:00'));

        $removed = $this->repository->pruneOlderThan('2026-08-01 00:00:00');

        $this->assertSame(1, $removed);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('203.0.113.11', $rows[0]['ip_address']);
    }

    /**
     * Sightings are about a credential, so they have no reason to outlive the
     * terminal they describe — and a source IP is personal data (ADR-0041 §5).
     */
    public function test_sightings_go_when_the_terminal_goes(): void
    {
        $this->repository->record($this->terminalId, '203.0.113.10', time());

        $this->db->prepare('DELETE FROM terminals WHERE id = ?')->execute([$this->terminalId]);

        $this->assertSame([], $this->rows());
    }
}
