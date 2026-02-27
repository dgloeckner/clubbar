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

    public function filterPreview(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Validate optional filter params
        if (!$this->validator->validate($params, [
            'date_from'  => ['date'],
            'date_to'    => ['date'],
            'member_id'  => ['string'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $filters = $this->extractTransactionFilters($params);

        $result = $this->settlementsService->previewByFilters($filters);

        return $this->json($response, $result);
    }

    public function settleFilter(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'settlement_date' => ['required', 'date'],
            'execution_date'  => ['required', 'date'],
            'date_from'       => ['date'],
            'date_to'         => ['date'],
            'member_id'       => ['string'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $filters = $this->extractTransactionFilters($body);

        $settlement = $this->settlementsService->createSettlementByFilters(
            filters: $filters,
            settlementDate: $body['settlement_date'],
            executionDate: $body['execution_date'],
            adminUserId: $adminId,
            notes: $body['notes'] ?? null,
        );

        return $this->json($response, $settlement->toArray(), 201);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'transaction_ids' => ['required', 'array'],
            'settlement_date' => ['required', 'date'],
            'execution_date'  => ['required', 'date'],
            'settlement_type' => ['required', 'in:sepa,manual'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        if (empty($body['transaction_ids'])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => ['transaction_ids' => ['transaction_ids must not be empty']],
            ], 422);
        }

        if (isset($body['settlement_date']) && isset($body['execution_date'])) {
            $settleDate = new \DateTime($body['settlement_date']);
            $execDate = new \DateTime($body['execution_date']);
            $minExecDate = (clone $settleDate)->modify('+7 days');
            if ($execDate < $minExecDate) {
                return $this->json($response, [
                    'error' => 'validation_failed',
                    'messages' => ['execution_date' => ['execution_date must be at least 7 days after settlement_date']],
                ], 422);
            }
        }

        if (($body['settlement_type'] ?? '') === 'manual' && empty($body['manual_reason'])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => ['manual_reason' => ['manual_reason is required for manual settlement type']],
            ], 422);
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

        // Accept both frontend format (page/per_page) and backend format (limit/offset)
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? $params['limit'] ?? 20);
        $limit = $perPage;
        $offset = ($page - 1) * $perPage;

        $status = $params['status'] ?? null;

        // Accept both 'sort'/'order' (frontend) and 'sort_key'/'sort_order' (backend)
        $sortKey = $params['sort'] ?? $params['sort_key'] ?? 'created_at';
        $sortOrder = $params['order'] ?? $params['sort_order'] ?? 'desc';

        // Date filters
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        $result = $this->settlementsService->listSettlements($limit, $offset, $status, $sortKey, $sortOrder, $dateFrom, $dateTo);

        $data = $result->toArray();
        return $this->json($response, [
            'data' => $data['items'],
            'pagination' => [
                'total' => $data['total'],
                'per_page' => $perPage,
                'current_page' => $page,
            ],
        ]);
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

    /**
     * Extract transaction filter parameters from a parameter array.
     * Accepted keys: date_from, date_to, search, member_id
     *
     * @param array<string,mixed> $source  Query params or request body
     * @return array{ date_from?: string, date_to?: string, search?: string, member_id?: string }
     */
    private function extractTransactionFilters(array $source): array
    {
        $filters = [];
        if (isset($source['date_from'])) $filters['date_from'] = $source['date_from'];
        if (isset($source['date_to']))   $filters['date_to']   = $source['date_to'];
        if (isset($source['search']))    $filters['search']    = $source['search'];
        if (isset($source['member_id'])) $filters['member_id'] = $source['member_id'];
        return $filters;
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
