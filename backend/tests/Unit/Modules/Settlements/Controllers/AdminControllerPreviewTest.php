<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Controllers;

use App\Modules\Settlements\Controllers\AdminController;
use App\Modules\Settlements\DTOs\SettlementPreviewDto;
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
 * The preview endpoint's `transaction_ids` route (#128).
 *
 * The Journal's settlement selection spans pages and a run sweeps each named
 * member in full, so the client cannot compute what it is about to create. It
 * posts the ids instead and the server answers for them — which only holds if
 * the controller actually forwards them.
 */
class AdminControllerPreviewTest extends TestCase
{
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
            ->createServerRequest('POST', '/api/admin/settlements/preview')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    private function emptyPreview(): SettlementPreviewDto
    {
        return new SettlementPreviewDto([], [], 0, 0, 0, []);
    }

    public function test_preview_forwards_transaction_ids_to_the_service(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, ['tx-1', 'tx-2'], null)
            ->willReturn($this->emptyPreview());

        $response = $this->controller->preview($this->post(['transaction_ids' => ['tx-1', 'tx-2']]), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    /** The window path is untouched: no ids means null, not an empty list. */
    public function test_preview_without_transaction_ids_passes_null(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with('2026-01-01', '2026-01-31', null, false, null, null)
            ->willReturn($this->emptyPreview());

        $this->controller->preview(
            $this->post(['from_date' => '2026-01-01', 'to_date' => '2026-01-31']),
            new Response(),
        );
    }

    public function test_preview_forwards_member_id_and_sepa_eligible_only(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, 'member-1', true, null, null)
            ->willReturn($this->emptyPreview());

        $this->controller->preview(
            $this->post(['member_id' => 'member-1', 'sepa_eligible_only' => true]),
            new Response(),
        );
    }

    /** An empty selection is still a selection — it must not fall back to the window. */
    public function test_preview_forwards_an_empty_transaction_ids_list(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, [], null)
            ->willReturn($this->emptyPreview());

        $this->controller->preview($this->post(['transaction_ids' => []]), new Response());
    }

    public function test_preview_rejects_a_scalar_transaction_ids(): void
    {
        $this->service->expects($this->never())->method('previewSettlement');

        $response = $this->controller->preview($this->post(['transaction_ids' => 'tx-1']), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('transaction_ids', $body['messages']);
    }

    /** Keys are dropped so the service receives a list, not a sparse map. */
    public function test_preview_reindexes_and_stringifies_the_ids(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, ['tx-1', 'tx-2'], null)
            ->willReturn($this->emptyPreview());

        $this->controller->preview($this->post(['transaction_ids' => [3 => 'tx-1', 7 => 'tx-2']]), new Response());
    }

    // ── member_ids: what the New Settlement screen sends (ADR-0030) ──

    public function test_preview_forwards_member_ids_to_the_service(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, null, ['member-a', 'member-b'])
            ->willReturn($this->emptyPreview());

        $response = $this->controller->preview($this->post(['member_ids' => ['member-a', 'member-b']]), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    /** An empty selection previews an empty run, it does not preview everything. */
    public function test_preview_forwards_an_empty_member_ids_list(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, null, [])
            ->willReturn($this->emptyPreview());

        $this->controller->preview($this->post(['member_ids' => []]), new Response());
    }

    public function test_preview_rejects_a_scalar_member_ids(): void
    {
        $this->service->expects($this->never())->method('previewSettlement');

        $response = $this->controller->preview($this->post(['member_ids' => 'member-a']), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('member_ids', $this->decode($response)['messages']);
    }

    public function test_preview_reindexes_and_stringifies_member_ids(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, null, ['member-a', 'member-b'])
            ->willReturn($this->emptyPreview());

        $this->controller->preview($this->post(['member_ids' => [2 => 'member-a', 5 => 'member-b']]), new Response());
    }

    /** Both may be sent; the service decides, and it prefers members. */
    public function test_preview_forwards_both_id_lists_when_both_are_given(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, ['tx-1'], ['member-a'])
            ->willReturn($this->emptyPreview());

        $this->controller->preview(
            $this->post(['transaction_ids' => ['tx-1'], 'member_ids' => ['member-a']]),
            new Response(),
        );
    }

    public function test_preview_answers_with_the_serialized_dto(): void
    {
        $this->service->method('previewSettlement')->willReturn(new SettlementPreviewDto(
            eligibleMembers: [['member_id' => 'm-1', 'balance_cents' => 2400]],
            ineligibleMembers: [],
            eligibleTotal: 2400,
            ineligibleTotal: 0,
            memberCount: 1,
            warnings: [],
            transactionCount: 4,
        ));

        $body = $this->decode($this->controller->preview($this->post(['transaction_ids' => ['tx-1']]), new Response()));

        // The count the confirmation renders: the swept position, not the one
        // id that was posted.
        $this->assertSame(4, $body['transaction_count']);
        $this->assertSame(2400, $body['eligible_total']);
        $this->assertSame(1, $body['member_count']);
    }

    /** A body-less POST is the documented "preview everything" call. */
    public function test_preview_tolerates_an_absent_body(): void
    {
        $this->service->expects($this->once())
            ->method('previewSettlement')
            ->with(null, null, null, false, null)
            ->willReturn($this->emptyPreview());

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/settlements/preview')
            ->withAttribute('admin_user_id', 'admin-1');

        $response = $this->controller->preview($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
    }
}
