<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\DTOs\AdminUserDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Shared\Services\AuditService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;

class AdminUsersService
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private AuditService $auditService,
        private NotificationsService $notificationsService,
    ) {}

    public function listAdminUsers(int $limit, int $offset, array $filters = []): PaginatedResultDto
    {
        $result = $this->adminUsersRepository->listPaginated($limit, $offset, $filters);
        $items = array_map(fn($row) => AdminUserDto::fromRow($row)->toArray(), $result['items']);

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function findAdminUserById(string $id): ?AdminUserDto
    {
        $row = $this->adminUsersRepository->findById($id);
        return $row ? AdminUserDto::fromRow($row) : null;
    }

    public function createAdminUser(string $email, string $displayName, string $locale, ?string $currentAdminId = null): array
    {
        $password = $this->generateRandomPassword();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $admin = $this->adminUsersRepository->create([
            'email' => $email,
            'password' => $hash,
            'display_name' => $displayName,
            'locale' => $locale,
            'is_active' => true,
        ]);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $admin['id'],
            newValues: ['email' => $email, 'display_name' => $displayName, 'password' => '[GENERATED]'],
            adminUserId: $currentAdminId,
        );

        return ['admin' => AdminUserDto::fromRow($admin), 'password' => $password];
    }

    /**
     * True when the address is already registered to some *other* admin user.
     *
     * `admin_users.email` is UNIQUE, so a duplicate that reaches the INSERT
     * comes back as a PDOException and a 500. Both write paths — the
     * admin-users endpoint and the own-profile endpoint — ask this first (#117).
     */
    public function emailTakenByAnother(string $email, ?string $excludeId = null): bool
    {
        $existing = $this->adminUsersRepository->findByEmail($email);

        return $existing !== null && $existing['id'] !== $excludeId;
    }

    /**
     * Apply a partial update.
     *
     * A body carrying `is_active` alongside `email`, `display_name` or `locale`
     * used to (de)activate and return early, silently discarding the other
     * three fields (#117). All of them are applied now.
     *
     * The activation change goes first on purpose: its guard rails — no
     * deactivating your own account, no deactivating the last active admin —
     * throw before anything is written, so a refused request leaves the record
     * exactly as it was rather than half-updated.
     */
    public function updateAdminUser(string $id, array $validated, ?string $currentAdminId = null): ?AdminUserDto
    {
        $activated = null;
        if (array_key_exists('is_active', $validated)) {
            $active = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN);

            $activated = $active
                ? $this->reactivateAdminUser($id, $currentAdminId)
                : $this->deactivateAdminUser($id, $currentAdminId ?? '');
        }

        $data = [];
        if (isset($validated['email'])) $data['email'] = $validated['email'];
        if (isset($validated['display_name'])) $data['display_name'] = $validated['display_name'];
        if (isset($validated['locale'])) $data['locale'] = $validated['locale'];

        if (empty($data)) return $activated ?? $this->findAdminUserById($id);

        // Read before the write: the old address is what the audit entry and the
        // notification are about, and it is gone the moment the UPDATE lands.
        $before = $this->adminUsersRepository->findById($id);
        $previousEmail = $before['email'] ?? null;
        $emailMoved = isset($data['email'])
            && $previousEmail !== null
            && strcasecmp($data['email'], $previousEmail) !== 0;

        $admin = $this->adminUsersRepository->updateById($id, $data);
        if (!$admin) return null;

        if ($emailMoved) {
            $this->onEmailChanged($id, $previousEmail, $data['email'], $currentAdminId);
        }

        // The email half is audited under its own action above; what is left
        // here is the ordinary profile edit.
        $remaining = $emailMoved ? array_diff_key($data, ['email' => null]) : $data;
        if (!empty($remaining)) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::ADMIN_USER,
                entityId: $id,
                newValues: $remaining,
                adminUserId: $currentAdminId,
            );
        }

        return AdminUserDto::fromRow($admin);
    }

    /**
     * The consequences of moving a login identifier: the old address is told,
     * the change is audited under its own name, and every session that
     * predates it stops working.
     *
     * The notification is best effort by construction — it is queued, not sent
     * (ADR-0038), and an install with no `mail.dsn` queues it to a transport
     * that logs and discards. None of that may block the change itself, which
     * has already been committed by the time this runs.
     */
    private function onEmailChanged(
        string $id,
        string $previousEmail,
        string $newEmail,
        ?string $currentAdminId,
    ): void {
        $this->auditService->log(
            action: AuditAction::EMAIL_CHANGED,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            oldValues: ['email' => $previousEmail],
            newValues: ['email' => $newEmail],
            adminUserId: $currentAdminId,
        );

        // Never allowed to fail the change it describes. The change is already
        // committed; a queue that will not take the notice is a smaller problem
        // than an admin told their address did not move when it did.
        try {
            // Unix seconds, not a formatted date: `forAdmin` appends a 36-char
            // UUID to build the dedup key and the column is VARCHAR(64), which
            // a 'Y-m-d H:i:s' occasion overruns.
            $this->notificationsService->notifyFormerAddress(
                adminUserId: $id,
                formerEmail: $previousEmail,
                occasion: 'changed:' . time(),
                actorAdminUserId: $currentAdminId,
            );
        } catch (\Throwable $e) {
            $this->auditService->log(
                action: AuditAction::EMAIL_CHANGED,
                entityType: EntityType::ADMIN_USER,
                entityId: $id,
                newValues: ['notification_failed' => $e->getMessage()],
                adminUserId: $currentAdminId,
            );
        }

        $this->adminUsersRepository->touchCredentialsEpoch($id);
    }

    public function deactivateAdminUser(string $id, string $currentAdminId): AdminUserDto
    {
        if ($id === $currentAdminId) {
            throw new BusinessRuleException('Cannot deactivate own account');
        }

        $activeCount = $this->adminUsersRepository->countActive();
        if ($activeCount <= 1) {
            throw new BusinessRuleException('Cannot deactivate the last active admin');
        }

        $admin = $this->adminUsersRepository->updateById($id, ['is_active' => 0]);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $id);

        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            newValues: ['is_active' => false],
            adminUserId: $currentAdminId,
        );

        return AdminUserDto::fromRow($admin);
    }

    public function reactivateAdminUser(string $id, ?string $currentAdminId = null): AdminUserDto
    {
        $admin = $this->adminUsersRepository->updateById($id, ['is_active' => 1]);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $id);

        $this->auditService->log(
            action: AuditAction::ACTIVATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            newValues: ['is_active' => true],
            adminUserId: $currentAdminId,
        );

        return AdminUserDto::fromRow($admin);
    }

    public function resetAdminPassword(string $targetAdminId, ?string $currentAdminId = null): array
    {
        $password = $this->generateRandomPassword();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $admin = $this->adminUsersRepository->updateById($targetAdminId, ['password' => $hash]);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $targetAdminId);

        $this->auditService->log(
            action: AuditAction::PASSWORD_CHANGED,
            entityType: EntityType::ADMIN_USER,
            entityId: $targetAdminId,
            newValues: ['password' => '[RESET]'],
            adminUserId: $currentAdminId,
        );

        // UC-A63 has always said a reset invalidates the target's sessions; now
        // it does. The target is someone else, so no session of the caller's is
        // affected — unless they reset their own, which the epoch handles the
        // same way as any other credential change.
        $this->adminUsersRepository->touchCredentialsEpoch($targetAdminId);

        return ['admin' => AdminUserDto::fromRow($admin), 'password' => $password];
    }

    public function verifyCurrentPassword(string $adminId, string $currentPassword): bool
    {
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin) {
            return false;
        }

        return password_verify($currentPassword, $admin['password_hash']);
    }

    public function changeOwnPassword(string $adminId, string $newPassword): void
    {
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $adminId);

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->adminUsersRepository->updateById($adminId, ['password' => $hash]);

        $this->auditService->log(
            action: AuditAction::PASSWORD_CHANGED,
            entityType: EntityType::ADMIN_USER,
            entityId: $adminId,
            newValues: ['password' => '[CHANGED]'],
            adminUserId: $adminId,
        );

        // Every other session on this account stops working. The caller keeps
        // theirs: `AuthController::changePassword` re-stamps it immediately
        // after this returns.
        $this->adminUsersRepository->touchCredentialsEpoch($adminId);
    }

    private function generateRandomPassword(int $length = 16): string
    {
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits = '0123456789';
        $specials = '!@#$%^&*';
        $all = $lower . $upper . $digits . $specials;

        // Guarantee at least one from each category
        $password = $lower[random_int(0, strlen($lower) - 1)]
            . $upper[random_int(0, strlen($upper) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)]
            . $specials[random_int(0, strlen($specials) - 1)];

        $max = strlen($all) - 1;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, $max)];
        }

        // Shuffle to avoid predictable positions
        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
