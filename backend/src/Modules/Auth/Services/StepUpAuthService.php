<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Http\ClientIp;
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
        private AdminUsersRepository $adminUsersRepository,
        /** @see \App\Shared\Config\AppConfig::$trustedProxies */
        private string $trustedProxies = '',
        private bool $replayProtectionDisabled = false,
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

    /**
     * Verifies the code AND enforces the same single-use-per-timestep guard
     * as the login path (#338, #882): step-up shares `totp_last_timestep`
     * with `AuthController::mfa()`, so a code stays refused here once it has
     * been consumed by either path. A step-up performed immediately after
     * login must therefore wait for the next timestep — the correct
     * behaviour for a replay guard, not a bug.
     *
     * $replayProtectionDisabled (DISABLE_TOTP_REPLAY_PROTECTION, test
     * environments only — see ServiceFactory) restores the pre-#882 check
     * with no timestep bookkeeping at all: the E2E suite runs almost every
     * step-up-gated spec through one seeded admin's TOTP secret
     * (fixtures/stepUp.ts), so two specs, or two workers, presenting the
     * same real-time code within the same ~30s window is a fixture
     * collision, not a replay. The guard itself stays covered regardless, in
     * StepUpAuthServiceTest.
     */
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
        if ($secret === false) {
            return false;
        }

        if ($this->replayProtectionDisabled) {
            return $this->totpService->verifyCode($secret, $code);
        }

        $matchedTimestep = $this->totpService->verifyCodeWithTimestep($secret, $code);
        if ($matchedTimestep === null) {
            return false;
        }

        $lastTimestep = ($caller['totp_last_timestep'] ?? null) !== null ? (int) $caller['totp_last_timestep'] : null;
        if ($lastTimestep !== null && $matchedTimestep <= $lastTimestep) {
            return false;
        }

        $this->adminUsersRepository->updateTotpLastTimestep($caller['id'], $matchedTimestep);

        return true;
    }

    private function recordFailure(array $caller, Request $request): void
    {
        $ip = ClientIp::resolve($request->getServerParams(), $this->trustedProxies) ?: '127.0.0.1';
        $this->loginAttempts->record($ip, $caller['email']);

        $this->auditService->log(
            action: AuditAction::LOGIN_FAILED,
            entityType: EntityType::ADMIN_USER,
            entityId: $caller['id'],
            newValues: ['attempted_email' => $caller['email'], 'context' => 'step_up_reauth'],
            adminUserId: $caller['id'],
        );
    }
}
