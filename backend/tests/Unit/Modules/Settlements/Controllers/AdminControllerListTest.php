<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Controllers;

use App\Modules\Settlements\Controllers\AdminController;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Services\SepaExportService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Shared\Services\AuditService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The settlements list and its two CSV exports (#119).
 *
 * The list spelled the page `current_page` and omitted the page count. The
 * member CSV joined four fields with `;` and no escaping at all, so a member
 * called "Meier; Hans" shifted the IBAN into the name column and the amount
 * into the IBAN column — a bank file that looks right and is not.
 */
class AdminControllerListTest extends TestCase
{
    use ListEndpointAssertions;

    private SettlementsService $service;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(SettlementsService::class);

        $this->controller = new AdminController(
            $this->service,
            $this->createMock(SepaExportService::class),
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(SettlementReversalService::class),
            $this->createMock(EncryptionKeyService::class),
            $this->createMock(StepUpAuthService::class),
            $this->createMock(AuditService::class),
        );
    }

    // ── The list ──────────────────────────────────────────────────────

    public function test_index_answers_with_the_canonical_envelope(): void
    {
        $this->service->method('listSettlements')
            ->willReturn(new PaginatedResultDto([['id' => 's-1']], total: 7, limit: 20, offset: 0));

        $body = $this->decode($this->controller->index($this->get('/api/admin/settlements'), new Response()));

        $this->assertSame([['id' => 's-1']], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 7, 'total_pages' => 1], $body['pagination']);
        $this->assertArrayNotHasKey('current_page', $body['pagination']);
    }

    public function test_index_defaults_to_twenty_per_page_newest_first(): void
    {
        $this->service->expects($this->once())
            ->method('listSettlements')
            ->with(20, 0, null, 'created_at', 'desc', null, null)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 20, offset: 0));

        $this->controller->index($this->get('/api/admin/settlements'), new Response());
    }

    public function test_index_passes_status_and_date_filters(): void
    {
        $this->service->expects($this->once())
            ->method('listSettlements')
            ->with(20, 0, 'active', 'created_at', 'desc', '2026-01-01', '2026-01-31')
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 20, offset: 0));

        $request = $this->get('/api/admin/settlements', [
            'status' => 'active',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->controller->index($request, new Response());
    }

    public function test_index_understands_the_combined_sort_by_dialect(): void
    {
        $this->service->expects($this->once())
            ->method('listSettlements')
            ->with(20, 0, null, 'created_at', 'asc', null, null)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 20, offset: 0));

        $this->controller->index($this->get('/api/admin/settlements', ['sort_by' => 'created_at_asc']), new Response());
    }

    public function test_index_refuses_a_per_page_over_the_cap(): void
    {
        $this->service->expects($this->never())->method('listSettlements');

        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->index($this->get('/api/admin/settlements', ['per_page' => '101']), new Response());
    }

    // ── The member CSV ────────────────────────────────────────────────

    /**
     * SettlementDto is final and cannot be doubled; only `items` matters here.
     *
     * @param list<array<string, mixed>> $items
     */
    private function settlement(array $items = []): SettlementDto
    {
        return new SettlementDto(
            id: 's-1',
            method: SettlementMethod::DIRECT_DEBIT,
            settlementDate: '2026-01-01',
            executionDate: '2026-01-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 0,
            memberCount: 0,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: $items,
            createdAt: '2026-01-01 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );
    }

    private function memberCsv(array $rows): string
    {
        $this->service->method('getSettlement')->willReturn($this->settlement());
        $this->service->method('getCsvData')->willReturn($rows);

        $response = $this->controller->exportCsv($this->get('/api/admin/settlements/s-1/export/csv'), new Response(), ['id' => 's-1']);
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    public function test_the_member_csv_writes_euros_under_a_stable_header(): void
    {
        $csv = $this->memberCsv([
            ['name' => 'Hans Meier', 'email' => 'hans@example.com', 'iban' => 'DE89370400440532013000', 'amount_cents' => 1234],
        ]);

        $this->assertSame(
            "Member Name;Email;IBAN;Amount EUR\nHans Meier;hans@example.com;DE89370400440532013000;12.34\n",
            $csv
        );
    }

    public function test_the_member_csv_quotes_a_name_containing_a_semicolon(): void
    {
        $csv = $this->memberCsv([
            ['name' => 'Meier; Hans', 'email' => 'h@example.com', 'iban' => 'DE89', 'amount_cents' => 500],
        ]);

        $rows = explode("\n", trim($csv));
        $this->assertSame('"Meier; Hans";h@example.com;DE89;5.00', $rows[1]);
    }

    public function test_the_member_csv_keeps_a_credit_negative(): void
    {
        $csv = $this->memberCsv([
            ['name' => 'A', 'email' => 'a@example.com', 'iban' => 'DE89', 'amount_cents' => -2750],
        ]);

        $this->assertStringContainsString(';-27.50', $csv);
    }

    public function test_the_member_csv_is_a_header_alone_when_there_is_nothing_to_collect(): void
    {
        $this->assertSame("Member Name;Email;IBAN;Amount EUR\n", $this->memberCsv([]));
    }

    public function test_the_member_csv_404s_for_an_unknown_settlement(): void
    {
        $this->service->method('getSettlement')->willReturn(null);

        $response = $this->controller->exportCsv($this->get('/api/admin/settlements/nope/export/csv'), new Response(), ['id' => 'nope']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_the_member_csv_is_served_as_an_attachment(): void
    {
        $this->service->method('getSettlement')->willReturn($this->settlement());
        $this->service->method('getCsvData')->willReturn([]);

        $response = $this->controller->exportCsv($this->get('/api/admin/settlements/s-1/export/csv'), new Response(), ['id' => 's-1']);

        $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('settlement-s-1.csv', $response->getHeaderLine('Content-Disposition'));
    }

    // ── The transactions CSV ──────────────────────────────────────────

    private function transactionsCsv(array $items): string
    {
        $this->service->method('getSettlement')->willReturn($this->settlement($items));

        $response = $this->controller->exportTransactionsCsv(
            $this->get('/api/admin/settlements/s-1/export-transactions'),
            new Response(),
            ['id' => 's-1'],
        );
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    public function test_the_transactions_csv_takes_its_header_from_the_first_row(): void
    {
        $csv = $this->transactionsCsv([
            ['transaction_id' => 't-1', 'product_name' => 'Bier', 'amount_cents' => 350],
            ['transaction_id' => 't-2', 'product_name' => 'Wasser', 'amount_cents' => 150],
        ]);

        $this->assertSame(
            "transaction_id;product_name;amount_cents\nt-1;Bier;350\nt-2;Wasser;150\n",
            $csv
        );
    }

    public function test_the_transactions_csv_quotes_a_value_containing_the_delimiter(): void
    {
        $csv = $this->transactionsCsv([['product_name' => 'Bier; gross']]);

        $this->assertStringContainsString('"Bier; gross"', $csv);
    }

    public function test_the_transactions_csv_is_empty_when_the_settlement_has_no_items(): void
    {
        $this->assertSame('', $this->transactionsCsv([]));
    }
}
