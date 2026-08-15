<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Terminals\Repositories;

use App\Modules\Terminals\Enums\TerminalAnomalyKind;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * Open anomalies, against a real database (ADR-0041 §2).
 *
 * "One open row per (terminal, kind)" is enforced by the writer rather than by a
 * unique index, because the natural key — (terminal_id, kind, still-open) —
 * cannot be expressed in MySQL: NULL never equals NULL, so a unique index over
 * the nullable acknowledgement column permits unlimited open rows. That makes
 * the find/open/touch cycle worth checking against the real thing, and the
 * guarded UPDATE in `acknowledge()` — which is what settles two admins clearing
 * the same alert — impossible to check any other way.
 */
class TerminalAnomaliesRepositoryTest extends DatabaseTestCase
{
    private TerminalAnomaliesRepository $repository;
    private string $terminalId;
    private string $otherTerminalId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TerminalAnomaliesRepository($this->db);
        $this->terminalId = $this->createTerminal('Theke 1');
        $this->otherTerminalId = $this->createTerminal('Theke 2');
    }

    protected function tearDown(): void
    {
        foreach ([$this->terminalId, $this->otherTerminalId] as $id) {
            $this->db->prepare('DELETE FROM terminal_anomalies WHERE terminal_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM terminals WHERE id = ?')->execute([$id]);
        }
        parent::tearDown();
    }

    private function createTerminal(string $name): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO terminals (id, name, device_id, is_active) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$id, $name, 'anomaly-' . substr($id, 0, 8)]);

        return $id;
    }

    private function open(string $terminalId, TerminalAnomalyKind $kind, array $details = ['x' => 1]): string
    {
        $id = $this->generateUuid();
        $this->repository->open($id, $terminalId, $kind, $details, strtotime('2026-08-15 18:00:00'));

        return $id;
    }

    public function test_nothing_is_open_to_begin_with(): void
    {
        $this->assertNull($this->repository->findOpen($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP));
    }

    public function test_an_opened_anomaly_is_found_and_carries_its_evidence(): void
    {
        $id = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP, [
            'overlap_seconds' => 1500,
            'ips' => [['ip_address' => '203.0.113.10']],
        ]);

        $open = $this->repository->findOpen($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $this->assertSame($id, $open['id']);
        $this->assertSame(1, $open['occurrence_count']);

        $row = $this->repository->findById($id);
        $details = json_decode((string) $row['details'], true);
        $this->assertSame(1500, $details['overlap_seconds']);
    }

    public function test_the_kinds_are_tracked_apart(): void
    {
        $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $this->assertNotNull($this->repository->findOpen($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP));
        $this->assertNull($this->repository->findOpen($this->terminalId, TerminalAnomalyKind::CURSOR_RESET));
    }

    public function test_the_terminals_are_tracked_apart(): void
    {
        $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $this->assertNull($this->repository->findOpen($this->otherTerminalId, TerminalAnomalyKind::CONCURRENT_IP));
    }

    /**
     * How long this has been going on is the part an admin most wants to know,
     * so the first stamp stays put while the last one moves.
     */
    public function test_touching_advances_the_count_and_the_last_stamp_but_not_the_first(): void
    {
        $id = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $this->repository->touch($id, ['overlap_seconds' => 3600], strtotime('2026-08-15 20:00:00'));

        $row = $this->repository->findById($id);

        $this->assertSame(2, (int) $row['occurrence_count']);
        $this->assertSame('2026-08-15 18:00:00', $row['first_detected_at']);
        $this->assertSame('2026-08-15 20:00:00', $row['last_detected_at']);
        $this->assertSame(3600, json_decode((string) $row['details'], true)['overlap_seconds']);
    }

    public function test_acknowledging_closes_it_and_records_who(): void
    {
        $id = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);
        $adminId = $this->generateUuid();

        $this->assertTrue($this->repository->acknowledge($id, $adminId, strtotime('2026-08-15 21:00:00')));

        $row = $this->repository->findById($id);
        $this->assertSame('2026-08-15 21:00:00', $row['acknowledged_at']);
        $this->assertSame($adminId, $row['acknowledged_by_admin_id']);
        $this->assertNull($this->repository->findOpen($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP));
    }

    /**
     * Two admins clearing the same alert. The guarded UPDATE settles it, and the
     * loser must learn it lost so it does not write a second audit entry
     * claiming it did the acknowledging.
     */
    public function test_only_the_first_acknowledgement_wins(): void
    {
        $id = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $this->assertTrue($this->repository->acknowledge($id, $this->generateUuid(), time()));
        $this->assertFalse($this->repository->acknowledge($id, $this->generateUuid(), time()));
    }

    public function test_acknowledging_something_that_does_not_exist_is_false_not_an_error(): void
    {
        $this->assertFalse($this->repository->acknowledge($this->generateUuid(), $this->generateUuid(), time()));
    }

    /**
     * The same condition recurring after an admin has dealt with it is news
     * again, so a fresh row is what should be opened rather than the old one
     * reawakened.
     */
    public function test_the_condition_recurring_after_acknowledgement_opens_a_fresh_row(): void
    {
        $first = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);
        $this->repository->acknowledge($first, $this->generateUuid(), time());

        $second = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->repository->findOpen($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP)['id']);
    }

    public function test_list_open_joins_the_terminal_name_the_dashboard_message_needs(): void
    {
        $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $mine = array_values(array_filter(
            $this->repository->listOpen(),
            fn(array $row) => $row['terminal_id'] === $this->terminalId,
        ));

        $this->assertCount(1, $mine);
        $this->assertSame('Theke 1', $mine[0]['terminal_name']);
    }

    public function test_list_open_leaves_out_what_has_been_acknowledged(): void
    {
        $id = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);
        $this->repository->acknowledge($id, $this->generateUuid(), time());

        $mine = array_filter(
            $this->repository->listOpen(),
            fn(array $row) => $row['terminal_id'] === $this->terminalId,
        );

        $this->assertSame([], $mine);
    }

    public function test_open_counts_are_grouped_per_terminal(): void
    {
        $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);
        $this->open($this->terminalId, TerminalAnomalyKind::CURSOR_RESET);
        $this->open($this->otherTerminalId, TerminalAnomalyKind::CURSOR_REGRESSION);

        $counts = $this->repository->openCountsByTerminal();

        $this->assertSame(2, $counts[$this->terminalId]);
        $this->assertSame(1, $counts[$this->otherTerminalId]);
    }

    public function test_a_terminal_with_nothing_open_is_absent_from_the_counts(): void
    {
        $this->assertArrayNotHasKey($this->terminalId, $this->repository->openCountsByTerminal());
    }

    public function test_anomalies_go_when_the_terminal_goes(): void
    {
        $id = $this->open($this->terminalId, TerminalAnomalyKind::CONCURRENT_IP);

        $this->db->prepare('DELETE FROM terminals WHERE id = ?')->execute([$this->terminalId]);

        $this->assertNull($this->repository->findById($id));
    }
}
