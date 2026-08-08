<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Controllers;

use App\Modules\Settlements\Controllers\AdminController;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Services\SepaExportService;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `POST /api/admin/settlements/{id}/reverse` — the HTTP edge of #196.
 *
 * The controller decides nothing about reversal itself; what it owns is the
 * request contract, and these cases pin it: the reason is required and closed,
 * the references are bounded by what ISO 20022 and the columns accept, and the
 * body that comes back is the settlement with its recomputed status.
 */
class AdminControllerReverseTest extends TestCase
{
    private SettlementsService $settlementsService;
    private SettlementReversalService $reversalService;
    private AdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementsService = $this->createMock(SettlementsService::class);
        $this->reversalService = $this->createMock(SettlementReversalService::class);

        $this->controller = new AdminController(
            $this->settlementsService,
            $this->createMock(SepaExportService::class),
            new Validator($this->createMock(\PDO::class)),
            $this->reversalService,
        );
    }

    public function test_a_bank_return_reaches_the_service_and_answers_with_the_settlement(): void
    {
        $this->settlementsService->method('getSettlement')->willReturn($this->settlement());

        $this->reversalService->expects($this->once())
            ->method('reverse')
            ->with('s-1', ['member-1'], ReversalReason::BANK_RETURN, 'RET-1', 'Returned unpaid', 'admin-1')
            ->willReturn([]);

        $response = $this->controller->reverse(
            $this->post([
                'reason' => 'bank_return',
                'member_ids' => ['member-1'],
                'bank_reference' => 'RET-1',
                'notes' => 'Returned unpaid',
            ]),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('s-1', $this->decode($response)['id']);
    }

    public function test_omitting_the_member_list_passes_null_through_as_every_member(): void
    {
        $this->settlementsService->method('getSettlement')->willReturn($this->settlement());

        $this->reversalService->expects($this->once())
            ->method('reverse')
            ->with('s-1', null, ReversalReason::CLUB_ERROR, null, null, 'admin-1')
            ->willReturn([]);

        $response = $this->controller->reverse(
            $this->post(['reason' => 'club_error']),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_a_missing_reason_is_a_422(): void
    {
        $this->reversalService->expects($this->never())->method('reverse');

        $response = $this->controller->reverse($this->post([]), new Response(), ['id' => 's-1']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('reason', $this->decode($response)['messages']);
    }

    public function test_a_reason_outside_the_column_enum_is_a_422(): void
    {
        // 'insufficient_funds' is exactly the kind of value German return
        // handling invites and the schema does not carry — AM04 and friends
        // are suppressed and collapse into "sonstige Gründe".
        $this->reversalService->expects($this->never())->method('reverse');

        $response = $this->controller->reverse(
            $this->post(['reason' => 'insufficient_funds']),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_a_bank_reference_longer_than_iso20022_allows_is_a_422(): void
    {
        $this->reversalService->expects($this->never())->method('reverse');

        $response = $this->controller->reverse(
            $this->post(['reason' => 'bank_return', 'bank_reference' => str_repeat('X', 36)]),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('bank_reference', $this->decode($response)['messages']);
    }

    public function test_a_bank_reference_of_exactly_thirty_five_characters_is_accepted(): void
    {
        $this->settlementsService->method('getSettlement')->willReturn($this->settlement());
        $this->reversalService->expects($this->once())->method('reverse')->willReturn([]);

        $response = $this->controller->reverse(
            $this->post(['reason' => 'bank_return', 'bank_reference' => str_repeat('X', 35)]),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_notes_longer_than_the_column_are_a_422(): void
    {
        $this->reversalService->expects($this->never())->method('reverse');

        $response = $this->controller->reverse(
            $this->post(['reason' => 'club_error', 'notes' => str_repeat('n', 1001)]),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_member_ids_must_be_an_array(): void
    {
        $this->reversalService->expects($this->never())->method('reverse');

        $response = $this->controller->reverse(
            $this->post(['reason' => 'bank_return', 'member_ids' => 'member-1']),
            new Response(),
            ['id' => 's-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements/s-1/reverse')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** SettlementDto is final and cannot be doubled. */
    private function settlement(): SettlementDto
    {
        return new SettlementDto(
            id: 's-1',
            method: SettlementMethod::DIRECT_DEBIT,
            settlementDate: '2026-01-01',
            executionDate: '2026-01-10',
            periodStart: null,
            periodEnd: null,
            sepaMessageId: null,
            totalAmountCents: 1500,
            memberCount: 1,
            isCancelled: false,
            cancelledAt: null,
            exportedAt: null,
            notes: null,
            items: [],
            createdAt: '2026-01-01 10:00:00',
            createdByAdminId: null,
            createdByAdminName: null,
        );
    }

    /** @return array<string, mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }
}
