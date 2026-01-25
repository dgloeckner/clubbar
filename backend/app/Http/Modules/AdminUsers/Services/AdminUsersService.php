<?php

namespace App\Http\Modules\AdminUsers\Services;

use App\Http\Modules\AdminUsers\DTOs\AdminUserDto;
use App\Http\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Models\AdminUser;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * AdminUsersService - Business Logic for Admin User Management
 *
 * Handles admin user operations:
 * - CRUD operations (list, create, read, update, deactivate, reactivate)
 * - Password management (reset, change own password)
 * - Business rule enforcement (self-deactivation, last admin protection)
 * - Audit logging for all operations
 *
 * Implements Pattern 004: Service Layer
 * Implements Pattern 008: Service Provider Bindings
 * Implements Pattern 016: Audit Logging for all changes
 * Implements ADR-0013: Audit logging with sensitive data masking
 */
class AdminUsersService extends BaseService
{
    /**
     * Initialize service with repository and audit service.
     *
     * @param AdminUsersRepository $adminUsersRepository
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly AdminUsersRepository $adminUsersRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($adminUsersRepository);
    }

    /**
     * List admin users with filtering and pagination.
     *
     * Supports filters: status ('active'|'inactive'|'all')
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page
     * @param array $filters Optional filters: status
     * @return PaginatedResultDto Paginated admin user results
     */
    public function listAdminUsers(
        int $page = 1,
        int $perPage = 20,
        array $filters = [],
    ): PaginatedResultDto {
        // Build query with filters
        $query = $this->adminUsersRepository->filter($filters);

        // Count total before pagination
        $total = $query->count();

        // Apply pagination
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->orderBy('created_at', 'asc')
            ->get();

        // Transform to DTOs
        $adminDtos = $items->map(fn($model) => $this->transform($model))->all();

        return new PaginatedResultDto(
            items: $adminDtos,
            total: $total,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
        );
    }

    /**
     * Find admin user by ID and return as DTO.
     *
     * @param string $id Admin user UUID
     * @return AdminUserDto|null
     */
    public function findAdminUserById(string $id): ?AdminUserDto
    {
        return parent::findById($id);
    }

    /**
     * Create new admin user with generated password.
     *
     * Generates a secure 16-character password and logs audit entry.
     * Password is shown once; never retrievable after.
     *
     * @param string $email Admin email address
     * @param string $displayName Display name
     * @param string $locale Preferred language (de, en, etc.)
     * @param string|null $currentAdminId UUID of admin creating this user (for audit)
     * @return array Contains 'admin' (AdminUserDto) and 'password' (16-char plaintext, shown once)
     */
    public function createAdminUser(
        string $email,
        string $displayName,
        string $locale,
        ?string $currentAdminId = null,
    ): array {
        // Generate random 16-character password
        $plainPassword = $this->generateRandomPassword(16);

        // Create admin user
        $admin = $this->adminUsersRepository->create([
            'email' => $email,
            'password' => $plainPassword,
            'display_name' => $displayName,
            'locale' => $locale,
            'is_active' => true,
        ]);

        // Log audit entry (mask password as [GENERATED])
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $admin->id,
            oldValues: null,
            newValues: [
                'email' => $admin->email,
                'display_name' => $admin->display_name,
                'locale' => $admin->locale,
                'is_active' => $admin->is_active,
                'password' => '[GENERATED]',
            ],
            adminUserId: $currentAdminId,
        );

        return [
            'admin' => $this->transform($admin),
            'password' => $plainPassword,
        ];
    }

    /**
     * Update admin user (email, display_name, locale).
     *
     * Only updates explicitly provided fields. Logs audit entry for changes.
     *
     * @param string $id Admin user UUID
     * @param array $validated Validated attributes (email, display_name, locale)
     * @param string|null $currentAdminId UUID of admin making this change (for audit)
     * @return AdminUserDto|null Updated admin DTO or null if not found
     */
    public function updateAdminUser(
        string $id,
        array $validated,
        ?string $currentAdminId = null,
    ): ?AdminUserDto {
        // Fetch current admin before update
        $admin = $this->adminUsersRepository->findById($id);
        if (!$admin) {
            return null;
        }

        // Prepare old values for audit (only changed fields)
        $oldValues = [];
        $newValues = [];
        foreach ($validated as $field => $value) {
            if ($field === 'email') {
                $oldValues['email'] = $admin->email;
                $newValues['email'] = $value;
            } elseif ($field === 'display_name') {
                $oldValues['display_name'] = $admin->display_name;
                $newValues['display_name'] = $value;
            } elseif ($field === 'locale') {
                $oldValues['locale'] = $admin->locale;
                $newValues['locale'] = $value;
            }
        }

        // Update admin
        $updated = $this->adminUsersRepository->updateById($id, $validated);

        // Log audit entry only if changes were made
        if (!empty($oldValues)) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::ADMIN_USER,
                entityId: $id,
                oldValues: $oldValues,
                newValues: $newValues,
                adminUserId: $currentAdminId,
            );
        }

        return $updated ? $this->transform($updated) : null;
    }

    /**
     * Deactivate admin user (set is_active = false).
     *
     * Enforces business rules:
     * - Admin cannot deactivate own account
     * - Cannot deactivate last active admin
     *
     * @param string $id Admin user UUID to deactivate
     * @param string $currentAdminId UUID of admin performing this action
     * @return AdminUserDto Deactivated admin DTO
     * @throws \Exception If business rules violated
     */
    public function deactivateAdminUser(string $id, string $currentAdminId): AdminUserDto
    {
        // Rule 1: Cannot deactivate own account
        if ($id === $currentAdminId) {
            throw new \Exception('Cannot deactivate your own admin account');
        }

        // Rule 2: Cannot deactivate last active admin
        $this->ensureNotLastActiveAdmin($id);

        // Fetch admin before deactivation
        $admin = $this->adminUsersRepository->findById($id);

        // Update to inactive
        $updated = $this->adminUsersRepository->updateById($id, ['is_active' => false]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            oldValues: ['is_active' => $admin->is_active],
            newValues: ['is_active' => false],
            adminUserId: $currentAdminId,
        );

        return $this->transform($updated);
    }

    /**
     * Reactivate admin user (set is_active = true).
     *
     * @param string $id Admin user UUID to reactivate
     * @param string|null $currentAdminId UUID of admin performing this action (for audit)
     * @return AdminUserDto Reactivated admin DTO
     */
    public function reactivateAdminUser(string $id, ?string $currentAdminId = null): AdminUserDto
    {
        // Fetch admin before reactivation
        $admin = $this->adminUsersRepository->findById($id);

        // Update to active
        $updated = $this->adminUsersRepository->updateById($id, ['is_active' => true]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::ACTIVATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            oldValues: ['is_active' => $admin->is_active],
            newValues: ['is_active' => true],
            adminUserId: $currentAdminId,
        );

        return $this->transform($updated);
    }

    /**
     * Reset admin user password to new 16-character random password.
     *
     * Generates new password and invalidates all existing sessions.
     * Password is shown once; never retrievable after.
     *
     * @param string $targetAdminId Admin UUID whose password to reset
     * @param string|null $currentAdminId UUID of admin performing reset (for audit)
     * @return array Contains 'admin' (AdminUserDto) and 'password' (16-char plaintext)
     */
    public function resetAdminPassword(
        string $targetAdminId,
        ?string $currentAdminId = null,
    ): array {
        // Generate new random 16-character password
        $plainPassword = $this->generateRandomPassword(16);

        // Update admin with new password
        $updated = $this->adminUsersRepository->updateById($targetAdminId, [
            'password' => $plainPassword,
        ]);

        // Log audit entry (mask password as [RESET])
        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $targetAdminId,
            oldValues: ['password' => '[RESET]'],
            newValues: ['password' => '[RESET]'],
            adminUserId: $currentAdminId,
        );

        return [
            'admin' => $this->transform($updated),
            'password' => $plainPassword,
        ];
    }

    /**
     * Change own password with current password verification.
     *
     * Validates current password and updates to new password.
     * Current session remains valid (session regenerated).
     *
     * @param string $adminId Admin UUID changing password
     * @param string $currentPassword Current password (for verification)
     * @param string $newPassword New password (must meet complexity requirements)
     * @return void
     * @throws \Exception If current password incorrect or admin not found
     */
    public function changeOwnPassword(
        string $adminId,
        string $currentPassword,
        string $newPassword,
    ): void {
        // Fetch admin
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin) {
            throw new \Exception('Admin user not found');
        }

        // Verify current password
        if (!Hash::check($currentPassword, $admin->password_hash)) {
            throw new \Exception('Current password is incorrect');
        }

        // Update password
        $this->adminUsersRepository->updateById($adminId, ['password' => $newPassword]);

        // Log audit entry (mask as [CHANGED])
        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $adminId,
            oldValues: ['password' => '[CHANGED]'],
            newValues: ['password' => '[CHANGED]'],
            adminUserId: $adminId,
        );
    }

    /**
     * Generate cryptographically random password.
     *
     * Generates password with mix of:
     * - Uppercase letters
     * - Lowercase letters
     * - Digits
     * - Special characters (!@#$%^&*)
     *
     * @param int $length Password length (default 16)
     * @return string Random password
     */
    private function generateRandomPassword(int $length = 16): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $digits = '0123456789';
        $special = '!@#$%^&*';

        $chars = $uppercase . $lowercase . $digits . $special;
        $password = '';

        // Ensure at least one character from each category
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        // Fill remaining characters randomly
        for ($i = 4; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // Shuffle to avoid predictable patterns
        $password = str_shuffle($password);

        return $password;
    }

    /**
     * Ensure target admin is not the last active admin.
     *
     * Throws exception if deactivating would violate business rule.
     *
     * @param string $targetAdminId Admin UUID to check
     * @throws \Exception If this is the last active admin
     */
    private function ensureNotLastActiveAdmin(string $targetAdminId): void
    {
        $activeCount = $this->adminUsersRepository->countActiveAdmins();

        // If target is active and this is the only active admin, throw error
        if ($activeCount === 1) {
            $target = $this->adminUsersRepository->findById($targetAdminId);
            if ($target && $target->is_active) {
                throw new \Exception('Cannot deactivate the last active admin user');
            }
        }
    }

    /**
     * Transform AdminUser Model to AdminUserDto.
     *
     * Implements abstract method from BaseService.
     * Never exposes password_hash.
     *
     * @param \Illuminate\Database\Eloquent\Model $entity AdminUser model
     * @return AdminUserDto
     */
    protected function transform(\Illuminate\Database\Eloquent\Model $entity): AdminUserDto
    {
        /** @var AdminUser $entity */
        return new AdminUserDto(
            id: $entity->id,
            email: $entity->email,
            displayName: $entity->display_name,
            locale: $entity->locale,
            isActive: $entity->is_active,
            lastLoginAt: $entity->last_login_at,
            createdAt: $entity->created_at,
            updatedAt: $entity->updated_at,
        );
    }
}
