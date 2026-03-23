<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\TotpService;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Services\AuditService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Validation\Validator;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function __construct(
        private AuthService $authService,
        private AdminUsersService $adminUsersService,
        private AdminUsersRepository $adminUsersRepository,
        private TotpService $totpService,
        private AuditService $auditService,
        private Validator $validator,
        private PDO $pdo,
    ) {}

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $admin = $this->authService->authenticate($body['email'], $body['password']);

        if (!$admin) {
            // Record failed login attempt for rate limiting
            $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (ip_address, email) VALUES (:ip, :email)');
            $stmt->execute(['ip' => $ip, 'email' => $body['email']]);

            $this->auditService->log(
                action: AuditAction::LOGIN_FAILED,
                entityType: EntityType::ADMIN_USER,
                entityId: $body['email'],
            );
            return $this->json($response, ['error' => 'invalid_credentials', 'message' => 'Invalid credentials'], 401);
        }

        // Clear rate limit counter on successful password verification
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip');
        $stmt->execute(['ip' => $ip]);

        // Start session and regenerate ID to prevent session fixation
        if (session_status() === PHP_SESSION_NONE) {
            session_name('_session');
            session_start();
        }
        session_regenerate_id(true);

        // Branch 1: TOTP enrolled — issue MFA-pending session, do not authenticate yet
        if ((int) ($admin['totp_enabled'] ?? 0) === 1) {
            $_SESSION['mfa_pending_user_id'] = $admin['id'];
            $_SESSION['mfa_pending_expires'] = time() + 300; // 5 minutes TTL
            unset($_SESSION['admin_user_id'], $_SESSION['csrf_token'], $_SESSION['totp_setup_required']);

            return $this->json($response, ['requiresMfa' => true]);
        }

        // Branch 2: TOTP not enrolled — full session with mandatory setup gate
        $_SESSION['admin_user_id'] = $admin['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['totp_setup_required'] = true;

        $this->auditService->log(
            action: AuditAction::LOGIN,
            entityType: EntityType::ADMIN_USER,
            entityId: $admin['id'],
            adminUserId: $admin['id'],
        );

        return $this->json($response, [
            'requiresTotpSetup' => true,
            'admin' => $this->formatAdmin($admin),
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    /**
     * POST /api/auth/mfa (public)
     * Exchange a valid MFA-pending session + TOTP code for a fully authenticated session.
     */
    public function mfa(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('_session');
            session_start();
        }

        $pendingUserId = $_SESSION['mfa_pending_user_id'] ?? null;
        $pendingExpires = $_SESSION['mfa_pending_expires'] ?? 0;

        if (!$pendingUserId || time() > $pendingExpires) {
            return $this->json($response, ['error' => 'invalid_credentials', 'message' => 'MFA session expired or not found'], 401);
        }

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $admin = $this->adminUsersRepository->findById($pendingUserId);
        if (!$admin || !(bool) $admin['is_active']) {
            return $this->json($response, ['error' => 'invalid_credentials', 'message' => 'Invalid credentials'], 401);
        }

        $encryptedSecret = $admin['totp_secret'] ?? null;
        if (!$encryptedSecret) {
            return $this->json($response, ['error' => 'invalid_credentials', 'message' => 'TOTP not configured'], 401);
        }

        $secret = $this->totpService->decrypt($encryptedSecret);
        if ($secret === false || !$this->totpService->verifyCode($secret, $body['code'])) {
            // Do not destroy MFA-pending session — user may retry within TTL
            return $this->json($response, ['error' => 'invalid_credentials', 'message' => 'Invalid TOTP code'], 401);
        }

        // Upgrade to fully authenticated session
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $admin['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_expires'], $_SESSION['totp_setup_required']);

        $this->adminUsersRepository->updateById($admin['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        $this->auditService->log(
            action: AuditAction::LOGIN,
            entityType: EntityType::ADMIN_USER,
            entityId: $admin['id'],
            adminUserId: $admin['id'],
        );

        return $this->json($response, [
            'message' => 'Login successful',
            'admin' => $this->formatAdmin($admin, true),
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    /**
     * POST /api/auth/2fa/setup
     * Begin TOTP enrollment for the current user. Returns QR code + plain-text secret.
     * Accessible even when totp_setup_required is set in session.
     */
    public function setup2fa(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        if (!$adminId) {
            return $this->json($response, ['error' => 'admin_not_authenticated'], 401);
        }

        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin) {
            return $this->json($response, ['error' => 'admin_not_authenticated'], 401);
        }

        $secret = $this->totpService->generateSecret();
        $_SESSION['totp_pending_secret'] = $secret;

        $qrCode = $this->totpService->getQrCodeUri($secret, $admin['email']);

        return $this->json($response, [
            'qrCode' => $qrCode,
            'secret' => $secret,
        ]);
    }

    /**
     * POST /api/auth/2fa/confirm
     * Confirm TOTP enrollment by verifying the first code.
     * On success: saves encrypted secret, removes setup gate.
     */
    public function confirm2fa(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        if (!$adminId) {
            return $this->json($response, ['error' => 'admin_not_authenticated'], 401);
        }

        $pendingSecret = $_SESSION['totp_pending_secret'] ?? null;
        if (!$pendingSecret) {
            return $this->json($response, ['error' => 'setup_not_started', 'message' => 'No pending TOTP setup. Call /api/auth/2fa/setup first.'], 400);
        }

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        if (!$this->totpService->verifyCode($pendingSecret, $body['code'])) {
            return $this->json($response, ['error' => 'invalid_code', 'message' => 'Invalid TOTP code'], 400);
        }

        $encryptedSecret = $this->totpService->encrypt($pendingSecret);
        $this->adminUsersRepository->saveTotp($adminId, $encryptedSecret);

        unset($_SESSION['totp_pending_secret'], $_SESSION['totp_setup_required']);

        $this->auditService->log(
            action: AuditAction::TOTP_ENROLLED,
            entityType: EntityType::ADMIN_USER,
            entityId: $adminId,
            adminUserId: $adminId,
        );

        return $this->json($response, ['message' => 'Two-factor authentication enabled']);
    }

    /**
     * POST /api/auth/2fa/reset
     * Remove TOTP from any admin user account. Any logged-in admin may call this.
     */
    public function reset2fa(Request $request, Response $response): Response
    {
        $callerAdminId = $request->getAttribute('admin_user_id');
        if (!$callerAdminId) {
            return $this->json($response, ['error' => 'admin_not_authenticated'], 401);
        }

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'userId' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $target = $this->adminUsersRepository->findById($body['userId']);
        if (!$target) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        $this->adminUsersRepository->clearTotp($body['userId']);

        $this->auditService->log(
            action: AuditAction::TOTP_RESET,
            entityType: EntityType::ADMIN_USER,
            entityId: $body['userId'],
            adminUserId: $callerAdminId,
        );

        return $this->json($response, ['message' => 'Two-factor authentication reset']);
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

        return $this->json($response, ['message' => 'Logout successful']);
    }

    public function profile(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');

        if (!$adminId) {
            return $this->json($response, ['error' => 'admin_not_authenticated'], 401);
        }

        $admin = $this->authService->getActiveAdmin($adminId);
        if (!$admin) {
            return $this->json($response, ['error' => 'admin_not_authenticated'], 401);
        }

        return $this->json($response, [
            'admin' => $this->formatAdmin($admin),
            'csrf_token' => $_SESSION['csrf_token'] ?? null,
        ]);
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        if (!$adminId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'email' => ['nullable', 'email'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'in:de,en'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $admin = $this->adminUsersService->updateAdminUser($adminId, $body, $adminId);

        if (!$admin) {
            return $this->json($response, ['error' => 'update_failed', 'message' => 'Failed to update profile'], 500);
        }

        return $this->json($response, [
            'message' => 'Profile updated',
            'admin' => $admin->toArray(),
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
            'new_password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'],
            'new_password_confirmation' => ['required', 'same:new_password'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        if (!$this->adminUsersService->verifyCurrentPassword($adminId, $body['current_password'])) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => 'Current password is incorrect',
            ], 401);
        }

        $this->adminUsersService->changeOwnPassword($adminId, $body['new_password']);

        return $this->json($response, ['message' => 'Password changed']);
    }

    private function formatAdmin(array $admin, bool $totpEnabled = false): array
    {
        return [
            'id' => $admin['id'],
            'email' => $admin['email'],
            'display_name' => $admin['display_name'],
            'locale' => $admin['locale'] ?? 'de',
            'last_login_at' => $admin['last_login_at'] ?? null,
            'totp_enabled' => $totpEnabled || (bool) ($admin['totp_enabled'] ?? false),
        ];
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
