<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Domain\SessionTimeout;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Auth\Services\TotpService;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Config\AppConfig;
use App\Shared\Services\AuditService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    use JsonResponder;

    /**
     * Failed TOTP codes tolerated within one MFA-pending session before it is
     * destroyed and the password must be entered again (ruling #145).
     */
    public const MFA_MAX_ATTEMPTS = 5;

    public function __construct(
        private AuthService $authService,
        private AdminUsersService $adminUsersService,
        private AdminUsersRepository $adminUsersRepository,
        private TotpService $totpService,
        private AuditService $auditService,
        private Validator $validator,
        private LoginAttemptsRepository $loginAttempts,
        private AppConfig $config,
        private StepUpAuthService $stepUpAuthService,
    ) {}

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $admin = $this->authService->authenticate($body['email'], $body['password']);

        if (!$admin) {
            // Record failed login attempt against both rate-limit dimensions
            $this->loginAttempts->record($this->clientIp($request), $body['email']);

            $this->auditService->log(
                action: AuditAction::LOGIN_FAILED,
                entityType: EntityType::ADMIN_USER,
                entityId: $body['email'],
                newValues: ['attempted_email' => $body['email']],
            );
            return $this->json($response, ['error' => 'invalid_credentials', 'message' => 'Invalid credentials'], 401);
        }

        // The attempt counter is NOT cleared here. A correct password is only half
        // an authentication; clearing it before the second factor let an attacker
        // mint a fresh MFA window on demand and brute-force TOTP forever (#78).

        // Start session and regenerate ID to prevent session fixation
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->config->sessionCookieName);
            session_start();
        }
        session_regenerate_id(true);

        // Branch 1: TOTP enrolled — issue MFA-pending session, do not authenticate yet
        if ((int) ($admin['totp_enabled'] ?? 0) === 1) {
            $_SESSION['mfa_pending_user_id'] = $admin['id'];
            // Kept alongside the id so the MFA rate limiter can resolve the account
            // under attack before the controller runs.
            $_SESSION['mfa_pending_email'] = $admin['email'];
            $_SESSION['mfa_pending_expires'] = time() + 300; // 5 minutes TTL
            $_SESSION['mfa_failed_attempts'] = 0;
            unset($_SESSION['admin_user_id'], $_SESSION['csrf_token'], $_SESSION['totp_setup_required']);

            return $this->json($response, ['requiresMfa' => true]);
        }

        // Branch 2: TOTP not enrolled — full session with mandatory setup gate.
        // This is a completed authentication (there is no second factor to pass),
        // so the account's attempt rows are forgiven here.
        $this->loginAttempts->clearForEmail($admin['email']);

        $_SESSION['admin_user_id'] = $admin['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['totp_setup_required'] = true;
        SessionTimeout::begin($_SESSION);

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
     * POST /api/auth/mfa (public, rate-limited)
     * Exchange a valid MFA-pending session + TOTP code for a fully authenticated session.
     *
     * Guessing is bounded on two levels (ruling #145): the pending session dies
     * after MFA_MAX_ATTEMPTS bad codes, and every bad code is also written to
     * login_attempts. The second is what closes the re-mint loop — a counter that
     * outlives the session an attacker can throw away and re-create at will.
     */
    public function mfa(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->config->sessionCookieName);
            session_start();
        }

        $pendingUserId = $_SESSION['mfa_pending_user_id'] ?? null;
        $pendingExpires = $_SESSION['mfa_pending_expires'] ?? 0;

        if (!$pendingUserId || time() > $pendingExpires) {
            return $this->json($response, ['error' => 'mfa_session_expired', 'message' => 'MFA session expired or not found'], 401);
        }

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
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
        $matchedTimestep = $secret !== false ? $this->totpService->verifyCodeWithTimestep($secret, $body['code']) : null;

        if ($matchedTimestep === null) {
            return $this->rejectMfaCode($request, $response, $admin);
        }

        // Replay protection (#338): a code stays structurally valid for its whole
        // ±1 window, so refuse one whose time-step was already consumed — whether
        // that is the exact code being resubmitted or clock skew replaying an
        // earlier one in the window. Goes through the same rejectMfaCode() path as
        // a wrong code: a replay is a real credential-guessing signal, so it counts
        // against both the rate limiter (ruling #145) and the pending session's
        // MFA_MAX_ATTEMPTS cap exactly the same way.
        $lastTimestep = ($admin['totp_last_timestep'] ?? null) !== null ? (int) $admin['totp_last_timestep'] : null;
        if ($lastTimestep !== null && $matchedTimestep <= $lastTimestep) {
            return $this->rejectMfaCode($request, $response, $admin);
        }

        $this->adminUsersRepository->updateTotpLastTimestep($admin['id'], $matchedTimestep);

        // Upgrade to fully authenticated session
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $admin['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset(
            $_SESSION['mfa_pending_user_id'],
            $_SESSION['mfa_pending_email'],
            $_SESSION['mfa_pending_expires'],
            $_SESSION['mfa_failed_attempts'],
            $_SESSION['totp_setup_required'],
        );
        SessionTimeout::begin($_SESSION);

        // Full authentication reached — only now are this account's attempts forgiven,
        // and only this account's: rows for other admins on the same IP keep counting.
        $this->loginAttempts->clearForEmail($admin['email']);

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
     * A wrong TOTP code: record it on both rate-limit dimensions, audit it, and
     * count it against the pending session's allowance.
     *
     * Below the cap the session survives so a user who fat-fingered a digit can
     * retry within the TTL. At the cap the pending state is destroyed and the
     * password must be entered again — which now runs into the rate limiter,
     * because the failures were persisted rather than thrown away with the session.
     */
    private function rejectMfaCode(Request $request, Response $response, array $admin): Response
    {
        $this->loginAttempts->record($this->clientIp($request), $admin['email']);

        $this->auditService->log(
            action: AuditAction::LOGIN_FAILED,
            entityType: EntityType::ADMIN_USER,
            entityId: $admin['id'],
            newValues: ['attempted_email' => $admin['email']],
        );

        $failures = ((int) ($_SESSION['mfa_failed_attempts'] ?? 0)) + 1;
        $_SESSION['mfa_failed_attempts'] = $failures;

        if ($failures >= self::MFA_MAX_ATTEMPTS) {
            unset(
                $_SESSION['mfa_pending_user_id'],
                $_SESSION['mfa_pending_email'],
                $_SESSION['mfa_pending_expires'],
                $_SESSION['mfa_failed_attempts'],
            );

            return $this->json($response, [
                'error' => 'mfa_attempts_exceeded',
                'message' => 'Too many invalid codes. Please sign in again.',
            ], 401);
        }

        return $this->json($response, ['error' => 'invalid_credentials', 'message' => 'Invalid TOTP code'], 401);
    }

    private function clientIp(Request $request): string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
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
            return $this->validationFailed($response, $this->validator->errors());
        }

        $matchedTimestep = $this->totpService->verifyCodeWithTimestep($pendingSecret, $body['code']);
        if ($matchedTimestep === null) {
            return $this->json($response, ['error' => 'invalid_code', 'message' => 'Invalid TOTP code'], 400);
        }

        // Same replay guard as mfa() (#338), for completeness — low value here since
        // the secret being enrolled is brand new, but a prior enrollment attempt on
        // this account could in principle have recorded a time-step already.
        $admin = $this->adminUsersRepository->findById($adminId);
        $lastTimestep = ($admin['totp_last_timestep'] ?? null) !== null ? (int) $admin['totp_last_timestep'] : null;
        if ($lastTimestep !== null && $matchedTimestep <= $lastTimestep) {
            return $this->json($response, ['error' => 'invalid_code', 'message' => 'Invalid TOTP code'], 400);
        }

        $encryptedSecret = $this->totpService->encrypt($pendingSecret);
        $this->adminUsersRepository->saveTotp($adminId, $encryptedSecret, $matchedTimestep);

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
     * Remove TOTP from any admin user account. Any logged-in admin may call
     * this, but only after re-proving their own identity — a session alone
     * is not enough to strip 2FA off a peer account (#337).
     */
    public function reset2fa(Request $request, Response $response): Response
    {
        $callerAdminId = $request->getAttribute('admin_user_id');
        if (!$callerAdminId) {
            return $this->json($response, ['error' => 'admin_not_authenticated'], 401);
        }
        $caller = $request->getAttribute('admin_user');

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'userId' => ['required', 'string'],
            'current_password' => ['required', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        if (!$this->stepUpAuthService->verify($caller, $body, $request)) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => 'Re-enter your password to reset two-factor authentication',
            ], 401);
        }

        $target = $this->adminUsersRepository->findById($body['userId']);
        if (!$target) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        $this->adminUsersRepository->clearTotp($body['userId']);

        // ADR-0026 left the target's sessions alive here, reasoning that ending
        // them "would require server-side session enumeration". It does not —
        // the credentials epoch ends them with one comparison — and leaving
        // them alive was the weaker half of the reset: stripping 2FA off an
        // account whose active session keeps working protects nobody.
        $this->adminUsersRepository->touchCredentialsEpoch($body['userId']);

        // Resetting your own 2FA is allowed, and would otherwise end the very
        // session performing the reset before it could enroll again.
        if ($body['userId'] === $callerAdminId) {
            $this->refreshOwnSession();
        }

        $this->auditService->log(
            action: AuditAction::TOTP_RESET,
            entityType: EntityType::ADMIN_USER,
            entityId: $body['userId'],
            newValues: ['target_email' => $target['email'] ?? null],
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

        // Roles ride along here and nowhere else in the auth responses: this is
        // the endpoint the panel calls to find out what to render (ADR-0044 —
        // hidden navigation, per-role landing page). It is not a permission
        // check. The server refuses independently on every request, because a
        // client that has been told its own roles is still a client.
        return $this->json($response, [
            'admin' => $this->formatAdmin($admin) + [
                'roles' => AdminRole::toValues($this->adminUsersService->getRoles($adminId)),
            ],
            'csrf_token' => $_SESSION['csrf_token'] ?? null,
        ]);
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        if (!$adminId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }
        $caller = $request->getAttribute('admin_user');

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'email' => ['nullable', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'in:de,en'],
            'current_password' => ['nullable', 'string'],
            'totp_code' => ['nullable', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        // Same guard the admin-users endpoint applies: `admin_users.email` is
        // UNIQUE, and without this a duplicate reached the constraint and came
        // back as a 500 instead of a 422 naming the field (#117).
        if (isset($body['email']) && $this->adminUsersService->emailTakenByAnother($body['email'], $adminId)) {
            return $this->validationFailed($response, ['email' => ['Email already exists']]);
        }

        // Changing the email changes the login identifier, so it is gated by the
        // same step-up the cross-account resets carry (#337). The gate is
        // conditional on the address actually changing, and that condition is
        // load-bearing: the profile page PATCHes this endpoint on every language
        // toggle, and an unconditional gate would demand a password to switch
        // locale. Display name and locale stay free.
        if ($this->emailIsChanging($body, $caller)) {
            // No caller row means there is nobody to re-prove, so there is
            // nothing to step up: refuse rather than let a null reach the
            // verifier. `AdminSessionAuth` always attaches it, which is exactly
            // why its absence must not be treated as permission.
            if (!is_array($caller) || !$this->stepUpAuthService->verify($caller, $body, $request)) {
                return $this->json($response, [
                    'error' => 'invalid_credentials',
                    'message' => 'Re-enter your password to change your email address',
                ], 401);
            }
        }

        $emailChanged = $this->emailIsChanging($body, $caller);

        $admin = $this->adminUsersService->updateAdminUser($adminId, $body, $adminId);

        if (!$admin) {
            return $this->json($response, ['error' => 'update_failed', 'message' => 'Failed to update profile'], 500);
        }

        // An email change advances this account's epoch, which would otherwise
        // refuse the session that just made the change.
        if ($emailChanged) {
            $this->refreshOwnSession();
        }

        return $this->json($response, [
            'message' => 'Profile updated',
            'admin' => $admin->toArray(),
        ]);
    }

    /**
     * Carry the acting session across a credential change of its own account.
     *
     * The epoch refuses every session authenticated at or before the change,
     * and this session is one of them — so it is re-stamped past it. The session
     * ID is rotated at the same time, for the reason ADR-0025 gives about login:
     * a credential just changed, and an ID that predates the change should not
     * outlive it either.
     */
    private function refreshOwnSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(true);
        SessionTimeout::beginAfterCredentialChange($_SESSION);
    }

    /**
     * Is this request actually moving the login identifier?
     *
     * Compared case-insensitively because `admin_users.email` is UNIQUE under a
     * `_ci` collation — re-submitting the same address in different case is not
     * a change the database would record, so it must not trip the step-up.
     */
    private function emailIsChanging(array $body, ?array $caller): bool
    {
        if (!isset($body['email'])) {
            return false;
        }

        return strcasecmp((string) $body['email'], (string) ($caller['email'] ?? '')) !== 0;
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        if (!$adminId) {
            return $this->json($response, ['error' => 'Not authenticated'], 401);
        }
        $caller = $request->getAttribute('admin_user');

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'],
            'new_password_confirmation' => ['required', 'same:new_password'],
            'totp_code' => ['nullable', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        // Step-up rather than a bare `verifyCurrentPassword`: the password is the
        // credential an attacker holding a hijacked session is most likely to
        // rotate, and re-proving it alone is what a stolen session already
        // implies. `verify()` checks `current_password` itself and adds the
        // caller's own fresh TOTP code, matching the gate on the cross-account
        // resets — so it replaces the old check rather than joining it.
        if (!is_array($caller) || !$this->stepUpAuthService->verify($caller, $body, $request)) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => 'Current password or two-factor code is incorrect',
            ], 401);
        }

        $this->adminUsersService->changeOwnPassword($adminId, $body['new_password']);
        $this->refreshOwnSession();

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
}
