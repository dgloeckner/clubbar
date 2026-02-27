<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\DTOs\AdminUserDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Services\AuditService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;

class AdminUsersService
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private AuditService $auditService,
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

    public function updateAdminUser(string $id, array $validated, ?string $currentAdminId = null): ?AdminUserDto
    {
        $data = [];
        if (isset($validated['email'])) $data['email'] = $validated['email'];
        if (isset($validated['display_name'])) $data['display_name'] = $validated['display_name'];
        if (isset($validated['locale'])) $data['locale'] = $validated['locale'];

        if (empty($data)) return $this->findAdminUserById($id);

        $admin = $this->adminUsersRepository->updateById($id, $data);
        if (!$admin) return null;

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            newValues: $data,
            adminUserId: $currentAdminId,
        );

        return AdminUserDto::fromRow($admin);
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
            action: AuditAction::UPDATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $targetAdminId,
            newValues: ['password' => '[RESET]'],
            adminUserId: $currentAdminId,
        );

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
            action: AuditAction::UPDATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $adminId,
            newValues: ['password' => '[CHANGED]'],
            adminUserId: $adminId,
        );
    }

    private function generateRandomPassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }
}
