<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Controllers;

use App\Modules\Transactions\Controllers\SyncController;
use App\Modules\Transactions\DTOs\TransactionBatchResultDto;
use App\Modules\Transactions\Services\TransactionsService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The sync endpoint's HTTP edge (#79, ruling #144 §3–4).
 *
 * The point of these tests is what the controller refuses to pass on. It used
 * to hand the client's array to the service unchanged, so a terminal token
 * could set `transaction_type`, `related_transaction_id` and
 * `created_by_admin_id` — forging a storno, and consuming the reversal slot
 * that #169's UNIQUE index makes single-use.
 *
 * Asserted here on the array the service actually receives, because that is
 * the boundary the allowlist has to hold; the end-to-end proof that the stored
 * row matches lives in e2etests/tests/api/transactions.spec.ts.
 */
class SyncControllerTest extends TestCase
{
    private const TERMINAL_ID = 'terminal-1';

    private TransactionsService $transactionsService;
    private SyncController $controller;

    protected function setUp(): void
    {
        $this->transactionsService = $this->createMock(TransactionsService::class);
        $this->controller = new SyncController($this->transactionsService);
    }

    private function request(array $transactions, ?string $terminalId = self::TERMINAL_ID, ?array $memberIds = null): \Psr\Http\Message\ServerRequestInterface
    {
        $body = ['transactions' => $transactions];
        if ($memberIds !== null) {
            $body['member_ids'] = $memberIds;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/sync/transactions')
            ->withParsedBody($body)
            ->withAttribute('terminal_id', $terminalId);
    }

    private function sale(array $overrides = []): array
    {
        return array_merge([
            'id' => 'tx-1',
            'member_id' => 'member-1',
            'product_id' => 'product-1',
            'amount_cents' => 350,
            'created_at' => '2026-08-08T18:00:00Z',
        ], $overrides);
    }

    private function decode(Response $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @return array the rows the service was handed
     */
    private function rowsReachingTheService(array $transactions, ?string $terminalId = self::TERMINAL_ID): array
    {
        $captured = [];

        $this->transactionsService->expects($this->once())
            ->method('processBatch')
            ->willReturnCallback(function (array $rows) use (&$captured) {
                $captured = $rows;
                return new TransactionBatchResultDto([], 0, [], []);
            });

        $response = $this->controller->processBatch(
            $this->request($transactions, $terminalId),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());

        return $captured;
    }

    public function test_a_forged_transaction_type_never_reaches_the_service(): void
    {
        $rows = $this->rowsReachingTheService([$this->sale(['transaction_type' => 'storno'])]);

        $this->assertSame('purchase', $rows[0]['transaction_type']);
    }

    public function test_a_forged_related_transaction_id_never_reaches_the_service(): void
    {
        $rows = $this->rowsReachingTheService([$this->sale(['related_transaction_id' => 'purchase-to-block'])]);

        $this->assertNull($rows[0]['related_transaction_id']);
    }

    public function test_a_forged_created_by_admin_id_never_reaches_the_service(): void
    {
        $rows = $this->rowsReachingTheService([$this->sale(['created_by_admin_id' => 'admin-1'])]);

        $this->assertNull($rows[0]['created_by_admin_id']);
    }

    public function test_the_terminal_id_comes_from_the_authenticated_token(): void
    {
        $rows = $this->rowsReachingTheService([$this->sale(['created_by_terminal_id' => 'someone-elses-terminal'])]);

        $this->assertSame(self::TERMINAL_ID, $rows[0]['created_by_terminal_id']);
    }

    public function test_every_entry_of_a_batch_is_rebuilt_not_only_the_first(): void
    {
        $rows = $this->rowsReachingTheService([
            $this->sale(['id' => 'tx-1']),
            $this->sale(['id' => 'tx-2', 'transaction_type' => 'payout', 'created_by_admin_id' => 'admin-1']),
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame('purchase', $rows[1]['transaction_type']);
        $this->assertNull($rows[1]['created_by_admin_id']);
    }

    public function test_the_sale_the_terminal_recorded_is_passed_through_untouched(): void
    {
        $rows = $this->rowsReachingTheService([$this->sale([
            'dispenser_tx_id' => 'DISP-1',
            'dispenser_requested' => 500,
            'dispenser_actual' => 495,
        ])]);

        $this->assertSame('tx-1', $rows[0]['id']);
        $this->assertSame('member-1', $rows[0]['member_id']);
        $this->assertSame('product-1', $rows[0]['product_id']);
        $this->assertSame(350, $rows[0]['amount_cents']);
        $this->assertSame('2026-08-08T18:00:00Z', $rows[0]['created_at']);
        $this->assertSame('DISP-1', $rows[0]['dispenser_tx_id']);
        $this->assertSame(500, $rows[0]['dispenser_requested']);
        $this->assertSame(495, $rows[0]['dispenser_actual']);
    }

    /**
     * #259, ruling #143 §2. This used to assert the opposite: a row missing a
     * required field refused the **whole batch** with a 422 and stored
     * nothing. Because the terminal treats a non-2xx as transient and retries
     * the batch unchanged — and only quarantines on a 2xx — an unstorable row
     * took every good sale queued behind it down with it, permanently.
     *
     * A field-level problem now belongs to the row that has it. The controller
     * passes the batch on, and the service answers per item.
     */
    public function test_a_row_missing_a_required_field_does_not_refuse_the_batch(): void
    {
        $captured = [];

        $this->transactionsService->expects($this->once())
            ->method('processBatch')
            ->willReturnCallback(function (array $rows) use (&$captured) {
                $captured = $rows;
                return new TransactionBatchResultDto([], 1, [], []);
            });

        $response = $this->controller->processBatch(
            $this->request([$this->sale(), ['member_id' => 'member-1']]),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(2, $captured, 'the good sale must still reach the service');
    }

    public function test_a_non_array_batch_entry_cannot_crash_the_endpoint(): void
    {
        // The allowlist must not be the thing that turns a malformed payload
        // into a 500: it rebuilds the entry as a row carrying only the
        // server-owned fields, which the service then rejects on its own terms.
        $this->transactionsService->expects($this->once())
            ->method('processBatch')
            ->willReturn(new TransactionBatchResultDto([], 1, [], []));

        $response = $this->controller->processBatch(
            $this->request(['not-an-object']),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * The envelope is still the one thing a whole-batch 4xx is for: nothing in
     * it was examined, so there is no per-item verdict to give.
     */
    public function test_a_batch_over_the_size_limit_is_still_refused_whole(): void
    {
        $this->transactionsService->expects($this->never())->method('processBatch');

        $response = $this->controller->processBatch(
            $this->request(array_fill(0, 101, $this->sale())),
            new Response(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_request', $this->decode($response)['error']);
    }

    // ------------------------------------------------------------------
    // Asking for a balance without selling anything (#191)
    // ------------------------------------------------------------------

    public function test_an_empty_batch_that_names_members_is_a_request_not_a_rejection(): void
    {
        $captured = null;

        $this->transactionsService->expects($this->once())
            ->method('processBatch')
            ->willReturnCallback(function (array $rows, array $memberIds) use (&$captured) {
                $captured = $memberIds;
                return new TransactionBatchResultDto([], 0, [], []);
            });

        $response = $this->controller->processBatch(
            $this->request([], self::TERMINAL_ID, ['member-1']),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['member-1'], $captured);
    }

    public function test_an_empty_batch_that_names_nobody_is_still_rejected(): void
    {
        // It asks for nothing. Accepting it would make a no-op round trip look
        // like a successful refresh.
        $this->transactionsService->expects($this->never())->method('processBatch');

        $response = $this->controller->processBatch(
            $this->request([], self::TERMINAL_ID, []),
            new Response(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_request', $this->decode($response)['error']);
    }

    public function test_a_malformed_member_id_costs_a_balance_not_the_upload(): void
    {
        // member_ids is a read. Rejecting the batch over a bad entry would
        // refuse an upload of real sales over a query parameter.
        $captured = null;

        $this->transactionsService->expects($this->once())
            ->method('processBatch')
            ->willReturnCallback(function (array $rows, array $memberIds) use (&$captured) {
                $captured = $memberIds;
                return new TransactionBatchResultDto([], 0, [], []);
            });

        $response = $this->controller->processBatch(
            $this->request([$this->sale()], self::TERMINAL_ID, ['member-1', '', 42, null, ['nested']]),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['member-1'], $captured);
    }

    public function test_more_member_ids_than_the_batch_limit_are_refused(): void
    {
        $this->transactionsService->expects($this->never())->method('processBatch');

        $response = $this->controller->processBatch(
            $this->request([], self::TERMINAL_ID, array_map(fn(int $i) => "member-$i", range(1, 101))),
            new Response(),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_request', $this->decode($response)['error']);
    }

    /**
     * `limit` and `offset` on the history endpoint used to go into LIMIT/OFFSET
     * as the terminal sent them, so `limit=-1` was a SQL error and therefore a
     * 500 (#117). They are clamped rather than refused: a 400 in the middle of
     * a sync costs the terminal more than a shorter page does.
     *
     * @return array<string, array{0: array<string, string>, 1: int, 2: int}>
     */
    public static function historyPagination(): array
    {
        return [
            'defaults' => [[], 50, 0],
            'negative limit' => [['limit' => '-1'], 1, 0],
            'zero limit' => [['limit' => '0'], 1, 0],
            'negative offset' => [['offset' => '-5'], 50, 0],
            'limit past the cap' => [['limit' => '100000000'], 100, 0],
            'both out of range' => [['limit' => '-1', 'offset' => '-5'], 1, 0],
            'a page the terminal may legitimately ask for' => [['limit' => '25', 'offset' => '50'], 25, 50],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historyPagination')]
    public function test_history_pagination_is_clamped_into_range(array $query, int $limit, int $offset): void
    {
        $this->transactionsService->expects($this->once())
            ->method('getRecentTransactionsForMember')
            ->with('member-1', $limit, $offset, null)
            ->willReturn([]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/terminal/transactions/member-1')
            ->withQueryParams($query);

        $response = $this->controller->transactionHistory($request, new Response(), ['memberId' => 'member-1']);

        $this->assertSame(200, $response->getStatusCode());
    }
}
