<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Shared\Services\AuditService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function __construct(
        private AuthService $authService,
        private AdminUsersService $adminUsersService,
        private AuditService $auditService,
        private Validator $validator,
    ) {}

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $admin = $this->authService->authenticate($body['email'], $body['password']);

        if (!$admin) {
            $this->auditService->log(
                action: AuditAction::LOGIN_FAILED,
                entityType: EntityType::ADMIN_USER,
                entityId: $body['email'],
            );
            return $this->json($response, ['error' => 'Invalid credentials'], 401);
        }

        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['admin_user_id'] = $admin['id'];

        $this->auditService->log(
            action: AuditAction::LOGIN,
            entityType: EntityType::ADMIN_USER,
            entityId: $admin['id'],
            adminUserId: $admin['id'],
        );

        return $this->json($response, [
            'id' => $admin['id'],
            'email' => $admin['email'],
            'display_name' => $admin['display_name'],
            'locale' => $admin['locale'] ?? 'de',
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');

        if ($adminId) {
            $this->auditService->log(
                action: AuditAction::LOGOUT,
                entityType: EntityType::ADMIN_USER,
                entityId: $adminId,
                adminUserId: $adminId,
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return $this->json($response, ['message' => 'Logged out']);
    }

    public function profile(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');

        if (!$adminId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }

        $admin = $this->authService->getActiveAdmin($adminId);
        if (!$admin) {
            return $this->json($response, ['error' => 'Admin not found or inactive'], 401);
        }

        return $this->json($response, [
            'id' => $admin['id'],
            'email' => $admin['email'],
            'display_name' => $admin['display_name'],
            'locale' => $admin['locale'] ?? 'de',
        ]);
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        if (!$adminId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $this->adminUsersService->changeOwnPassword($adminId, $body['current_password'], $body['new_password']);

        return $this->json($response, ['message' => 'Password changed']);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
