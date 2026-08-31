<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Repositories;

use App\Modules\Registrations\Repositories\RegistrationAttemptsRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/** Hand-maintained copy of migration `059`'s `registration_attempts`. */
final class RegistrationAttemptsRepositoryTest extends TestCase
{
    private PDO $db;
    private RegistrationAttemptsRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE registration_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                outcome VARCHAR(10) NOT NULL,
                attempted_at DATETIME NOT NULL
            )'
        );

        $this->repository = new RegistrationAttemptsRepository($this->db);
    }

    private function seed(string $ip, string $outcome, string $at): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO registration_attempts (ip_address, outcome, attempted_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$ip, $outcome, $at]);
    }

    public function test_it_counts_only_the_outcome_asked_for(): void
    {
        $this->seed('10.0.0.1', 'refused', '2026-08-31 10:00:00');
        $this->seed('10.0.0.1', 'refused', '2026-08-31 10:01:00');
        $this->seed('10.0.0.1', 'accepted', '2026-08-31 10:02:00');

        self::assertSame(2, $this->repository->countRecent('10.0.0.1', 'refused', '2026-08-31 09:00:00'));
        self::assertSame(1, $this->repository->countRecent('10.0.0.1', 'accepted', '2026-08-31 09:00:00'));
    }

    /**
     * The two meters exist because the login surface's failure count is the
     * wrong meter on its own here: somebody holding the real poster secret
     * never fails, and can still fill the treasurer's queue.
     */
    public function test_accepted_submissions_are_metered_too(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->record('10.0.0.2', 'accepted');
        }

        self::assertSame(5, $this->repository->countRecent('10.0.0.2', 'accepted', '2000-01-01 00:00:00'));
    }

    public function test_it_counts_only_within_the_window_and_only_that_ip(): void
    {
        $this->seed('10.0.0.1', 'refused', '2026-08-31 08:00:00');
        $this->seed('10.0.0.1', 'refused', '2026-08-31 10:00:00');
        $this->seed('10.0.0.9', 'refused', '2026-08-31 10:00:00');

        self::assertSame(1, $this->repository->countRecent('10.0.0.1', 'refused', '2026-08-31 09:00:00'));
    }

    public function test_pruning_drops_rows_older_than_the_cutoff(): void
    {
        $this->seed('10.0.0.1', 'refused', '2026-08-30 10:00:00');
        $this->seed('10.0.0.1', 'refused', '2026-08-31 10:00:00');

        self::assertSame(1, $this->repository->pruneOlderThan('2026-08-31 00:00:00'));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM registration_attempts')->fetchColumn());
    }
}
