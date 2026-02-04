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
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);

        // Validate limit
        if ($limit > 100) {
            return $this->json($response, [
                'error' => 'invalid_request',
                'messages' => ['limit' => ['Limit cannot exceed 100']]
            ], 400);
        }

        if (isset($params['limit']) && !is_numeric($params['limit'])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => ['limit' => ['Limit must be numeric']]
            ], 400);
        }

        $sortKey = $params['sort_key'] ?? 'created_at';
        $sortOrder = $params['sort_order'] ?? 'desc';
        $search = $params['search'] ?? null;

        $filters = [];
        if (isset($params['is_active'])) {
            $filters['is_active'] = $params['is_active'];
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
            iban: $body['iban'] ?? null,
            accountHolderName: $body['account_holder_name'] ?? null,
            mandateReference: $body['mandate_reference'] ?? null,
            mandateSignedAt: $body['mandate_signed_at'] ?? null,
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
