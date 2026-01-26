<?php

namespace App\Http\Modules\Terminals\Repositories;

use App\Models\Terminal;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * TerminalsRepository - Data Access for Terminals
 *
 * Extends BaseRepository with standard CRUD inherited methods.
 * Adds domain-specific queries for Terminal device management.
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
 * - findByDeviceId(string $deviceId): ?Terminal
 * - findActive(): Collection
 * - findAllPaginated(int $page, int $perPage, ?bool $isActive): array
 * - updateLastSync(string $terminalId): bool
 * - countActive(): int
 */
class TerminalsRepository extends BaseRepository
{
    /**
     * Initialize repository with Terminal model.
     */
    public function __construct()
    {
        parent::__construct(new Terminal());
    }

    /**
     * Find terminal by device_id.
     *
     * Device IDs should be unique (hardware serial numbers or similar).
     * Used for duplicate prevention during terminal creation.
     *
     * @param string $deviceId
     * @return Terminal|null
     */
    public function findByDeviceId(string $deviceId): ?Terminal
    {
        return $this->query()
            ->where('device_id', $deviceId)
            ->first();
    }

    /**
     * Find all active terminals.
     *
     * Active terminals are those where is_active=true.
     * Used for inventory checks and sync operations.
     *
     * @return Collection
     */
    public function findActive(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get paginated list of terminals with optional status filter.
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Items per page
     * @param bool|null $isActive Filter by active status (null = no filter)
     * @return array With keys: items, total, page, per_page, total_pages
     */
    public function findAllPaginated(int $page, int $perPage, ?bool $isActive = null): array
    {
        $query = $this->query();

        // Apply status filter if specified
        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        // Count total before pagination
        $total = $query->count();

        // Fetch paginated results
        $offset = ($page - 1) * $perPage;
        $items = $query
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $totalPages = (int) ceil($total / $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Update last_sync_at timestamp for terminal.
     *
     * Called after successful sync to track terminal connectivity.
     *
     * @param string $terminalId
     * @return bool Success indicator
     */
    public function updateLastSync(string $terminalId): bool
    {
        $updated = $this->query()
            ->where('id', $terminalId)
            ->update(['last_sync_at' => now()]);

        return $updated > 0;
    }

    /**
     * Count active terminals.
     *
     * Used for monitoring and inventory tracking.
     *
     * @return int
     */
    public function countActive(): int
    {
        return $this->query()
            ->where('is_active', true)
            ->count();
    }
}
