<?php

namespace App\Shared\Services;

use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * BaseService - Abstract CRUD Service Layer
 *
 * Implements common business logic patterns for all modules.
 * Modules extend this class to add domain-specific methods.
 *
 * Pattern 010: Shared Base Service Layer
 * Pattern 004: Service Layer
 *
 * Key Responsibilities:
 * - CRUD operations delegated to repository
 * - DTO transformation (entity → DTO)
 * - Error handling (not found, validation)
 * - Common business logic (pagination, filtering)
 *
 * Hook Methods (Override in child services):
 * - transform(Model $entity): mixed - Convert model to DTO
 * - applyFilters(Builder $query, array $filters): Builder - Apply custom filters
 * - transformCollection(Collection $entities): array - Transform multiple models
 */
abstract class BaseService
{
    /**
     * Initialize service with repository (injected by service provider).
     *
     * @param BaseRepository $repository The data access layer
     */
    public function __construct(
        protected readonly BaseRepository $repository,
    ) {}

    /**
     * List entities with pagination and filtering.
     *
     * Common pattern for:
     * - Terminal API: Sync entities modified since timestamp
     * - Admin API: List paginated with filters
     *
     * @param int $limit Records per page
     * @param int $offset Starting position
     * @param array $filters Optional filter criteria
     * @param ?int $since Optional Unix timestamp for delta sync
     * @return PaginatedResultDto
     */
    public function listWithPagination(
        int $limit = 50,
        int $offset = 0,
        array $filters = [],
        ?int $since = null,
    ): PaginatedResultDto {
        // Build base query
        $query = $this->repository->query();

        // Apply timestamp filter for delta sync
        if ($since !== null) {
            $since_datetime = date('Y-m-d H:i:s', $since);
            $query = $query->where('updated_at', '>=', $since_datetime);
        }

        // Apply custom filters (delegated to child class)
        $query = $this->applyFilters($query, $filters);

        // Count total before pagination
        $total = $query->count();

        // Apply pagination
        $items = $query
            ->offset($offset)
            ->limit($limit)
            ->orderBy('updated_at', 'asc')
            ->get();

        // Transform items to DTOs
        $transformed = $this->transformCollection($items);

        return new PaginatedResultDto(
            items: $transformed,
            total: $total,
            limit: $limit,
            offset: $offset,
        );
    }

    /**
     * Find single entity by ID and transform to DTO.
     *
     * @param string $id Entity UUID
     * @return mixed DTO or null
     */
    public function findById(string $id)
    {
        $entity = $this->repository->findById($id);
        return $entity ? $this->transform($entity) : null;
    }

    /**
     * Create new entity.
     *
     * @param array $attributes Validated attributes
     * @return mixed DTO of created entity
     */
    public function create(array $attributes)
    {
        $entity = $this->repository->create($attributes);
        return $this->transform($entity);
    }

    /**
     * Update entity by ID.
     *
     * @param string $id Entity UUID
     * @param array $attributes Validated attributes to update
     * @return mixed DTO of updated entity or null if not found
     */
    public function update(string $id, array $attributes)
    {
        $entity = $this->repository->updateById($id, $attributes);
        return $entity ? $this->transform($entity) : null;
    }

    /**
     * Delete entity by ID.
     *
     * @param string $id Entity UUID
     * @return bool Success
     */
    public function delete(string $id): bool
    {
        return $this->repository->deleteById($id);
    }

    /**
     * Convert Model to DTO.
     *
     * **OVERRIDE IN CHILD CLASS**
     *
     * Example:
     * ```php
     * protected function transform(Model $entity): MemberDto
     * {
     *     return new MemberDto(
     *         id: $entity->id,
     *         firstName: $entity->first_name,
     *         // ...
     *     );
     * }
     * ```
     *
     * @param Model $entity
     * @return mixed DTO instance
     */
    abstract protected function transform(Model $entity): mixed;

    /**
     * Apply custom filters to query.
     *
     * **OVERRIDE IN CHILD CLASS**
     *
     * Example:
     * ```php
     * protected function applyFilters($query, array $filters)
     * {
     *     if (isset($filters['is_active'])) {
     *         $query = $query->where('is_active', $filters['is_active']);
     *     }
     *     return $query;
     * }
     * ```
     *
     * @param mixed $query Eloquent query builder
     * @param array $filters Filter criteria
     * @return mixed Modified query builder
     */
    protected function applyFilters($query, array $filters)
    {
        // Default: no filters
        return $query;
    }

    /**
     * Transform collection of Models to array of DTOs.
     *
     * **OPTIONAL OVERRIDE IN CHILD CLASS**
     *
     * Default implementation calls transform() on each item.
     * Override if you need batch transformation or eager loading optimization.
     *
     * @param Collection $entities
     * @return array Array of DTOs
     */
    protected function transformCollection(Collection $entities): array
    {
        return $entities->map(fn($entity) => $this->transform($entity))->toArray();
    }
}
