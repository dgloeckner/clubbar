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

    private function request(array $transactions, ?string $terminalId = self::TERMINAL_ID): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/sync/transactions')
            ->withParsedBody(['transactions' => $transactions])
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

    public function test_an_invalid_batch_is_rejected_before_any_row_is_built(): void
    {
        $this->transactionsService->expects($this->never())->method('processBatch');

        $response = $this->controller->processBatch(
            $this->request([['member_id' => 'member-1']]),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $this->decode($response)['error']);
    }

    public function test_a_non_array_batch_entry_cannot_crash_the_endpoint(): void
    {
        // Reached only if validation lets it through; the allowlist must not be
        // the thing that turns a malformed payload into a 500.
        $this->transactionsService->expects($this->never())->method('processBatch');

        $response = $this->controller->processBatch(
            $this->request(['not-an-object']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
    }
}
