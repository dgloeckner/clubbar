<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Controllers;

use App\Modules\Settlements\Controllers\AdminController;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Services\SepaExportService;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Creating a settlement by naming its **members** (ADR-0030).
 *
 * The New Settlement screen holds members, not transactions, so it says so.
 * `transaction_ids` remains as the compatibility path and must keep behaving
 * exactly as it did — the two are one run described two ways, not two
 * behaviours.
 *
 * Both dates below are Mondays, seven days apart, so the `business_day` rule
 * and the lead-time check pass and the test is about the branch it names.
 */
class AdminControllerStoreTest extends TestCase
{
    private const SETTLEMENT_DATE = '2026-03-02';
    private const EXECUTION_DATE = '2026-03-16';

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
        );
    }

    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements')
            ->withParsedBody($body + [
                'settlement_date' => self::SETTLEMENT_DATE,
                'execution_date' => self::EXECUTION_DATE,
            ])
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    private function createdSettlement(): SettlementDto
    {
        return SettlementDto::fromRow([
            'id' => 'settlement-1',
            'method' => 'direct_debit',
            'settlement_date' => self::SETTLEMENT_DATE,
            'execution_date' => self::EXECUTION_DATE,
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => 'SEPA-1',
            'total_amount_cents' => 1200,
            'member_count' => 1,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-03-02 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ], []);
    }

    public function test_store_forwards_member_ids_and_sends_no_transaction_ids(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->with(
                [],
                self::SETTLEMENT_DATE,
                self::EXECUTION_DATE,
                null,
                null,
                SettlementMethod::DIRECT_DEBIT,
                null,
                'admin-1',
                ['member-a', 'member-b'],
            )
            ->willReturn($this->createdSettlement());

        $response = $this->controller->store($this->post(['member_ids' => ['member-a', 'member-b']]), new Response());

        $this->assertSame(201, $response->getStatusCode());
    }

    /** The compatibility path is unchanged: no member ids, ids passed through. */
    public function test_store_still_accepts_transaction_ids(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->with(
                ['tx-1'],
                self::SETTLEMENT_DATE,
                self::EXECUTION_DATE,
                null,
                null,
                SettlementMethod::DIRECT_DEBIT,
                null,
                'admin-1',
                null,
            )
            ->willReturn($this->createdSettlement());

        $response = $this->controller->store($this->post(['transaction_ids' => ['tx-1']]), new Response());

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_store_rejects_an_empty_member_id_list(): void
    {
        $this->service->expects($this->never())->method('createSettlement');

        $response = $this->controller->store($this->post(['member_ids' => []]), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('member_ids', $this->decode($response)['messages']);
    }

    public function test_store_rejects_a_scalar_member_ids(): void
    {
        $this->service->expects($this->never())->method('createSettlement');

        $response = $this->controller->store($this->post(['member_ids' => 'member-a']), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('member_ids', $this->decode($response)['messages']);
    }

    /** Naming neither is not a run. */
    public function test_store_rejects_a_body_naming_no_participants(): void
    {
        $this->service->expects($this->never())->method('createSettlement');

        $response = $this->controller->store($this->post([]), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('transaction_ids', $this->decode($response)['messages']);
    }

    /**
     * `member_ids` present means the member path, so a stray `transaction_ids`
     * alongside it must not quietly become the participant list.
     */
    public function test_store_prefers_member_ids_when_both_are_present(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->with([], $this->anything(), $this->anything(), null, null, $this->anything(), null, 'admin-1', ['member-a'])
            ->willReturn($this->createdSettlement());

        $this->controller->store(
            $this->post(['member_ids' => ['member-a'], 'transaction_ids' => ['tx-9']]),
            new Response(),
        );
    }

    public function test_store_stringifies_and_reindexes_member_ids(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->with([], $this->anything(), $this->anything(), null, null, $this->anything(), null, 'admin-1', ['member-a', 'member-b'])
            ->willReturn($this->createdSettlement());

        $this->controller->store($this->post(['member_ids' => [4 => 'member-a', 9 => 'member-b']]), new Response());
    }

    public function test_store_passes_method_and_notes_through_on_the_member_path(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->with([], $this->anything(), $this->anything(), null, null, SettlementMethod::WRITE_OFF, 'uncollectable', 'admin-1', ['member-a'])
            ->willReturn($this->createdSettlement());

        $this->controller->store(
            $this->post(['member_ids' => ['member-a'], 'method' => 'write_off', 'notes' => 'uncollectable']),
            new Response(),
        );
    }

    /** The lead-time rule belongs to the run, not to how it named its members. */
    public function test_store_enforces_lead_time_on_the_member_path(): void
    {
        $this->service->expects($this->never())->method('createSettlement');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements')
            ->withParsedBody([
                'member_ids' => ['member-a'],
                'settlement_date' => '2026-03-02',
                'execution_date' => '2026-03-05',
            ])
            ->withAttribute('admin_user_id', 'admin-1');

        $response = $this->controller->store($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('execution_date', $this->decode($response)['messages']);
    }
}
