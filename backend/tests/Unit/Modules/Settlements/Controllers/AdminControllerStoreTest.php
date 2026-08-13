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
use App\Shared\Utils\BankingCalendar;
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
 * The execution date below is a business day a month out, so the `business_day`
 * rule and the lead-time check pass and the test is about the branch it names.
 * It is relative to today because the lead time is (issue #113) — a fixed date
 * would start failing the moment it fell into the past.
 */
class AdminControllerStoreTest extends TestCase
{
    private SettlementsService $service;
    private AdminController $controller;

    /** A bank business day well past the lead time, whenever this test runs. */
    private static function executionDate(): string
    {
        return BankingCalendar::nextBusinessDay(date('Y-m-d', strtotime('+30 days')));
    }

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

    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements')
            ->withParsedBody($body + ['execution_date' => self::executionDate()])
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
            'settlement_date' => date('Y-m-d'),
            'execution_date' => self::executionDate(),
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => 'SEPA-1',
            'total_amount_cents' => 1200,
            'member_count' => 1,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => date('Y-m-d') . ' 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ], []);
    }

    public function test_store_forwards_member_ids_and_sends_no_transaction_ids(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->with(
                [],
                self::executionDate(),
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
                self::executionDate(),
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
            ->with([], self::executionDate(), null, null, $this->anything(), null, 'admin-1', ['member-a'])
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
            ->with([], self::executionDate(), null, null, $this->anything(), null, 'admin-1', ['member-a', 'member-b'])
            ->willReturn($this->createdSettlement());

        $this->controller->store($this->post(['member_ids' => [4 => 'member-a', 9 => 'member-b']]), new Response());
    }

    public function test_store_passes_method_and_notes_through_on_the_member_path(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->with([], self::executionDate(), null, null, SettlementMethod::WRITE_OFF, 'uncollectable', 'admin-1', ['member-a'])
            ->willReturn($this->createdSettlement());

        $this->controller->store(
            $this->post(['member_ids' => ['member-a'], 'method' => 'write_off', 'notes' => 'uncollectable']),
            new Response(),
        );
    }

    /**
     * A business day inside the lead time — the next one after tomorrow. The
     * roll never reaches +7 (its worst case is the four consecutive TARGET2
     * closing days at Easter, which is +5 from tomorrow), so this date always
     * fails the lead time and never the `business_day` rule.
     */
    private static function tooSoon(): string
    {
        return BankingCalendar::nextBusinessDay(date('Y-m-d', strtotime('+1 day')));
    }

    /** The lead-time rule belongs to the run, not to how it named its members. */
    public function test_store_enforces_lead_time_on_the_member_path(): void
    {
        $this->service->expects($this->never())->method('createSettlement');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements')
            ->withParsedBody([
                'member_ids' => ['member-a'],
                'execution_date' => self::tooSoon(),
            ])
            ->withAttribute('admin_user_id', 'admin-1');

        $response = $this->controller->store($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('execution_date', $this->decode($response)['messages']);
    }

    /**
     * Issue #113: the lead time is measured from the server's today, so a
     * backdated `settlement_date` in the body buys nothing.
     *
     * Before the fix the anchor was that very field, and a caller who claimed
     * the settlement dated from last month could have the bank collect
     * tomorrow — no SEPA pre-notification at all. The field is no longer part
     * of the request; sending one is ignored, exactly as it is here.
     */
    public function test_store_ignores_a_backdated_settlement_date_in_the_body(): void
    {
        $this->service->expects($this->never())->method('createSettlement');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements')
            ->withParsedBody([
                'member_ids' => ['member-a'],
                'settlement_date' => date('Y-m-d', strtotime('-1 month')),
                'execution_date' => self::tooSoon(),
            ])
            ->withAttribute('admin_user_id', 'admin-1');

        $response = $this->controller->store($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('execution_date', $this->decode($response)['messages']);
    }

    /** And the same hole on the other creation path. */
    public function test_settle_filter_ignores_a_backdated_settlement_date_in_the_body(): void
    {
        $this->service->expects($this->never())->method('createSettlementByFilters');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements/settle-filter')
            ->withParsedBody([
                'settlement_date' => date('Y-m-d', strtotime('-1 month')),
                'execution_date' => self::tooSoon(),
            ])
            ->withAttribute('admin_user_id', 'admin-1');

        $response = $this->controller->settleFilter($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('execution_date', $this->decode($response)['messages']);
    }

    /** `settlement_date` is gone from the contract, so its absence is fine. */
    public function test_store_no_longer_requires_a_settlement_date(): void
    {
        $this->service->expects($this->once())
            ->method('createSettlement')
            ->willReturn($this->createdSettlement());

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements')
            ->withParsedBody([
                'member_ids' => ['member-a'],
                'execution_date' => self::executionDate(),
            ])
            ->withAttribute('admin_user_id', 'admin-1');

        $this->assertSame(201, $this->controller->store($request, new Response())->getStatusCode());
    }
}
