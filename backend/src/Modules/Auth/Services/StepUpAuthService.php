<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Step-up re-authentication for cross-account admin actions that would
 * otherwise need nothing beyond an active session (#337): resetting another
 * admin's 2FA, and resetting another admin's password. The caller re-proves
 * it is really them — their own password, and their own fresh TOTP code if
 * they have 2FA enabled — at the moment of the sensitive action. Checking
 * the *target's* credentials would prove nothing about who is making the
 * request, which is why this always verifies the caller.
 *
 * A failed step-up is audited and recorded against the same 5/15min login
 * rate limiter used for the password and MFA steps (ruling #145), keyed on
 * the caller so the credential can't be brute-forced.
 */
class StepUpAuthService
{
    public function __construct(
        private AdminUsersService $adminUsersService,
        private TotpService $totpService,
        private AuditService $auditService,
        private LoginAttemptsRepository $loginAttempts,
    ) {}

    /**
     * @param array $caller Full admin_users row of the authenticated caller
     *  (as attached to the request by AdminSessionAuth), not the target.
     * @param array $body Parsed request body; reads `current_password` and,
     *  when the caller has TOTP enabled, `totp_code`.
     */
    public function verify(array $caller, array $body, Request $request): bool
    {
        $passwordOk = $this->adminUsersService->verifyCurrentPassword(
            $caller['id'],
            (string) ($body['current_password'] ?? ''),
        );

        $totpOk = true;
        if ((int) ($caller['totp_enabled'] ?? 0) === 1) {
            $totpOk = $passwordOk && $this->verifyOwnTotpCode($caller, (string) ($body['totp_code'] ?? ''));
        }

        if ($passwordOk && $totpOk) {
            return true;
        }

        $this->recordFailure($caller, $request);

        return false;
    }

    private function verifyOwnTotpCode(array $caller, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $encryptedSecret = $caller['totp_secret'] ?? null;
        if (!$encryptedSecret) {
            return false;
        }

        $secret = $this->totpService->decrypt($encryptedSecret);

        return $secret !== false && $this->totpService->verifyCode($secret, $code);
    }

    private function recordFailure(array $caller, Request $request): void
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->loginAttempts->record($ip, $caller['email']);

        $this->auditService->log(
            action: AuditAction::LOGIN_FAILED,
            entityType: EntityType::ADMIN_USER,
            entityId: $caller['id'],
            adminUserId: $caller['id'],
        );
    }
}
