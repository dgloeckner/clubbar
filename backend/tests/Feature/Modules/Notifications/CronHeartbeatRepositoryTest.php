<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * The singleton every drain run stamps, against the real schema.
 *
 * The one thing here that cannot be tested anywhere else is the *ordering* of
 * the `ON DUPLICATE KEY UPDATE` assignments: `previous_run_at = last_run_at`
 * has to be evaluated before `last_run_at` is overwritten, and whether it is
 * depends on MariaDB rather than on this code. The gap between the two columns
 * is the observed cron interval (ADR-0039 decision 5) — the only thing that
 * catches a crontab that says hourly and fires daily — so if the order silently
 * changed, the cross-check would compare a run against itself and always agree.
 */
class CronHeartbeatRepositoryTest extends DatabaseTestCase
{
    private CronHeartbeatRepository $repository;
    private ?array $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new CronHeartbeatRepository($this->db);
        // One row for the whole installation, shared with every other suite —
        // snapshot and restore rather than create (Pattern 001's singleton form).
        $this->original = $this->repository->get();
    }

    protected function tearDown(): void
    {
        if ($this->original !== null) {
            $this->db->prepare(
                'UPDATE cron_heartbeat
                    SET last_run_at = ?, previous_run_at = ?, source = ?, sent = ?, failed = ?,
                        php_version = ?, missing_extensions = ?
                  WHERE id = 1'
            )->execute([
                $this->original['last_run_at'],
                $this->original['previous_run_at'],
                $this->original['source'],
                $this->original['sent'],
                $this->original['failed'],
                $this->original['php_version'],
                $this->original['missing_extensions'],
            ]);
        }

        parent::tearDown();
    }

    public function test_a_run_moves_the_previous_one_aside_instead_of_losing_it(): void
    {
        $this->db->exec("UPDATE cron_heartbeat SET last_run_at = '2026-08-15 10:00:00', previous_run_at = NULL WHERE id = 1");

        $this->repository->record(DrainSource::CLI, 3, 0, '8.3.0', '');

        $row = $this->repository->get();
        $this->assertSame('2026-08-15 10:00:00', $row['previous_run_at']);
        $this->assertNotSame('2026-08-15 10:00:00', $row['last_run_at'], 'the new run is now the last one');
        $this->assertSame(3, (int) $row['sent']);
    }

    public function test_the_first_ever_run_has_no_previous_one(): void
    {
        $this->db->exec('UPDATE cron_heartbeat SET last_run_at = NULL, previous_run_at = NULL WHERE id = 1');

        $this->repository->record(DrainSource::URL, 0, 0, '8.3.0', '');

        $row = $this->repository->get();
        $this->assertNull($row['previous_run_at'], 'nothing to compare against yet, and that is not a gap of zero');
        $this->assertNotNull($row['last_run_at']);
    }

    /**
     * Two consecutive runs leave a readable gap. This is what the self-check
     * turns into "declared hourly, observed daily".
     */
    public function test_two_runs_leave_a_measurable_gap(): void
    {
        $this->repository->record(DrainSource::CLI, 0, 0, '8.3.0', '');
        $this->db->exec('UPDATE cron_heartbeat SET last_run_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = 1');
        $this->repository->record(DrainSource::CLI, 0, 0, '8.3.0', '');

        $row = $this->repository->get();
        $gap = strtotime((string) $row['last_run_at']) - strtotime((string) $row['previous_run_at']);

        $this->assertGreaterThan(23 * 3600, $gap);
    }
}
