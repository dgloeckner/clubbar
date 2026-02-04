<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TerminalsService;
use App\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TerminalsController
{
    public function __construct(
        private TerminalsService $terminalsService,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);
        $isActive = isset($params['is_active']) ? (bool) $params['is_active'] : null;

        $result = $this->terminalsService->listTerminals($limit, $offset, $isActive);

        return $this->json($response, $result->toArray());
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'name' => ['required', 'string', 'max:100'],
            'device_id' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $result = $this->terminalsService->createTerminal($body['name'], $body['device_id'], $adminId);

        return $this->json($response, [
            'terminal' => $result['terminal']->toArray(),
            'plaintext_token' => $result['plaintext_token'],
        ], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $terminal = $this->terminalsService->getTerminal($id);

        return $this->json($response, $terminal->toArray());
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        $terminal = $this->terminalsService->updateTerminal(
            $id,
            $body['name'] ?? null,
            isset($body['is_active']) ? (bool) $body['is_active'] : null,
            $adminId,
        );

        return $this->json($response, $terminal->toArray());
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

        return $this->json($response, [
            'terminal' => $result['terminal']->toArray(),
            'plaintext_token' => $result['plaintext_token'],
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
