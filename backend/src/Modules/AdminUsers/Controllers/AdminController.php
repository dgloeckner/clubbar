<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private AdminUsersService $adminUsersService,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);

        $filters = [];
        if (isset($params['is_active'])) {
            $filters['is_active'] = $params['is_active'];
        }

        $result = $this->adminUsersService->listAdminUsers($limit, $offset, $filters);

        return $this->json($response, $result->toArray());
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'email' => ['required', 'email'],
            'display_name' => ['required', 'string', 'max:100'],
            'locale' => ['required', 'string', 'in:de,en,fr'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $result = $this->adminUsersService->createAdminUser(
            $body['email'],
            $body['display_name'],
            $body['locale'],
            $adminId,
        );

        return $this->json($response, [
            'admin' => $result['admin']->toArray(),
            'password' => $result['password'],
        ], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $admin = $this->adminUsersService->findAdminUserById($id);

        if (!$admin) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        $admin = $this->adminUsersService->updateAdminUser($id, $body, $adminId);

        if (!$admin) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $admin = $this->adminUsersService->deactivateAdminUser($id, $adminId);

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    public function reactivate(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $admin = $this->adminUsersService->reactivateAdminUser($id, $adminId);

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $result = $this->adminUsersService->resetAdminPassword($id, $adminId);

        return $this->json($response, [
            'admin' => $result['admin']->toArray(),
            'password' => $result['password'],
        ]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
