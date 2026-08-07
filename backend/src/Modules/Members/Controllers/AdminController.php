<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Shared\Validation\Validator;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Members\Services\MandateDocumentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
        private SepaConfigService $sepaConfigService,
        private MandateDocumentService $mandateDocumentService,
        private SettlementsService $settlementsService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Validate limit: reject non-numeric or values exceeding 100
        $rawLimit = $params['per_page'] ?? $params['limit'] ?? null;
        if ($rawLimit !== null) {
            if (!is_numeric($rawLimit) || (int) $rawLimit != $rawLimit) {
                return $this->json($response, [
                    'error' => 'invalid_request',
                    'messages' => ['limit' => ['limit must be a positive integer']],
                ], 400);
            }
            if ((int) $rawLimit > 100) {
                return $this->json($response, [
                    'error' => 'invalid_request',
                    'messages' => ['limit' => ['limit must not exceed 100']],
                ], 400);
            }
        }

        // Support both page/per_page (frontend) and limit/offset (direct) formats
        $limit = (int) ($rawLimit ?? 50);
        $page = (int) ($params['page'] ?? 1);
        $offset = isset($params['offset']) ? (int) $params['offset'] : ($page - 1) * $limit;

        // Support both sort/order (frontend) and sort_key/sort_order (direct) formats
        $sortKey = $params['sort'] ?? $params['sort_key'] ?? 'created_at';
        $sortOrder = $params['order'] ?? $params['sort_order'] ?? 'desc';
        $search = $params['search'] ?? null;

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

        $result = $this->membersService->listMembers($limit, $offset, $filters, $sortKey, $sortOrder, $search);

        $data = $result->toArray();
        $totalPages = $limit > 0 ? (int) ceil($result->total / $limit) : 1;
        return $this->json($response, [
            'data' => $data['items'],
            'pagination' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => $result->total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'preferred_language' => ['required', 'string', 'in:de,en,fr'],
            'account_holder_name' => ['nullable', 'string', 'max:70'],
            'card_uid' => ['nullable', 'string', 'min:8', 'max:20', 'regex:/^[0-9A-F]+$/', 'unique:members,card_uid'],
            'iban' => ['nullable', 'string', 'iban'],
        ])) {
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

        // Validate card_uid if provided
        if (isset($body['card_uid'])) {
            if (!$this->validator->validate($body, [
                'card_uid' => ['nullable', 'string', 'min:8', 'max:20', 'regex:/^[0-9A-F]+$/', "unique:members,card_uid,{$memberId}"],
            ])) {
                return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
            }
        }

        // Validate preferred_language if provided
        if (isset($body['preferred_language'])) {
            if (!$this->validator->validate($body, [
                'preferred_language' => ['string', 'in:de,en,fr'],
            ])) {
                return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
            }
        }

        // Validate IBAN if provided
        if (isset($body['iban']) && $body['iban'] !== null && $body['iban'] !== '') {
            if (!$this->validator->validate($body, [
                'iban' => ['string', 'iban'],
            ])) {
                return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
            }
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

        // GDPR: delete mandate document before anonymizing member record
        $this->mandateDocumentService->deleteForMember($memberId, $adminId);

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

    public function downloadMandateTemplate(Request $request, Response $response): Response
    {
        $pdf = $this->sepaConfigService->generateMandateTemplatePdf();
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="sepa-mandate-template.pdf"');
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
