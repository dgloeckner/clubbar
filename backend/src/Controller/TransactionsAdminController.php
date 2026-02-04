<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TransactionsService;
use App\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TransactionsAdminController
{
    public function __construct(
        private TransactionsService $transactionsService,
        private Validator $validator,
    ) {}

    public function getTransactions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);
        $sortKey = $params['sort_key'] ?? 'created_at';
        $sortOrder = $params['sort_order'] ?? 'desc';

        $filters = [];
        if (isset($params['member_id'])) {
            $filters['member_id'] = $params['member_id'];
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

    public function recordCorrection(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'amount_cents' => ['required', 'integer'],
            'reason' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $result = $this->transactionsService->recordCorrection(
            $memberId,
            (int) $body['amount_cents'],
            $body['reason'],
            $adminId,
        );

        return $this->json($response, $result, 201);
    }

    public function exportTransactions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = 10000;
        $offset = 0;

        $filters = [];
        if (isset($params['member_id'])) {
            $filters['member_id'] = $params['member_id'];
        }
        if (isset($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (isset($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }

        $result = $this->transactionsService->getTransactions($limit, $offset, $filters);

        $csv = $this->buildCsv($result->items);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="transactions-export.csv"')
            ->withStatus(200);
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
