<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Repositories;

use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Logging\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a token leaving *this* hopper (#955, ADR-0058).
 *
 * The estimate's subtrahend is a query, so its rules are SQL rules and are
 * tested as such — against a hand-maintained copy of the three tables it joins.
 * Each test below removes one thing that would otherwise be counted, and each
 * of those was a plausible reading of "tokens sold since the refill".
 */
final class TerminalsRepositoryFillTest extends TestCase
{
    private const BAR = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const CLUB = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const TOKEN_PRODUCT = 'bbbbbbbb-0000-4000-8000-000000000001';
    private const BEER = 'bbbbbbbb-0000-4000-8000-000000000002';

    private PDO $db;
    private TerminalsRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Hand-maintained copies of the columns this query touches, from
        // migrations 001, 007 and 071.
        $this->db->exec(
            'CREATE TABLE terminals (
                id CHAR(36) PRIMARY KEY,
                dispenser_refilled_at DATETIME NULL,
                dispenser_refill_tokens INT NULL,
                dispenser_low_threshold INT NOT NULL DEFAULT 20
            )'
        );
        $this->db->exec(
            'CREATE TABLE products (
                id CHAR(36) PRIMARY KEY,
                requires_dispenser INT NOT NULL DEFAULT 0
            )'
        );
        $this->db->exec(
            'CREATE TABLE transactions (
                id CHAR(36) PRIMARY KEY,
                product_id CHAR(36) NULL,
                created_by_terminal_id CHAR(36) NULL,
                transaction_type VARCHAR(20) NOT NULL,
                occurred_at DATETIME NOT NULL
            )'
        );

        $this->db->exec("INSERT INTO products (id, requires_dispenser) VALUES ('" . self::TOKEN_PRODUCT . "', 1)");
        $this->db->exec("INSERT INTO products (id, requires_dispenser) VALUES ('" . self::BEER . "', 0)");

        $this->repository = new TerminalsRepository($this->db, $this->createMock(Logger::class));
    }

    private function terminal(string $id, ?string $refilledAt, ?int $tokens = 400): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO terminals (id, dispenser_refilled_at, dispenser_refill_tokens) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id, $refilledAt, $tokens]);
    }

    /** One row per token — the shape `CartService.billDispensedTokens` writes. */
    private function sale(
        string $terminalId,
        string $occurredAt,
        string $productId = self::TOKEN_PRODUCT,
        string $type = 'purchase',
    ): void {
        static $n = 0;
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, product_id, created_by_terminal_id, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([sprintf('tx-%04d', ++$n), $productId, $terminalId, $type, $occurredAt]);
    }

    public function test_estimate_subtracts_only_dispenser_purchases_of_this_terminal_since_refill(): void
    {
        $this->terminal(self::BAR, '2026-09-20 18:00:00');
        $this->terminal(self::CLUB, '2026-09-20 18:00:00');

        $this->sale(self::BAR, '2026-09-20 19:00:00');
        $this->sale(self::BAR, '2026-09-20 19:05:00');
        // A beer at the same terminal is not a token.
        $this->sale(self::BAR, '2026-09-20 19:06:00', self::BEER);
        // Another terminal's hopper is another hopper.
        $this->sale(self::CLUB, '2026-09-20 19:10:00');

        $counts = $this->repository->countTokensSoldSinceRefill();

        self::assertSame(2, $counts[self::BAR]);
        self::assertSame(1, $counts[self::CLUB]);
    }

    /**
     * Counting by `occurred_at` rather than by when the row arrived is the
     * whole reason an offline terminal does not corrupt the estimate: it can
     * upload last night's sales tomorrow, and they belong to last night's load.
     */
    public function test_sale_before_refill_synced_after_it_is_not_subtracted(): void
    {
        $this->terminal(self::BAR, '2026-09-20 18:00:00');

        $this->sale(self::BAR, '2026-09-19 21:00:00');
        $this->sale(self::BAR, '2026-09-20 17:59:59');
        $this->sale(self::BAR, '2026-09-20 18:00:00');
        $this->sale(self::BAR, '2026-09-20 18:00:01');

        self::assertSame(2, $this->repository->countTokensSoldSinceRefill()[self::BAR]);
    }

    /**
     * A storno gives a member their money back. It does not give the club its
     * token back — that one is in somebody's pocket — so the hopper is one
     * lighter either way.
     */
    public function test_storno_of_a_token_purchase_does_not_put_a_token_back(): void
    {
        $this->terminal(self::BAR, '2026-09-20 18:00:00');

        $this->sale(self::BAR, '2026-09-20 19:00:00');
        $this->sale(self::BAR, '2026-09-20 19:30:00', self::TOKEN_PRODUCT, 'correction');

        self::assertSame(1, $this->repository->countTokensSoldSinceRefill()[self::BAR]);
    }

    public function test_a_terminal_with_no_refill_recorded_is_not_counted_at_all(): void
    {
        $this->terminal(self::BAR, null, null);
        $this->sale(self::BAR, '2026-09-20 19:00:00');

        // Absent, not zero: "sold since never" is not a number, and the caller
        // must be able to tell that from a hopper nobody has drawn from.
        self::assertArrayNotHasKey(self::BAR, $this->repository->countTokensSoldSinceRefill());
    }

    public function test_it_can_be_narrowed_to_one_terminal(): void
    {
        $this->terminal(self::BAR, '2026-09-20 18:00:00');
        $this->terminal(self::CLUB, '2026-09-20 18:00:00');
        $this->sale(self::BAR, '2026-09-20 19:00:00');
        $this->sale(self::CLUB, '2026-09-20 19:00:00');

        $counts = $this->repository->countTokensSoldSinceRefill(self::BAR);

        self::assertSame([self::BAR => 1], $counts);
    }

    /**
     * The write half: moving the anchor is what ends the old load's count, and
     * it is the only thing a refill writes — there is no counter to reset,
     * because the sales are rows.
     */
    public function test_a_refill_moves_the_anchor_and_the_count_starts_again(): void
    {
        $this->terminal(self::BAR, '2026-09-20 18:00:00');
        $this->sale(self::BAR, '2026-09-20 19:00:00');
        $this->sale(self::BAR, '2026-09-20 19:05:00');

        self::assertSame(2, $this->repository->countTokensSoldSinceRefill()[self::BAR]);

        self::assertTrue($this->repository->recordDispenserRefill(self::BAR, 500, '2026-09-20 20:00:00'));

        // The two sales are behind the new anchor, so they belong to the load
        // that is already gone.
        self::assertArrayNotHasKey(self::BAR, $this->repository->countTokensSoldSinceRefill());

        $row = $this->db->query('SELECT * FROM terminals WHERE id = \'' . self::BAR . '\'')->fetch();
        self::assertSame('2026-09-20 20:00:00', $row['dispenser_refilled_at']);
        self::assertSame(500, (int) $row['dispenser_refill_tokens']);
    }
}
