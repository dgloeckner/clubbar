<?php

namespace App\Shared\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * BaseRepository - Abstract CRUD Implementation
 *
 * Default implementation of RepositoryInterface with standard Eloquent patterns.
 * All module repositories extend this class.
 *
 * Pattern 011: Shared Base Repository
 *
 * Design:
 * - Abstract class enforces constructor pattern
 * - Child repositories inject specific Model class
 * - Standard CRUD operations implemented using Eloquent Query Builder
 * - Methods return typed Model/Collection for IDE support
 * - Error handling: null returns instead of exceptions
 *
 * Example Usage in Child Repository:
 * ```php
 * class MembersRepository extends BaseRepository
 * {
 *     public function __construct()
 *     {
 *         parent::__construct(new Member());
 *     }
 *
 *     // Add domain-specific queries
 *     public function findModifiedSince(int $timestamp): Collection
 *     {
 *         return $this->query()
 *             ->where('updated_at', '>=', $timestamp)
 *             ->orderBy('updated_at', 'desc')
 *             ->get();
 *     }
 * }
 * ```
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * The model instance for this repository.
     *
     * @var Model
     */
    protected Model $model;

    /**
     * Initialize repository with model.
     *
     * @param Model $model The Eloquent model instance
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Get query builder for the model.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        return $this->model->query();
    }

    /**
     * Find entity by primary key (UUID).
     *
     * @param string $id
     * @return Model|null
     */
    public function findById(string $id): ?Model
    {
        return $this->query()->find($id);
    }

    /**
     * Find multiple entities by IDs.
     *
     * @param array $ids
     * @return Collection
     */
    public function findByIds(array $ids): Collection
    {
        return $this->query()->whereIn($this->model->getKeyName(), $ids)->get();
    }

    /**
     * Get all entities.
     *
     * @return Collection
     */
    public function findAll(): Collection
    {
        return $this->query()->get();
    }

    /**
     * Create new entity.
     *
     * @param array $attributes
     * @return Model
     */
    public function create(array $attributes): Model
    {
        return $this->model->create($attributes);
    }

    /**
     * Update entity by ID.
     *
     * @param string $id
     * @param array $attributes
     * @return Model|null The updated model with fresh data from database
     */
    public function updateById(string $id, array $attributes): ?Model
    {
        $entity = $this->findById($id);

        if (!$entity) {
            return null;
        }

        $entity->update($attributes);
        return $entity->fresh();
    }

    /**
     * Delete entity by ID.
     *
     * @param string $id
     * @return bool True if deleted, false if not found
     */
    public function deleteById(string $id): bool
    {
        $entity = $this->findById($id);

        if (!$entity) {
            return false;
        }

        return $entity->delete();
    }

    /**
     * Delete multiple entities by IDs.
     *
     * @param array $ids
     * @return int Number of deleted rows
     */
    public function deleteByIds(array $ids): int
    {
        return $this->query()->whereIn($this->model->getKeyName(), $ids)->delete();
    }

    /**
     * Count all entities.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Check if entity exists by ID.
     *
     * @param string $id
     * @return bool
     */
    public function exists(string $id): bool
    {
        return $this->query()->where($this->model->getKeyName(), $id)->exists();
    }
}
