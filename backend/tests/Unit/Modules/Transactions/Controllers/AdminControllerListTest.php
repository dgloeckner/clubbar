<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Controllers;

use App\Modules\Transactions\Controllers\AdminController;
use App\Modules\Transactions\Services\TransactionsService;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The journal list and its CSV export (#119).
 *
 * The list answered `{items,total,limit,offset,has_more}` with no cap. The
 * export wrote raw cents in a column called "amount" while the settlement
 * export next to it wrote euros, and escaped `;` by replacing it with a comma
 * — which quietly rewrites the data rather than quoting it.
 */
class AdminControllerListTest extends TestCase
{
    use ListEndpointAssertions;

    private TransactionsService $service;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(TransactionsService::class);
        $this->controller = new AdminController($this->service, new Validator($this->createMock(\PDO::class)));
    }

    private function expectList(int $limit, int $offset, array $filters, string $sortKey, string $sortOrder): void
    {
        $this->service->expects($this->once())
            ->method('getTransactions')
            ->with($limit, $offset, $filters, $sortKey, $sortOrder)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: $limit, offset: $offset));
    }

    // ── The list ──────────────────────────────────────────────────────

    public function test_it_answers_with_the_canonical_envelope(): void
    {
        $this->service->method('getTransactions')
            ->willReturn(new PaginatedResultDto([['id' => 't-1']], total: 45, limit: 20, offset: 0));

        $body = $this->decode($this->controller->getTransactions($this->get('/api/admin/transactions'), new Response()));

        $this->assertSame([['id' => 't-1']], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 45, 'total_pages' => 3], $body['pagination']);
        $this->assertArrayNotHasKey('items', $body);
    }

    public function test_it_defaults_to_twenty_per_page(): void
    {
        $this->expectList(20, 0, [], 'created_at', 'desc');

        $this->controller->getTransactions($this->get('/api/admin/transactions'), new Response());
    }

    public function test_it_maps_the_type_parameter_to_a_transaction_type_filter(): void
    {
        $this->expectList(20, 0, ['transaction_type' => 'storno'], 'created_at', 'desc');

        $this->controller->getTransactions($this->get('/api/admin/transactions', ['type' => 'storno']), new Response());
    }

    public function test_it_maps_settlement_status_open_to_unsettled(): void
    {
        $this->expectList(20, 0, ['settlement_status' => 'unsettled'], 'created_at', 'desc');

        $this->controller->getTransactions($this->get('/api/admin/transactions', ['settlement_status' => 'open']), new Response());
    }

    public function test_settlement_status_all_means_no_filter(): void
    {
        $this->expectList(20, 0, [], 'created_at', 'desc');

        $this->controller->getTransactions($this->get('/api/admin/transactions', ['settlement_status' => 'all']), new Response());
    }

    public function test_it_passes_member_date_and_search_filters(): void
    {
        $this->expectList(20, 0, [
            'member_id' => 'm-1',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'search' => 'bier',
        ], 'created_at', 'desc');

        $request = $this->get('/api/admin/transactions', [
            'member_id' => 'm-1',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'search' => 'bier',
        ]);

        $this->controller->getTransactions($request, new Response());
    }

    public function test_it_understands_the_combined_sort_by_dialect(): void
    {
        $this->expectList(20, 0, [], 'amount', 'asc');

        $this->controller->getTransactions($this->get('/api/admin/transactions', ['sort_by' => 'amount_asc']), new Response());
    }

    public function test_it_refuses_a_per_page_over_the_cap(): void
    {
        $this->service->expects($this->never())->method('getTransactions');

        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->getTransactions($this->get('/api/admin/transactions', ['per_page' => '5000']), new Response());
    }

    // ── The CSV export ────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $items
     */
    private function exportCsv(array $items, array $query = []): string
    {
        $this->service->method('getTransactions')
            ->willReturn(new PaginatedResultDto($items, total: count($items), limit: 10000, offset: 0));

        $response = $this->controller->exportTransactions($this->get('/api/admin/transactions/export', $query), new Response());
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    public function test_the_export_names_its_money_column_for_its_unit(): void
    {
        $csv = $this->exportCsv([]);

        $this->assertSame("date;member_name;product;type;amount_eur\n", $csv);
    }

    public function test_the_export_writes_euros_not_cents(): void
    {
        $csv = $this->exportCsv([[
            'created_at' => '2026-01-15 18:22:01',
            'member_name' => 'Hans Meier',
            'product_names' => '{"de":"Bier","en":"Beer"}',
            'transaction_type' => 'purchase',
            'amount_cents' => 350,
        ]]);

        $this->assertSame(
            "date;member_name;product;type;amount_eur\n2026-01-15;Hans Meier;Bier;purchase;3.50\n",
            $csv
        );
    }

    /**
     * The old writer replaced `;` with `,` inside the value — the file parsed,
     * and the name was wrong. Quoting keeps the data intact.
     */
    public function test_the_export_quotes_a_member_name_containing_the_delimiter(): void
    {
        $csv = $this->exportCsv([[
            'created_at' => '2026-01-15 18:22:01',
            'member_name' => 'Meier; Hans',
            'product_names' => null,
            'notes' => 'Bar',
            'transaction_type' => 'purchase',
            'amount_cents' => 100,
        ]]);

        $this->assertStringContainsString('"Meier; Hans"', $csv);
    }

    public function test_the_export_prefers_the_german_product_name(): void
    {
        $csv = $this->exportCsv([[
            'created_at' => '2026-01-15 18:22:01',
            'member_name' => 'A',
            'product_names' => ['de' => 'Bier', 'en' => 'Beer'],
            'transaction_type' => 'purchase',
            'amount_cents' => 100,
        ]]);

        $this->assertStringContainsString(';Bier;', $csv);
    }

    public function test_the_export_falls_back_to_english_then_to_the_note(): void
    {
        $csv = $this->exportCsv([
            [
                'created_at' => '2026-01-15 18:22:01',
                'member_name' => 'A',
                'product_names' => '{"en":"Beer"}',
                'transaction_type' => 'purchase',
                'amount_cents' => 100,
            ],
            [
                'created_at' => '2026-01-16 18:22:01',
                'member_name' => 'B',
                'product_names' => null,
                'notes' => 'Correction',
                'transaction_type' => 'storno',
                'amount_cents' => -100,
            ],
        ]);

        $this->assertStringContainsString(';Beer;', $csv);
        $this->assertStringContainsString(';Correction;storno;-1.00', $csv);
    }

    public function test_the_export_rejects_a_malformed_date(): void
    {
        $response = $this->controller->exportTransactions(
            $this->get('/api/admin/transactions/export', ['from_date' => '01-01-2026']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('from_date', $this->decode($response)['errors']);
    }

    public function test_the_export_rejects_an_inverted_date_range(): void
    {
        $response = $this->controller->exportTransactions(
            $this->get('/api/admin/transactions/export', ['from_date' => '2026-01-31', 'to_date' => '2026-01-01']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('to_date', $this->decode($response)['errors']);
    }

    public function test_the_export_names_the_file_after_the_date_range(): void
    {
        $this->service->method('getTransactions')
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 10000, offset: 0));

        $response = $this->controller->exportTransactions(
            $this->get('/api/admin/transactions/export', ['from_date' => '2026-01-15', 'to_date' => '2026-01-25']),
            new Response(),
        );

        $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('transactions-2026-01-15-to-2026-01-25.csv', $response->getHeaderLine('Content-Disposition'));
    }

    public function test_the_export_falls_back_to_a_generic_filename(): void
    {
        $this->service->method('getTransactions')
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 10000, offset: 0));

        $response = $this->controller->exportTransactions($this->get('/api/admin/transactions/export'), new Response());

        $this->assertStringContainsString('transactions-export.csv', $response->getHeaderLine('Content-Disposition'));
    }
}
