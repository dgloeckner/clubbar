<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Controllers;

use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Settlements\Services\SepaExportService;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private SettlementsService $settlementsService,
        private SepaExportService $sepaExportService,
        private Validator $validator,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        $result = $this->settlementsService->previewSettlement(
            fromDate: $body['from_date'] ?? null,
            toDate: $body['to_date'] ?? null,
            memberId: $body['member_id'] ?? null,
            sepaEligibleOnly: (bool) ($body['sepa_eligible_only'] ?? false),
        );

        return $this->json($response, $result->toArray());
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'transaction_ids' => ['required', 'array'],
            'settlement_date' => ['required', 'date'],
            'execution_date' => ['required', 'date'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $settlement = $this->settlementsService->createSettlement(
            transactionIds: $body['transaction_ids'],
            settlementDate: $body['settlement_date'],
            executionDate: $body['execution_date'],
            periodStart: $body['period_start'] ?? null,
            periodEnd: $body['period_end'] ?? null,
            manualReason: $body['manual_reason'] ?? null,
            notes: $body['notes'] ?? null,
            adminUserId: $adminId,
        );

        return $this->json($response, $settlement->toArray(), 201);
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);
        $status = $params['status'] ?? null;
        $sortKey = $params['sort_key'] ?? 'created_at';
        $sortOrder = $params['sort_order'] ?? 'desc';

        $result = $this->settlementsService->listSettlements($limit, $offset, $status, $sortKey, $sortOrder);

        return $this->json($response, $result->toArray());
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $settlement = $this->settlementsService->getSettlement($id);

        if (!$settlement) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Settlement not found'], 404);
        }

        return $this->json($response, $settlement->toArray());
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');
        $body = $request->getParsedBody() ?? [];

        $this->settlementsService->cancelSettlement($id, $adminId, $body['reason'] ?? null);

        return $response->withStatus(204);
    }

    public function exportSepa(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $xml = $this->sepaExportService->generateSepaXml($id);

        // Mark settlement as exported
        $this->settlementsService->markExported($id, $adminId);

        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="sepa-' . $id . '.xml"')
            ->withStatus(200);
    }

    public function exportCsv(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $settlement = $this->settlementsService->getSettlement($id);

        if (!$settlement) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Settlement not found'], 404);
        }

        $csv = $this->buildSettlementCsv($settlement->toArray());

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="settlement-' . $id . '.csv"')
            ->withStatus(200);
    }

    public function exportTransactionsCsv(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $settlement = $this->settlementsService->getSettlement($id);

        if (!$settlement) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Settlement not found'], 404);
        }

        $data = $settlement->toArray();
        $items = $data['items'] ?? [];

        $csv = $this->buildCsv($items);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="settlement-transactions-' . $id . '.csv"')
            ->withStatus(200);
    }

    private function buildSettlementCsv(array $settlement): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['id', 'settlement_date', 'execution_date', 'total_amount_cents', 'member_count', 'created_at']);
        fputcsv($output, [
            $settlement['id'] ?? '',
            $settlement['settlement_date'] ?? '',
            $settlement['execution_date'] ?? '',
            $settlement['total_amount_cents'] ?? '',
            $settlement['member_count'] ?? '',
            $settlement['created_at'] ?? '',
        ]);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    private function buildCsv(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys((array) $items[0]));
        foreach ($items as $item) {
            fputcsv($output, (array) $item);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
