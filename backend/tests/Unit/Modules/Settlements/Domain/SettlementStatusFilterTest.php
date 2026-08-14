<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Domain;

use App\Modules\Settlements\Domain\SettlementStatusFilter;
use App\Modules\Settlements\Enums\SettlementStatus;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The filter and the badge never disagree about a settlement (#377).
 *
 * The settlements list derives each row's badge in PHP, through
 * {@see SettlementStatus::derive()}, and selects the rows for a pill in SQL,
 * through {@see SettlementStatusFilter::sql()}. Those are two expressions of
 * one ladder, and two expressions of one rule drift — the failure being a
 * settlement that the "Submitted" pill returns while its own badge reads
 * "Partly reversed", so the treasurer believes money is with the bank that has
 * already come back.
 *
 * So this test does not assert what either side answers. It runs both over the
 * same row shapes and asserts they agree, which is the only property that
 * matters and the one nobody would notice breaking.
 *
 * The SQL runs against an in-memory SQLite database rather than a mock: a
 * hand-rolled evaluator of the fragment would be a *third* expression of the
 * ladder, and the point here is to have fewer, not more. The predicates use no
 * dialect-specific syntax, and the same fragments are exercised against MariaDB
 * by the API tests in `e2etests/tests/api/settlements.spec.ts`.
 */
class SettlementStatusFilterTest extends TestCase
{
    private PDO $db;

    /**
     * Every row shape the ladder distinguishes, plus the ones that look like
     * they belong on a rung they do not.
     *
     * @var array<string, array{settlement: array<string, mixed>, reversed: int, settled: int}>
     */
    private const SHAPES = [
        'nothing recorded' => [
            'settlement' => [],
            'reversed' => 0,
            'settled' => 3,
        ],
        'a file was generated' => [
            'settlement' => ['exported_at' => '2026-08-07 09:00:00'],
            'reversed' => 0,
            'settled' => 3,
        ],
        'the file reached the bank' => [
            'settlement' => ['exported_at' => '2026-08-07 09:00:00', 'submitted_at' => '2026-08-07 11:00:00'],
            'reversed' => 0,
            'settled' => 3,
        ],
        'the bank returned one of three members' => [
            'settlement' => ['exported_at' => '2026-08-07 09:00:00', 'submitted_at' => '2026-08-07 11:00:00'],
            'reversed' => 1,
            'settled' => 3,
        ],
        'the bank returned every member' => [
            'settlement' => ['exported_at' => '2026-08-07 09:00:00', 'submitted_at' => '2026-08-07 11:00:00'],
            'reversed' => 3,
            'settled' => 3,
        ],
        'the run was called off' => [
            'settlement' => ['is_cancelled' => 1],
            'reversed' => 0,
            'settled' => 3,
        ],
        'called off, and carrying reversals' => [
            'settlement' => [
                'is_cancelled' => 1,
                'exported_at' => '2026-08-07 09:00:00',
                'submitted_at' => '2026-08-07 11:00:00',
            ],
            'reversed' => 2,
            'settled' => 2,
        ],
        // Nothing to be "fully" reversed against — a zero member count is
        // missing, not satisfied.
        'reversals against a run covering no members' => [
            'settlement' => ['submitted_at' => '2026-08-07 11:00:00'],
            'reversed' => 1,
            'settled' => 0,
        ],
        // A reversal outranks `exported` as surely as it outranks `submitted`.
        'exported, never submitted, one of two returned' => [
            'settlement' => ['exported_at' => '2026-08-07 09:00:00'],
            'reversed' => 1,
            'settled' => 2,
        ],
        'covers a single member, entirely reversed' => [
            'settlement' => ['exported_at' => '2026-08-07 09:00:00', 'submitted_at' => '2026-08-07 11:00:00'],
            'reversed' => 1,
            'settled' => 1,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE settlements (
                id TEXT PRIMARY KEY,
                is_cancelled INTEGER NOT NULL DEFAULT 0,
                exported_at TEXT NULL,
                submitted_at TEXT NULL
            )'
        );
        $this->db->exec('CREATE TABLE settlement_reversals (settlement_id TEXT, member_id TEXT)');
        $this->db->exec('CREATE TABLE settlement_items (settlement_id TEXT, member_id TEXT)');

        foreach (self::SHAPES as $name => $shape) {
            $this->insertShape($name, $shape);
        }
    }

    public function test_every_pill_returns_exactly_the_rows_whose_badge_says_so(): void
    {
        foreach (SettlementStatus::cases() as $status) {
            $this->assertSame(
                $this->shapesDerivingTo($status),
                $this->shapesSelectedBy($status->value),
                "The `{$status->value}` filter and the derived badge disagree",
            );
        }
    }

    public function test_the_reversed_pill_asks_for_both_reversal_rungs_at_once(): void
    {
        // A treasurer looking for "money came back" does not yet care how much
        // of it did; the badge still shows the two apart.
        $expected = array_values(array_unique(array_merge(
            $this->shapesDerivingTo(SettlementStatus::PARTLY_REVERSED),
            $this->shapesDerivingTo(SettlementStatus::FULLY_REVERSED),
        )));
        sort($expected);

        $this->assertSame($expected, $this->shapesSelectedBy(SettlementStatusFilter::REVERSED));
    }

    public function test_the_pills_partition_the_unfiltered_list(): void
    {
        $seen = [];
        foreach (SettlementStatusFilter::PILLS as $pill) {
            foreach ($this->shapesSelectedBy($pill) as $name) {
                $this->assertArrayNotHasKey(
                    $name,
                    $seen,
                    sprintf('"%s" is returned by both the `%s` and `%s` pills', $name, $seen[$name] ?? '', $pill),
                );
                $seen[$name] = $pill;
            }
        }

        $missed = array_diff(array_keys(self::SHAPES), array_keys($seen));
        $this->assertSame([], array_values($missed), 'No pill returns these settlements at all');
    }

    public function test_the_deprecated_active_alias_still_means_not_cancelled(): void
    {
        // Bookmarked URLs and the pre-#377 suite still send it, and there is no
        // reason to break them just because the pill row moved on.
        $expected = array_keys(array_filter(
            self::SHAPES,
            static fn(array $shape): bool => empty($shape['settlement']['is_cancelled']),
        ));
        sort($expected);

        $this->assertSame($expected, $this->shapesSelectedBy(SettlementStatusFilter::ACTIVE));
    }

    public function test_all_and_unknown_values_filter_nothing(): void
    {
        foreach ([null, '', 'all', 'not-a-status'] as $value) {
            $this->assertNull(
                SettlementStatusFilter::sql($value),
                sprintf('%s should not narrow the list', var_export($value, true)),
            );
        }
    }

    /**
     * The fragment is spliced into the WHERE clause unquoted, so it must never
     * carry anything the caller supplied.
     */
    public function test_an_injected_status_produces_no_fragment(): void
    {
        $this->assertNull(SettlementStatusFilter::sql("draft' OR '1'='1"));
    }

    /** @return list<string> The shape names, sorted. */
    private function shapesDerivingTo(SettlementStatus $status): array
    {
        $names = [];
        foreach (self::SHAPES as $name => $shape) {
            if (SettlementStatus::derive($shape['settlement'], $shape['reversed'], $shape['settled']) === $status) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /** @return list<string> The shape names the filter's SQL selects, sorted. */
    private function shapesSelectedBy(string $filterValue): array
    {
        $fragment = SettlementStatusFilter::sql($filterValue);
        $this->assertNotNull($fragment, "`{$filterValue}` should narrow the list");

        $names = $this->db
            ->query("SELECT s.id FROM settlements s WHERE {$fragment}")
            ->fetchAll(PDO::FETCH_COLUMN);
        sort($names);

        return $names;
    }

    /** @param array{settlement: array<string, mixed>, reversed: int, settled: int} $shape */
    private function insertShape(string $name, array $shape): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, is_cancelled, exported_at, submitted_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            (int) ($shape['settlement']['is_cancelled'] ?? 0),
            $shape['settlement']['exported_at'] ?? null,
            $shape['settlement']['submitted_at'] ?? null,
        ]);

        // The counts are what the ladder turns on, so they are built out of
        // real rows rather than stubbed: `settled` distinct members hold an
        // item, and the first `reversed` of them also hold a reversal.
        for ($i = 0; $i < $shape['settled']; $i++) {
            $this->db->prepare('INSERT INTO settlement_items (settlement_id, member_id) VALUES (?, ?)')
                ->execute([$name, "member-{$i}"]);
        }
        for ($i = 0; $i < $shape['reversed']; $i++) {
            $this->db->prepare('INSERT INTO settlement_reversals (settlement_id, member_id) VALUES (?, ?)')
                ->execute([$name, "member-{$i}"]);
        }
    }
}
