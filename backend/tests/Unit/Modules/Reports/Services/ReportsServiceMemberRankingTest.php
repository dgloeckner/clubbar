<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reports\Services;

use App\Modules\Reports\Services\ReportsService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The member ranking is anonymous, and there is no way to ask it otherwise
 * (#177). A named leaderboard of who drinks most is the consumption profile
 * ADR-0029 prohibits, so the named mode was removed rather than gated.
 *
 * The unfiltered ranking query is portable SQL, so an in-memory SQLite database
 * exercises the real statement without the Docker MariaDB instance. The date
 * filters use MariaDB's DATE_ADD and are covered by the API tests instead.
 */
class ReportsServiceMemberRankingTest extends TestCase
{
    private PDO $db;
    private ReportsService $reportsService;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE members (
                id CHAR(36) NOT NULL PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE transactions (
                id CHAR(36) NOT NULL PRIMARY KEY,
                member_id CHAR(36) NOT NULL,
                amount_cents INT NOT NULL,
                transaction_type VARCHAR(20) NOT NULL,
                occurred_at TIMESTAMP NOT NULL
            )'
        );

        $this->reportsService = new ReportsService($this->db);
    }

    private function addMember(string $id, string $firstName, string $lastName): void
    {
        $this->db->prepare('INSERT INTO members (id, first_name, last_name) VALUES (?, ?, ?)')
            ->execute([$id, $firstName, $lastName]);
    }

    private function addPurchase(string $id, string $memberId, int $amountCents): void
    {
        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, amount_cents, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $memberId, $amountCents, 'purchase', '2026-01-05 18:00:00']);
    }

    public function test_it_labels_rows_by_rank_and_never_by_name(): void
    {
        $this->addMember('m-1', 'Anna', 'Meier');
        $this->addMember('m-2', 'Ben', 'Schulz');
        $this->addPurchase('t-1', 'm-1', 5000);
        $this->addPurchase('t-2', 'm-2', 1000);

        $ranking = $this->reportsService->getMemberRanking(null, null);

        $this->assertSame(
            [
                ['rank' => 1, 'member_name' => 'Member 1', 'total_amount_cents' => 5000, 'transaction_count' => 1],
                ['rank' => 2, 'member_name' => 'Member 2', 'total_amount_cents' => 1000, 'transaction_count' => 1],
            ],
            $ranking['data']
        );

        $serialized = json_encode($ranking);
        foreach (['Anna', 'Meier', 'Ben', 'Schulz', 'm-1', 'm-2'] as $identifier) {
            $this->assertStringNotContainsString($identifier, (string) $serialized);
        }
    }

    /**
     * The label is the ordinal position in *this* report, not a persistent
     * alias — otherwise anyone who identified one row could carry that name
     * into every other ranking. Here the same "Member 1" is a different person
     * once the totals change.
     */
    public function test_the_same_label_means_a_different_member_in_a_later_report(): void
    {
        $this->addMember('m-1', 'Anna', 'Meier');
        $this->addMember('m-2', 'Ben', 'Schulz');
        $this->addPurchase('t-1', 'm-1', 5000);
        $this->addPurchase('t-2', 'm-2', 1000);

        $first = $this->reportsService->getMemberRanking(null, null);
        $this->assertSame(5000, $first['data'][0]['total_amount_cents']);

        // Ben overtakes Anna, so rank 1 is now a different member.
        $this->addPurchase('t-3', 'm-2', 9000);

        $second = $this->reportsService->getMemberRanking(null, null);
        $this->assertSame('Member 1', $second['data'][0]['member_name']);
        $this->assertSame(10000, $second['data'][0]['total_amount_cents']);
    }

    public function test_it_counts_only_purchases(): void
    {
        $this->addMember('m-1', 'Anna', 'Meier');
        $this->addPurchase('t-1', 'm-1', 5000);
        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, amount_cents, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(['t-2', 'm-1', -5000, 'correction', '2026-01-06 18:00:00']);

        $ranking = $this->reportsService->getMemberRanking(null, null);

        $this->assertSame(5000, $ranking['data'][0]['total_amount_cents']);
        $this->assertSame(1, $ranking['data'][0]['transaction_count']);
    }

    public function test_it_clamps_the_limit_to_the_supported_range(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->addMember("m-{$i}", "First{$i}", "Last{$i}");
            $this->addPurchase("t-{$i}", "m-{$i}", $i * 100);
        }

        $this->assertCount(1, $this->reportsService->getMemberRanking(null, null, 0)['data']);
        $this->assertCount(3, $this->reportsService->getMemberRanking(null, null, 1000)['data']);
    }

    public function test_it_returns_an_empty_ranking_when_nobody_bought_anything(): void
    {
        $this->assertSame([], $this->reportsService->getMemberRanking(null, null)['data']);
    }
}
