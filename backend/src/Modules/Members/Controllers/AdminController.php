<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Shared\Validation\Validator;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Members\Services\MandateDocumentService;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    /**
     * One rule set for the member fields a client may write.
     *
     * Create and update used to disagree about which fields were checked at
     * all: `phone`, `email`, `mandate_reference` and `mandate_signed_at`
     * carried no length or format rule on either path, so an over-long phone
     * number or a malformed date reached MariaDB in strict mode and came back
     * as a PDOException — a 500 that named nothing (#117). The lengths mirror
     * the column widths in `001_initial_schema.sql` and `007_critical_remediation.sql`.
     *
     * Create adds `required` (and the `unique` card check) on top; update
     * validates only the keys the request actually carries, so a PATCH of one
     * field is not rejected for the absence of the others.
     */
    private const FIELD_RULES = [
        'first_name' => ['nullable', 'string', 'max:100'],
        'last_name' => ['nullable', 'string', 'max:100'],
        'email' => ['nullable', 'email', 'max:255'],
        'phone' => ['nullable', 'string', 'max:20'],
        'preferred_language' => ['nullable', 'string', 'in:de,en,fr'],
        'account_holder_name' => ['nullable', 'string', 'max:70'],
        'card_uid' => ['nullable', 'string', 'min:8', 'max:20', 'regex:/^[0-9A-F]+$/'],
        // `iban` bounds the compact form at 34 characters on its own; `max:34`
        // additionally rejects a value padded past the column width with the
        // spaces IBANs are conventionally printed with.
        'iban' => ['nullable', 'string', 'iban', 'max:34'],
        'mandate_reference' => ['nullable', 'string', 'max:35'],
        'mandate_signed_at' => ['nullable', 'date'],
    ];

    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
        private SepaConfigService $sepaConfigService,
        private MandateDocumentService $mandateDocumentService,
        private SettlementsService $settlementsService,
        private CollectionHoldService $collectionHoldService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params);

        // Support both filters[is_active] (nested) and is_active (direct) formats
        $filters = [];
        if (isset($params['filters']['is_active'])) {
            // Convert string "true"/"false" to boolean
            $filters['is_active'] = filter_var($params['filters']['is_active'], FILTER_VALIDATE_BOOLEAN);
        } elseif (isset($params['is_active'])) {
            // Convert string "true"/"false" to boolean
            $filters['is_active'] = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        // OAS: status=active|inactive (top-level param)
        $statusParam = $params['status'] ?? null;
        if ($statusParam === 'active') {
            $filters['is_active'] = true;
        } elseif ($statusParam === 'inactive') {
            $filters['is_active'] = false;
        }

        // Language filter
        if (isset($params['filters']['language'])) {
            $filters['language'] = $params['filters']['language'];
        } elseif (isset($params['language'])) {
            $filters['language'] = $params['language'];
        }

        // Card UID filter — OAS: has_card_uid=with|without (top-level param)
        $cardUidParam = $params['has_card_uid'] ?? $params['filters']['has_card_uid'] ?? null;
        if ($cardUidParam === 'with' || $cardUidParam === 'true') {
            $filters['has_card_uid'] = true;
        } elseif ($cardUidParam === 'without' || $cardUidParam === 'false') {
            $filters['has_card_uid'] = false;
        }

        // SEPA status filter — OAS: sepa_status=valid|invalid (top-level param)
        $sepaParam = $params['sepa_status'] ?? $params['filters']['sepa_status'] ?? null;
        if ($sepaParam !== null && in_array($sepaParam, ['valid', 'invalid', 'missing'], true)) {
            $filters['sepa_status'] = $sepaParam;
        }

        $result = $this->membersService->listMembers(
            $query->perPage,
            $query->offset,
            $filters,
            $query->sortKey,
            $query->sortOrder,
            $query->search,
        );

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        $rules = self::FIELD_RULES;
        $rules['first_name'][] = 'required';
        $rules['last_name'][] = 'required';
        $rules['email'][] = 'required';
        $rules['preferred_language'][] = 'required';
        $rules['card_uid'][] = 'unique:members,card_uid';

        if (!$this->validator->validate($body, $rules)) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $language = SupportedLanguage::from($body['preferred_language']);

        $member = $this->membersService->createMember(
            firstName: $body['first_name'],
            lastName: $body['last_name'],
            email: $body['email'],
            phone: $body['phone'] ?? null,
            cardUid: $body['card_uid'] ?? null,
            language: $language,
            iban: ($body['iban'] ?? null) ?: null,
            accountHolderName: ($body['account_holder_name'] ?? null) ?: null,
            mandateReference: $body['mandate_reference'] ?? null,
            mandateSignedAt: ($body['mandate_signed_at'] ?? null) ?: null,
            adminUserId: $adminId,
        );

        return $this->json($response, $member->toArray(), 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $member   = $this->membersService->getMember($memberId);
        $doc      = $this->mandateDocumentService->findByMemberId($memberId);

        $data                     = $member->toArray();
        $data['mandate_document'] = $doc?->toArray();

        return $this->json($response, $data);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        // Only the fields the request carries are checked — three of them used
        // to be checked one at a time in separate passes, and the other seven
        // not at all.
        $rules = array_intersect_key(self::FIELD_RULES, $body);
        if (isset($rules['card_uid'])) {
            $rules['card_uid'][] = "unique:members,card_uid,{$memberId}";
        }

        if ($rules !== [] && !$this->validator->validate($body, $rules)) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $member = $this->membersService->updateMember($memberId, $body, $adminId);

        return $this->json($response, $member->toArray());
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->membersService->deleteMember($memberId, $adminId);

        return $this->json($response, ['message' => 'Member deleted'], 200);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $exportData = $this->membersService->exportMember($memberId);

        return $this->json($response, $exportData);
    }

    public function anonymize(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $adminId  = $request->getAttribute('admin_user_id');

        // GDPR: anonymizeMember() drops the mandate document as part of the
        // same transaction — never before its eligibility checks have passed
        // (#85), so a refused attempt leaves the signed mandate intact.
        $member = $this->membersService->anonymizeMember($memberId, $adminId);

        return $this->json($response, $member->toArray());
    }

    /**
     * Standing "credit balances outstanding" listing under Members (#161
     * work item 3) — every member the club currently owes money.
     */
    public function creditBalances(Request $request, Response $response): Response
    {
        $result = $this->settlementsService->listCreditBalances();

        return $this->json($response, [
            'items' => array_map(fn($item) => $item->toArray(), $result['items']),
            'total_credit_cents' => $result['total_credit_cents'],
        ]);
    }

    /**
     * Standing "no usable mandate" listing under Members (#258) — the members
     * the next run cannot collect from at all.
     *
     * The worst of the three exclusions and, until this endpoint, the least
     * visible: a member in credit or on hold is at least an account somebody
     * is transacting with, while a member with no mandate can never be
     * collected from and was only discoverable by reading the SEPA column of
     * the members list row by row.
     */
    public function mandateMissing(Request $request, Response $response): Response
    {
        $result = $this->settlementsService->listMembersWithoutMandate();

        return $this->json($response, [
            'items' => array_map(fn($item) => $item->toArray(), $result['items']),
            'total_uncollectable_cents' => $result['total_uncollectable_cents'],
        ]);
    }

    /**
     * Standing "on collection hold" listing under Members (ruling #148 §4) —
     * every member the next run will skip, and why.
     *
     * It sits beside credit balances for the same reason that one does: an
     * exclusion nobody can see is an exclusion nobody ever resolves.
     */
    public function collectionHolds(Request $request, Response $response): Response
    {
        $result = $this->collectionHoldService->listHeld();

        return $this->json($response, [
            'items' => array_map(fn($item) => $item->toArray(), $result['items']),
            'total_held_cents' => $result['total_held_cents'],
        ]);
    }

    /** Let the next run collect from this member again (ruling #148 §5). */
    public function clearCollectionHold(Request $request, Response $response, array $args): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        $hold = $this->collectionHoldService->clearHold($args['memberId'], $adminId);

        return $this->json($response, $hold->toArray());
    }

    public function downloadMandateTemplate(Request $request, Response $response): Response
    {
        $pdf = $this->sepaConfigService->generateMandateTemplatePdf();
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="sepa-mandate-template.pdf"');
    }
}
