<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
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

        // Language filter
        if (isset($params['filters']['language'])) {
            $filters['language'] = $params['filters']['language'];
        } elseif (isset($params['language'])) {
            $filters['language'] = $params['language'];
        }

        // Card UID filter
        if (isset($params['filters']['has_card_uid'])) {
            $filters['has_card_uid'] = filter_var($params['filters']['has_card_uid'], FILTER_VALIDATE_BOOLEAN);
        }

        // SEPA status filter
        if (isset($params['filters']['sepa_status']) && in_array($params['filters']['sepa_status'], ['valid', 'missing'], true)) {
            $filters['sepa_status'] = $params['filters']['sepa_status'];
        }

        $result = $this->membersService->listMembers($limit, $offset, $filters, $sortKey, $sortOrder, $search);

        // Add has_more field to pagination
        $responseData = $result->toArray();
        if (isset($responseData['pagination'])) {
            $responseData['pagination']['has_more'] = ($offset + $limit) < $result->total;
        }

        return $this->json($response, $responseData);
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
        $member = $this->membersService->getMember($memberId);

        return $this->json($response, $member->toArray());
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
        $adminId = $request->getAttribute('admin_user_id');

        $member = $this->membersService->anonymizeMember($memberId, $adminId);

        return $this->json($response, $member->toArray());
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
