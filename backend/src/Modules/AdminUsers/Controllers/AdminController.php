<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private AdminUsersService $adminUsersService,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params, defaultPerPage: 20);

        $filters = [];
        if (isset($params['status'])) {
            $filters['status'] = $params['status']; // 'active' or 'inactive'
        } elseif (isset($params['filters']['is_active'])) {
            $isActive = filter_var($params['filters']['is_active'], FILTER_VALIDATE_BOOLEAN);
            $filters['status'] = $isActive ? 'active' : 'inactive';
        }

        $result = $this->adminUsersService->listAdminUsers($query->perPage, $query->offset, $filters);

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
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

        // Check for duplicate email
        if ($this->adminUsersService->emailTakenByAnother($body['email'])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => ['email' => ['Email already exists']]
            ], 422);
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

        // Add validation
        if (!$this->validator->validate($body, [
            'email' => ['nullable', 'email'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'in:de,en,fr'],
            'is_active' => ['nullable', 'boolean'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        // Check email uniqueness if provided
        if (isset($body['email']) && $this->adminUsersService->emailTakenByAnother($body['email'], $id)) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => ['email' => ['Email already exists']]
            ], 422);
        }

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
}
