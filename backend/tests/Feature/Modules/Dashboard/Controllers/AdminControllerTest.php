<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Controllers\AdminController;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Feature\DatabaseTestCase;

/**
 * The dashboard's revenue figures (#116).
 *
 * Revenue on this screen used to sum every transaction type while the sold-item
 * counter beside it counted purchases alone, so a storno moved one number and
 * not the other and the Dashboard disagreed with the Reports page — which has
 * always filtered to purchases — for the same period. These tests pin the
 * agreed definition: revenue is purchases, everywhere on the screen.
 *
 * The two tests measure differently on purpose. Monthly statistics accept a
 * month parameter, so they can be asserted absolutely inside a month no other
 * data occupies. The dashboard's today/WTD/MTD figures are fixed to the current
 * period and cannot be isolated that way, so they are asserted as an exact
 * before/after delta instead.
 */
class AdminControllerTest extends DatabaseTestCase
{
    /** A month far enough in the past that no seed or other test writes into it. */
    private const ISOLATED_MONTH = '2001-03';

    private AdminController $controller;

    /** @var string[] */
    private array $transactionIds = [];

    /** @var string[] */
    private array $memberIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new AdminController(
            new MembersRepository($this->db, $this->logger),
            new TransactionsRepository($this->db, $this->logger),
            new SettlementsRepository($this->db, $this->logger),
            new TerminalsRepository($this->db, $this->logger),
            $this->db,
        );
    }

    protected function tearDown(): void
    {
        // Stornos point at their original, so they have to go first.
        $this->cleanupTestData('transactions', array_reverse($this->transactionIds));
        $this->cleanupTestData('members', $this->memberIds);
        parent::tearDown();
    }

    public function test_monthly_revenue_counts_purchases_only(): void
    {
        $memberId = $this->createMember();
        $purchase = $this->createPurchase($memberId, 5000, self::ISOLATED_MONTH . '-15 12:00:00');
        $this->createStorno($purchase, 5000, self::ISOLATED_MONTH . '-15 12:05:00');
        $this->createPurchase($memberId, 2500, self::ISOLATED_MONTH . '-16 12:00:00');

        $stats = $this->monthlyStats(self::ISOLATED_MONTH);

        // The storno is -5000; when it counted, this was 2500.
        $this->assertSame(7500, $stats['total_revenue_cents']);
        $this->assertSame(2, $stats['total_sold_items']);
    }

    public function test_daily_revenue_rows_reconcile_with_the_monthly_total(): void
    {
        $memberId = $this->createMember();
        $purchase = $this->createPurchase($memberId, 5000, self::ISOLATED_MONTH . '-15 12:00:00');
        $this->createStorno($purchase, 5000, self::ISOLATED_MONTH . '-15 12:05:00');
        $this->createPurchase($memberId, 2500, self::ISOLATED_MONTH . '-16 12:00:00');

        $stats = $this->monthlyStats(self::ISOLATED_MONTH);
        $daily = $stats['daily_revenue'];

        // The 15th holds a purchase and its storno; only the purchase is revenue,
        // and the day is a single sold item rather than two transactions.
        $this->assertSame(
            [
                ['date' => self::ISOLATED_MONTH . '-15', 'revenue_cents' => 5000, 'transaction_count' => 1],
                ['date' => self::ISOLATED_MONTH . '-16', 'revenue_cents' => 2500, 'transaction_count' => 1],
            ],
            $daily,
        );

        // The breakdown and the headline are the same number, which is the whole
        // point of the shared filter.
        $this->assertSame(
            $stats['total_revenue_cents'],
            array_sum(array_column($daily, 'revenue_cents')),
        );
        $this->assertSame(
            $stats['total_sold_items'],
            array_sum(array_column($daily, 'transaction_count')),
        );
    }

    public function test_dashboard_period_revenue_ignores_a_storno(): void
    {
        $before = $this->dashboardMetrics();

        $memberId = $this->createMember();
        $now = date('Y-m-d H:i:s');
        $purchase = $this->createPurchase($memberId, 4200, $now);
        $this->createStorno($purchase, 4200, $now);

        $after = $this->dashboardMetrics();

        // Purchase minus storno would be zero; purchases-only is the full 4200.
        foreach (['todays_revenue_cents', 'wtd_revenue_cents', 'mtd_revenue_cents'] as $metric) {
            $this->assertSame(
                4200,
                $after[$metric] - $before[$metric],
                "{$metric} must move by the purchase alone",
            );
        }
    }

    public function test_outstanding_balance_still_nets_off_the_storno(): void
    {
        $before = $this->dashboardMetrics();

        $memberId = $this->createMember();
        $now = date('Y-m-d H:i:s');
        $purchase = $this->createPurchase($memberId, 4200, $now);
        $this->createStorno($purchase, 4200, $now);

        $after = $this->dashboardMetrics();

        // What the member owes is a different question from what the bar sold:
        // a reversed booking is not owed, so this figure keeps counting stornos.
        $this->assertSame(
            0,
            $after['outstanding_balance_cents'] - $before['outstanding_balance_cents'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function monthlyStats(string $month): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/statistics/monthly')
            ->withQueryParams(['month' => $month]);

        $response = $this->controller->monthlyStats($request, new Response());
        $this->assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, int>
     */
    private function dashboardMetrics(): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/dashboard');

        $response = $this->controller->show($request, new Response());
        $this->assertSame(200, $response->getStatusCode());

        return $this->decode($response)['metrics'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function createMember(): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id, 'Dashboard', 'RevenueTest']);
        $this->memberIds[] = $id;

        return $id;
    }

    private function createPurchase(string $memberId, int $amountCents, string $occurredAt): string
    {
        return $this->insertTransaction($memberId, $amountCents, 'purchase', $occurredAt, null);
    }

    private function createStorno(string $originalId, int $amountCents, string $occurredAt): string
    {
        $stmt = $this->db->prepare('SELECT member_id FROM transactions WHERE id = ?');
        $stmt->execute([$originalId]);
        $memberId = (string) $stmt->fetchColumn();

        return $this->insertTransaction($memberId, -$amountCents, 'storno', $occurredAt, $originalId);
    }

    private function insertTransaction(
        string $memberId,
        int $amountCents,
        string $type,
        string $occurredAt,
        ?string $relatedTransactionId,
    ): string {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO transactions
                (id, member_id, amount_cents, transaction_type, occurred_at, related_transaction_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $memberId, $amountCents, $type, $occurredAt, $relatedTransactionId]);
        $this->transactionIds[] = $id;

        return $id;
    }
}
