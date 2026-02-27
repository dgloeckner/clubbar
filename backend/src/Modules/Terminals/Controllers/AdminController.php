<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Controllers;

use App\Modules\Terminals\Services\TerminalsService;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private TerminalsService $terminalsService,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Support page/per_page (frontend) format
        $perPage = (int) ($params['per_page'] ?? $params['limit'] ?? 50);
        $perPage = min($perPage, 100);
        $page = (int) ($params['page'] ?? 1);
        $offset = ($page - 1) * $perPage;

        // Support is_active filter with proper string-to-bool conversion
        $isActive = null;
        if (isset($params['is_active'])) {
            $isActive = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $result = $this->terminalsService->listTerminals($perPage, $offset, $isActive);

        $lastPage = $perPage > 0 ? (int) ceil($result->total / $perPage) : 1;

        return $this->json($response, [
            'data' => $result->items,
            'pagination' => [
                'total' => $result->total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, $lastPage),
            ],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'name' => ['required', 'string', 'max:100'],
            'device_id' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        try {
            $result = $this->terminalsService->createTerminal($body['name'], $body['device_id'], $adminId);
        } catch (DuplicateResourceException) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => ['device_id' => ['Device ID already exists']],
            ], 422);
        }

        // Return api_token at top level (not inside terminal object)
        $terminalData = $result['terminal']->toArray();
        unset($terminalData['api_token']);

        return $this->json($response, [
            'terminal' => $terminalData,
            'api_token' => $result['plaintext_token'],
            'message' => 'Terminal created successfully. The API token will not be shown again.',
        ], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $terminal = $this->terminalsService->getTerminal($id);

        return $this->json($response, ['terminal' => $terminal->toArray()]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        // Require at least one updatable field
        if (!isset($body['name']) && !isset($body['is_active'])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => ['_base' => ['At least one field (name, is_active) must be provided']],
            ], 422);
        }

        if (!$this->validator->validate($body, [
            'name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $terminal = $this->terminalsService->updateTerminal(
            $id,
            $body['name'] ?? null,
            isset($body['is_active']) ? filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) : null,
            $adminId,
        );

        return $this->json($response, ['terminal' => $terminal->toArray()]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->terminalsService->deleteTerminal($id, $adminId);

        return $this->json($response, ['message' => 'Terminal deactivated']);
    }

    public function rotateToken(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $result = $this->terminalsService->rotateToken($id, $adminId);

        // Return api_token at top level (not inside terminal object)
        $terminalData = $result['terminal']->toArray();
        unset($terminalData['api_token']);

        return $this->json($response, [
            'terminal' => $terminalData,
            'api_token' => $result['plaintext_token'],
            'message' => 'Token rotated successfully. The new API token will not be shown again.',
        ]);
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->terminalsService->revokeAccess($id, $adminId);

        return $this->json($response, ['message' => 'Terminal access revoked']);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
