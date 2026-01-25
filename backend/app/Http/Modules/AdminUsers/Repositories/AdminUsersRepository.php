<?php

namespace App\Http\Modules\AdminUsers\Repositories;

use App\Models\AdminUser;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * AdminUsersRepository - Data Access for Admin Users
 *
 * Extends BaseRepository with standard CRUD inherited methods.
 * Adds domain-specific queries for AdminUsers.
 *
 * Pattern 005: Repository Interface
 * Pattern 011: Shared Base Repository (inherits all CRUD operations)
 *
 * Inherited Methods from BaseRepository:
 * - findById(string $id): ?Model
 * - findByIds(array $ids): Collection
 * - findAll(): Collection
 * - create(array $attributes): Model
 * - updateById(string $id, array $attributes): ?Model
 * - deleteById(string $id): bool
 * - count(): int
 * - exists(string $id): bool
 *
 * Module-Specific Methods:
 * - findByEmail(string $email): ?AdminUser
 * - countActiveAdmins(): int
 * - findActiveAdmins(): Collection
 */
class AdminUsersRepository extends BaseRepository
{
    /**
     * Initialize repository with AdminUser model.
     */
    public function __construct()
    {
        parent::__construct(new AdminUser());
    }

    /**
     * Find admin user by email.
     *
     * Used for login validation and uniqueness checks.
     *
     * @param string $email
     * @return AdminUser|null
     */
    public function findByEmail(string $email): ?AdminUser
    {
        return $this->query()
            ->where('email', $email)
            ->first();
    }

    /**
     * Count active admin users.
     *
     * Used to enforce minimum active admin requirement (at least 1 must remain active).
     *
     * @return int
     */
    public function countActiveAdmins(): int
    {
        return $this->query()
            ->where('is_active', true)
            ->count();
    }

    /**
     * Find all active admin users.
     *
     * Used for listing active admins and audit purposes.
     *
     * @return Collection Active admin users
     */
    public function findActiveAdmins(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Filter admin users with multiple criteria.
     *
     * Supports filtering by status.
     * Used for admin user list with filters.
     *
     * @param array $filters Filters: status ('active'|'inactive'|'all')
     * @return \Illuminate\Database\Eloquent\Builder Query builder for pagination/sorting
     */
    public function filter(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->query();

        // Filter by status
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return $query;
    }
}
