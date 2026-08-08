<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Controllers;

use App\Modules\Transactions\Services\TransactionsService;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private TransactionsService $transactionsService,
        private Validator $validator,
    ) {}

    public function getTransactions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Accept both frontend format (page/per_page) and backend format (limit/offset)
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? $params['limit'] ?? 20);
        $limit = $perPage;
        $offset = ($page - 1) * $perPage;

        // Accept both 'sort'/'order' (frontend) and 'sort_key'/'sort_order' (backend)
        $sortKey = $params['sort'] ?? $params['sort_key'] ?? 'created_at';
        $sortOrder = $params['order'] ?? $params['sort_order'] ?? 'desc';

        $filters = [];
        if (isset($params['member_id'])) {
            $filters['member_id'] = $params['member_id'];
        }
        if (isset($params['type'])) {
            $filters['transaction_type'] = $params['type'];
        }
        if (isset($params['transaction_type'])) {
            $filters['transaction_type'] = $params['transaction_type'];
        }
        if (isset($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (isset($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }
        if (isset($params['search'])) {
            $filters['search'] = $params['search'];
        }
        if (isset($params['settlement_status']) && $params['settlement_status'] !== 'all') {
            $settlementMap = ['open' => 'unsettled', 'settled' => 'settled'];
            $filters['settlement_status'] = $settlementMap[$params['settlement_status']] ?? $params['settlement_status'];
        }

        $result = $this->transactionsService->getTransactions($limit, $offset, $filters, $sortKey, $sortOrder);

        return $this->json($response, $result->toArray());
    }

    public function getTransactionHistory(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $params = $request->getQueryParams();
        $type = $params['type'] ?? null;

        $result = $this->transactionsService->getMemberTransactionHistory($memberId, $type);

        return $this->json($response, $result);
    }

    /**
     * Storno a transaction: POST /admin/transactions/{transactionId}/storno.
     *
     * `reason` is the whole request body. The amount and the member are read
     * from the transaction named in the path and are deliberately not accepted
     * from the caller — anything else in the body is ignored rather than
     * validated, because there is no shape of it that could be honoured (#169).
     */
    public function storno(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'reason' => ['required', 'string', 'max:500'],
        ]) || trim((string) ($body['reason'] ?? '')) === '') {
            $errors = $this->validator->errors();
            $errors['reason'] ??= ['reason is required'];

            return $this->json($response, [
                'error' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $result = $this->transactionsService->storno(
            $args['transactionId'],
            trim((string) $body['reason']),
            $request->getAttribute('admin_user_id'),
        );

        return $this->json($response, $result['transaction'], 201);
    }

    public function exportTransactions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = 10000;
        $offset = 0;

        // Accept from_date/to_date as primary names (also support legacy date_from/date_to)
        $fromDate = $params['from_date'] ?? $params['date_from'] ?? null;
        $toDate = $params['to_date'] ?? $params['date_to'] ?? null;

        // Validate date formats (must be YYYY-MM-DD)
        $dateErrors = [];
        if ($fromDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $dateErrors['from_date'] = ['from_date must be a valid date in YYYY-MM-DD format'];
        }
        if ($toDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $dateErrors['to_date'] = ['to_date must be a valid date in YYYY-MM-DD format'];
        }
        if (!empty($dateErrors)) {
            return $this->json($response, ['error' => 'validation_failed', 'errors' => $dateErrors], 422);
        }

        // Validate date range (to_date must not be before from_date)
        if ($fromDate !== null && $toDate !== null && $toDate < $fromDate) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'errors' => ['to_date' => ['to_date must not be before from_date']],
            ], 422);
        }

        $filters = [];
        if (isset($params['member_id'])) {
            $filters['member_id'] = $params['member_id'];
        }
        if ($fromDate !== null) {
            $filters['date_from'] = $fromDate;
        }
        if ($toDate !== null) {
            $filters['date_to'] = $toDate;
        }
        // Support type filter (e.g. type=purchase, type=correction)
        if (isset($params['type']) && $params['type'] !== '' && $params['type'] !== 'all') {
            $filters['type'] = $params['type'];
        }

        $result = $this->transactionsService->getTransactions($limit, $offset, $filters);

        $csv = $this->buildCsv($result->items);

        // Build filename with date range when provided
        if ($fromDate !== null && $toDate !== null) {
            $filename = "transactions-{$fromDate}-to-{$toDate}.csv";
        } else {
            $filename = 'transactions-export.csv';
        }

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withStatus(200);
    }

    private function buildCsv(array $items): string
    {
        $output = fopen('php://temp', 'r+');

        // Write fixed semantic headers (semicolon-separated)
        fwrite($output, "date;member_name;product;type;amount\n");

        foreach ($items as $item) {
            $item = (array) $item;
            // Resolve product name from JSON names column (prefer 'de', fallback to first available)
            $productName = '';
            $productNames = $item['product_names'] ?? null;
            if ($productNames !== null) {
                $names = is_array($productNames) ? $productNames : (json_decode((string)$productNames, true) ?? []);
                $productName = $names['de'] ?? $names['en'] ?? reset($names) ?: '';
            }
            // For corrections with no product, use notes
            if ($productName === '' && isset($item['notes'])) {
                $productName = (string) $item['notes'];
            }

            $row = [
                'date'        => substr((string)($item['created_at'] ?? ''), 0, 10),
                'member_name' => $item['member_name'] ?? '',
                'product'     => $productName,
                'type'        => $item['transaction_type'] ?? $item['type'] ?? '',
                'amount'      => $item['amount_cents'] ?? '',
            ];

            fwrite($output, implode(';', array_map(
                static fn($v) => str_replace([';', "\n", "\r"], [',', ' ', ' '], (string)$v),
                $row
            )) . "\n");
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
