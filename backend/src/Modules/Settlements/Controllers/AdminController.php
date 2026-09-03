<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Settlements\DTOs\ReversalCandidateDto;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\Domain\SettlementLeadTime;
use App\Modules\Settlements\Domain\SettlementReference;
use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Settlements\Services\SepaExportService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use App\Shared\Time\ClubTimeZone;
use App\Shared\Utils\Csv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private SettlementsService $settlementsService,
        private SepaExportService $sepaExportService,
        private Validator $validator,
        private SettlementReversalService $reversalService,
        private EncryptionKeyService $encryptionKeyService,
        private StepUpAuthService $stepUpAuthService,
        private AuditService $auditService,
    ) {}

    /**
     * The earliest valid execution date, so the admin UI does not have to
     * reimplement the TARGET2 calendar (ADR-0009, UC-SEPA-07).
     */
    public function executionDateInfo(Request $request, Response $response): Response
    {
        return $this->json($response, $this->settlementsService->getExecutionDateInfo()->toArray());
    }

    public function preview(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        // Participants can be named directly, so a caller is told what its own
        // post would do rather than what its selection adds up to (#128).
        // `member_ids` is the New Settlement screen's language (ADR-0030);
        // `transaction_ids` is the compatibility path.
        foreach (['member_ids', 'transaction_ids'] as $field) {
            if (isset($body[$field]) && !is_array($body[$field])) {
                return $this->validationFailed($response, [$field => ["{$field} must be an array of ids"]]);
            }
        }

        $idList = static fn(?array $ids): ?array => $ids === null
            ? null
            : array_values(array_map('strval', $ids));

        $result = $this->settlementsService->previewSettlement(
            fromDate: $body['from_date'] ?? null,
            toDate: $body['to_date'] ?? null,
            memberId: $body['member_id'] ?? null,
            sepaEligibleOnly: (bool) ($body['sepa_eligible_only'] ?? false),
            transactionIds: $idList($body['transaction_ids'] ?? null),
            memberIds: $idList($body['member_ids'] ?? null),
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
            return $this->validationFailed($response, $this->validator->errors());
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
            'execution_date'  => ['required', 'date', 'business_day'],
            'date_from'       => ['date'],
            'date_to'         => ['date'],
            'member_id'       => ['string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        if ($leadTimeError = $this->validateLeadTime($body['execution_date'])) {
            return $this->validationFailed($response, $leadTimeError);
        }

        $filters = $this->extractTransactionFilters($body);

        $settlement = $this->settlementsService->createSettlementByFilters(
            filters: $filters,
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

        // A run names its participants either way (ADR-0030): `member_ids` is
        // what the New Settlement screen sends, `transaction_ids` the
        // compatibility path. Exactly one is required.
        $namesMembers = array_key_exists('member_ids', $body);

        if (!$this->validator->validate($body, [
            'member_ids'      => $namesMembers ? ['required', 'array'] : [],
            'transaction_ids' => $namesMembers ? [] : ['required', 'array'],
            'execution_date'  => ['required', 'date', 'business_day'],
            'method'          => ['in:direct_debit,bank_transfer,write_off'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $field = $namesMembers ? 'member_ids' : 'transaction_ids';
        if (empty($body[$field])) {
            return $this->validationFailed($response, [$field => ["{$field} must not be empty"]]);
        }

        if ($leadTimeError = $this->validateLeadTime($body['execution_date'])) {
            return $this->validationFailed($response, $leadTimeError);
        }

        // Ruling #163: method replaces settlement_type + manual_reason; the
        // "manual requires a reason" branch that used to live here is gone —
        // there is no free-text reason left to require.
        $method = SettlementMethod::from($body['method'] ?? SettlementMethod::DIRECT_DEBIT->value);

        $settlement = $this->settlementsService->createSettlement(
            transactionIds: $namesMembers ? [] : $body['transaction_ids'],
            executionDate: $body['execution_date'],
            periodStart: $body['period_start'] ?? null,
            periodEnd: $body['period_end'] ?? null,
            method: $method,
            notes: $body['notes'] ?? null,
            adminUserId: $adminId,
            postedMemberIds: $namesMembers
                ? array_values(array_map('strval', $body['member_ids']))
                : null,
        );

        // Read back rather than echo, so the response carries what was queued
        // to announce the run (#407). The count is the only honest thing the
        // confirmation can say at this moment — *"N announcements queued,
        // sending"*, never *"N sent"*, because nothing has been sent: the
        // scheduler is the only sender and has not run yet (ADR-0038 rule 3).
        return $this->json($response, ($this->settlementsService->getSettlement($settlement->id) ?? $settlement)->toArray(), 201);
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params, defaultPerPage: 20);

        $result = $this->settlementsService->listSettlements(
            $query->perPage,
            $query->offset,
            $params['status'] ?? null,
            $query->sortKey,
            $query->sortOrder,
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
        );

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
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

    /**
     * `DELETE /settlements/{id}/cancel` — the one cancellation route.
     *
     * The bodyless `DELETE /settlements/{id}` alias that used to sit beside
     * this one is gone (#120): it was never in the OpenAPI spec, no client
     * called it, and having two routes into the same gate meant one of them
     * could be routed past it without any test noticing.
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');
        $body = $request->getParsedBody() ?? [];

        $this->settlementsService->cancelSettlement($id, $adminId, $body['reason'] ?? null);

        // Re-read rather than echo the pre-cancellation row: the undo button
        // renders this body. cancelSettlement() throws for an unknown id, and
        // cancelling does not delete the row, so the settlement is always here.
        $settlement = $this->settlementsService->getSettlement($id);

        return $this->json($response, $settlement->toArray());
    }

    /**
     * Record that the exported file is now with the bank (#81, ruling #142) —
     * after this the settlement can only be reversed, never cancelled.
     */
    public function markSubmitted(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->settlementsService->markSubmitted($id, $adminId);

        $settlement = $this->settlementsService->getSettlement($id);
        if (!$settlement) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Settlement not found'], 404);
        }

        return $this->json($response, $settlement->toArray());
    }

    /**
     * Record that the bank clawed a collection back (#196, ruling #148).
     *
     * One mechanism at per-member granularity: `member_ids` names who came
     * back, and omitting it means every member of the settlement — the
     * whole-settlement undo, not a second code path.
     */
    public function reverse(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'reason'         => ['required', 'in:bank_return,club_error'],
            'member_ids'     => ['array'],
            // ISO 20022 caps every reference at 35 characters, and the column
            // is sized for exactly that.
            'bank_reference' => ['string', 'max:35'],
            'notes'          => ['string', 'max:1000'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $this->reversalService->reverse(
            settlementId: $id,
            memberIds: $body['member_ids'] ?? null,
            reason: ReversalReason::from($body['reason']),
            bankReference: $body['bank_reference'] ?? null,
            notes: $body['notes'] ?? null,
            adminUserId: $adminId,
        );

        // The settlement itself is the answer: its derived status, the members
        // still settled, and the reversals just recorded.
        $settlement = $this->settlementsService->getSettlement($id);
        if (!$settlement) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Settlement not found'], 404);
        }

        return $this->json($response, $settlement->toArray(), 201);
    }

    /**
     * Resolve a bank reference to the collections it could belong to (#433).
     *
     * The other half of "record a bank return": the treasurer arrives holding a
     * statement line and does not know which run it came from, so the reference
     * is what they search on. ADR-0032 §8 makes this a lookup rather than a
     * free-hand picker, and the service keeps it one — nothing but
     * `end_to_end_id` and the mandate reference is matched.
     */
    public function reversalCandidates(Request $request, Response $response): Response
    {
        $candidates = $this->reversalService->findCandidates(
            (string) ($request->getQueryParams()['reference'] ?? '')
        );

        return $this->json($response, [
            'data' => array_map(static fn(ReversalCandidateDto $c) => $c->toArray(), $candidates),
        ]);
    }

    /**
     * Build the pain.008 file — the one place the club's IBANs are legitimately
     * decrypted in bulk (ADR-0036, #393).
     *
     * The private key lives offline in the club's archive and reaches the
     * server only here, in this request body, behind a fresh step-up (own
     * password + own TOTP) on top of the session. It is validated against the
     * registered public half, used through a per-member opener, and wiped
     * before the response is written.
     *
     * The body carries the key rather than a multipart upload deliberately:
     * PHP writes multipart parts to temporary files on disk, which is exactly
     * the persistence this design exists to avoid.
     */
    public function exportSepa(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, ['private_key' => ['required', 'string']])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $caller = $request->getAttribute('admin_user');
        if ($caller === null || !$this->stepUpAuthService->verify($caller, $body, $request)) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => 'Re-enter your password (and TOTP code) to decrypt IBANs for a SEPA export',
            ], 401);
        }

        try {
            $result = $this->encryptionKeyService->withActivePrivateKey(
                (string) $body['private_key'],
                fn(callable $openIban) => $this->sepaExportService->export($id, $openIban),
            );
        } catch (\InvalidArgumentException $e) {
            // Wrong key for this club: nothing was decrypted, so this is a bad
            // input rather than a conflict with server state.
            return $this->json($response, ['error' => 'private_key_mismatch', 'message' => $e->getMessage()], 422);
        }

        // Mark settlement as exported, and put what the file actually collects
        // on the permanent record along with it (#114).
        $this->settlementsService->markExported($id, $adminId, $result->toAuditSummary());

        // The decryption itself is the auditable event, separately from the
        // settlement's own export record: how many IBANs were opened, and under
        // which settlement. Never an IBAN, never the XML (ADR-0036).
        $this->auditService->log(
            action: AuditAction::SEPA_EXPORT,
            entityType: EntityType::SETTLEMENT,
            entityId: $id,
            oldValues: null,
            newValues: [
                'collected_members' => $result->collectedMemberCount,
                'collected_amount_cents' => $result->collectedAmountCents,
            ],
            adminUserId: $adminId,
        );

        $response->getBody()->write($result->xml);
        $response = $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $result->downloadName . '"')
            // The body is a list of plaintext IBANs. It must not sit in a proxy
            // or a browser cache once the download is done (ADR-0036).
            ->withHeader('Cache-Control', 'no-store');

        // #80 / #114: a member the file leaves out carries no line to say so,
        // and the body is the bank file — it cannot carry the report. The
        // omissions ride on headers instead of vanishing. Ids only, in two
        // buckets because the remedies are opposite (ruling #141 §3): chase
        // the bank details, versus pay the member back or let it ride.
        $creditExcluded = $result->creditExcludedMembers();
        if ($creditExcluded !== []) {
            $response = $response->withHeader(
                'X-Credit-Excluded-Members',
                implode(',', array_map(fn($member) => $member->memberId, $creditExcluded))
            );
        }

        $uncollectable = $result->uncollectableMembers();
        if ($uncollectable !== []) {
            $response = $response
                ->withHeader(
                    'X-Uncollectable-Members',
                    implode(',', array_map(fn($member) => $member->memberId, $uncollectable))
                )
                // The numbers matter as much as the names: this is the gap
                // between what the books say the club is owed and what the
                // file asks the bank for.
                ->withHeader('X-Shortfall-Amount-Cents', (string) $result->shortfallAmountCents())
                ->withHeader('X-Collected-Amount-Cents', (string) $result->collectedAmountCents)
                ->withHeader('X-Settlement-Amount-Cents', (string) $result->settlementAmountCents);
        }

        return $response->withStatus(200);
    }

    public function exportCsv(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $settlement = $this->settlementsService->getSettlement($id);

        if (!$settlement) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Settlement not found'], 404);
        }

        $csvData = $this->settlementsService->getCsvData($id);
        $csv = $this->buildSettlementCsv($csvData, $settlement);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . self::downloadName('abrechnung', $settlement, 'csv') . '"')
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
        $items = array_map(self::onTheClubClock(...), $data['items'] ?? []);

        $csv = $this->buildCsv($items);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . self::downloadName('abrechnung-transaktionen', $settlement, 'csv') . '"')
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

    /**
     * Enforce the ADR-0009 lead time on both settlement creation paths.
     *
     * The business-day part of the rule is applied declaratively via the
     * `business_day` validation rule; this covers the lead time, which the
     * rule-based validator cannot express.
     *
     * Anchored on the server's today (issue #113). The anchor used to be the
     * request's own `settlement_date`, so a caller that backdated that field
     * could have the bank collect tomorrow — no SEPA pre-notification, and
     * members debited without the advance notice the rule exists to give.
     *
     * @return array<string, list<string>>|null Field messages, keyed by `execution_date`. Null when valid.
     */
    private function validateLeadTime(string $executionDate): ?array
    {
        if (SettlementLeadTime::isSatisfiedBy($executionDate)) {
            return null;
        }

        return ['execution_date' => [SettlementLeadTime::violationMessage()]];
    }

    /**
     * What the file is called in the treasurer's downloads folder.
     *
     * Date first so a folder of these sorts chronologically, then the canonical
     * reference so the file names the run it came from — previously every one
     * of them was `<kind>-<raw uuid>`, unsortable and unrecognisable.
     */
    private static function downloadName(string $kind, SettlementDto $settlement, string $extension): string
    {
        return sprintf(
            '%s-%s-%s.%s',
            $kind,
            $settlement->settlementDate,
            SettlementReference::of($settlement->id),
            $extension,
        );
    }

    /**
     * The per-member breakdown of one settlement.
     *
     * The reference is a column on every row rather than a preamble line: a
     * preamble is not CSV, and the file's own name is not something that
     * survives being pasted into a spreadsheet next to another run's.
     */
    private function buildSettlementCsv(array $memberRows, SettlementDto $settlement): string
    {
        $reference = SettlementReference::of($settlement->id);

        return Csv::build(
            ['Settlement', 'Member Name', 'Email', 'IBAN', 'Amount EUR'],
            array_map(static fn(array $row): array => [
                $reference,
                $row['name'],
                $row['email'],
                $row['iban'],
                Csv::money((int) $row['amount_cents']),
            ], $memberRows),
        );
    }


    /**
     * A settlement item with its `transaction_date` on the club's clock.
     *
     * The API ships that column as a labelled UTC instant, which is right for a
     * browser — it converts. A CSV has no browser: the column would reach the
     * Kassenwart's spreadsheet as `2026-09-02T22:30:00Z` under a heading that
     * says "date", naming the 2nd for a sale everyone present remembers buying
     * on the 3rd. The books are the club's, so the export states them in the
     * club's zone (#365), keeping the time of day rather than truncating it.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function onTheClubClock(array $item): array
    {
        $value = $item['transaction_date'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return $item;
        }

        $moment = ClubTimeZone::moment($value);
        if ($moment === null) {
            return $item;
        }

        // Assigned in place: `buildCsv()` takes its header from the first
        // row's key order, so rebuilding the array would silently move the
        // column to the front of every export.
        $item['transaction_date'] = $moment->format('Y-m-d H:i:s');

        return $item;
    }

    private function buildCsv(array $items): string
    {
        if (empty($items)) {
            return '';
        }

        return Csv::build(array_keys((array) $items[0]), $items);
    }
}
