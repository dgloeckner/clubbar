<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Controllers;

use App\Modules\Transactions\Services\JugendschutzViolationService;
use App\Modules\Transactions\Services\TransactionsService;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use App\Shared\Utils\Csv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private TransactionsService $transactionsService,
        private Validator $validator,
        private JugendschutzViolationService $jugendschutzViolationService,
    ) {}

    /**
     * The violations still waiting to be looked at (#622).
     *
     * `TREASURY`, and member-free: the transaction id is the handle into the
     * journal, which a Kassenwart's role already permits.
     */
    public function getJugendschutzViolations(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'data' => $this->jugendschutzViolationService->listUnacknowledged(),
        ]);
    }

    /**
     * Mark a recorded Jugendschutz violation as dealt with (#622).
     *
     * `TREASURY`, not `ADMIN_ONLY`. The audit log that holds the record is
     * admin-only, which is precisely the gap this closes: the **Kassenwart** is
     * the office ADR-0045 names as the recipient, and an alert they can see but
     * not clear would leave the dashboard permanently red for the one person
     * most likely to be looking at it.
     *
     * No step-up gate, on the same reasoning as the terminal-anomaly
     * acknowledgement beside it: this clears a notice and hands out nothing,
     * and an alert that is awkward to dismiss is one that gets ignored.
     */
    public function acknowledgeJugendschutzViolation(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $this->validator->validate($body, ['note' => ['nullable', 'string', 'max:500']]);

        $newlyAcknowledged = $this->jugendschutzViolationService->acknowledge(
            (int) $args['id'],
            $request->getAttribute('admin_user_id'),
            // A blank note is no note. Storing '' would make "did they write
            // anything?" a question about string length at every read site.
            trim((string) ($body['note'] ?? '')) ?: null,
        );

        // 200 either way. The second admin pressing the same dashboard button
        // has not made a mistake, and answering 409 would turn the expected
        // case into an error somebody has to interpret.
        return $this->json($response, [
            'message' => 'Jugendschutz violation acknowledged',
            'newly_acknowledged' => $newlyAcknowledged,
        ]);
    }

    public function getTransactions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params, defaultPerPage: 20);

        $filters = [];
        if (isset($params['member_id'])) {
            $filters['member_id'] = $params['member_id'];
        }
        // The filter key is `type`: that is what the repository reads, and what
        // the export next to it has always written. Writing `transaction_type`
        // here meant the journal ignored the filter and returned every
        // transaction while the control read as active — so an admin reviewing,
        // say, every correction in a period saw the unfiltered list with no
        // signal, and the export of the same view disagreed with it (#110).
        //
        // `transaction_type` stays accepted as an alias for the query
        // parameter, and both `all` and the empty value mean "no filter", as in
        // the export.
        $type = $params['transaction_type'] ?? $params['type'] ?? null;
        if ($type !== null && $type !== '' && $type !== 'all') {
            $filters['type'] = $type;
        }
        if (isset($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (isset($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }
        if ($query->search !== null) {
            $filters['search'] = $query->search;
        }
        if (isset($params['settlement_status']) && $params['settlement_status'] !== 'all') {
            $settlementMap = ['open' => 'unsettled', 'settled' => 'settled'];
            $filters['settlement_status'] = $settlementMap[$params['settlement_status']] ?? $params['settlement_status'];
        }

        $result = $this->transactionsService->getTransactions(
            $query->perPage,
            $query->offset,
            $filters,
            $query->sortKey,
            $query->sortOrder,
        );

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
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

            return $this->validationFailed($response, $errors);
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
            return $this->validationFailed($response, $dateErrors);
        }

        // Validate date range (to_date must not be before from_date)
        if ($fromDate !== null && $toDate !== null && $toDate < $fromDate) {
            return $this->validationFailed($response, ['to_date' => ['to_date must not be before from_date']]);
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

    /**
     * The journal export.
     *
     * The money column used to be raw cents under a header that just said
     * "amount", while the settlement export next to it wrote euros — the same
     * figure, two units, no way to tell from the file which you had (#119).
     * Both now write euros, and this header says so.
     */
    private function buildCsv(array $items): string
    {
        $rows = [];

        foreach ($items as $item) {
            $item = (array) $item;

            $rows[] = [
                substr((string) ($item['created_at'] ?? ''), 0, 10),
                $item['member_name'] ?? '',
                $this->productLabel($item),
                $item['transaction_type'] ?? $item['type'] ?? '',
                Csv::money((int) ($item['amount_cents'] ?? 0)),
            ];
        }

        return Csv::build(['date', 'member_name', 'product', 'type', 'amount_eur'], $rows);
    }

    /**
     * Product name in German where available, then English, then whatever the
     * product has; a correction carries no product and falls back to its note.
     *
     * @param array<string, mixed> $item
     */
    private function productLabel(array $item): string
    {
        $productNames = $item['product_names'] ?? null;

        if ($productNames !== null) {
            $names = is_array($productNames)
                ? $productNames
                : (json_decode((string) $productNames, true) ?? []);

            $label = $names['de'] ?? $names['en'] ?? (reset($names) ?: '');
            if ($label !== '') {
                return (string) $label;
            }
        }

        return (string) ($item['notes'] ?? '');
    }
}
