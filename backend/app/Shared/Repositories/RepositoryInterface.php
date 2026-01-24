<?php

namespace App\Shared\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Repository Interface - Data Access Abstraction
 *
 * Contract for all data repositories. Defines standard CRUD operations
 * and query building patterns to abstract away ORM details.
 *
 * Pattern 011: Shared Base Repository
 * Each module repository must implement this interface.
 *
 * @see \App\Shared\Repositories\BaseRepository for default implementation
 */
interface RepositoryInterface
{
    /**
     * Get query builder for this repository's model.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query();

    /**
     * Find single entity by primary key (UUID).
     *
     * @param string $id
     * @return Model|null
     */
    public function findById(string $id): ?Model;

    /**
     * Find multiple entities by IDs.
     *
     * @param array $ids
     * @return Collection
     */
    public function findByIds(array $ids): Collection;

    /**
     * Get all entities from repository.
     *
     * @return Collection
     */
    public function findAll(): Collection;

    /**
     * Create new entity with attributes.
     *
     * @param array $attributes
     * @return Model
     */
    public function create(array $attributes): Model;

    /**
     * Update entity by ID and return updated instance.
     *
     * @param string $id
     * @param array $attributes
     * @return Model|null
     */
    public function updateById(string $id, array $attributes): ?Model;

    /**
     * Delete entity by ID.
     *
     * @param string $id
     * @return bool
     */
    public function deleteById(string $id): bool;

    /**
     * Delete multiple entities by IDs.
     *
     * @param array $ids
     * @return int Number deleted
     */
    public function deleteByIds(array $ids): int;

    /**
     * Count total entities.
     *
     * @return int
     */
    public function count(): int;

    /**
     * Check if entity exists by ID.
     *
     * @param string $id
     * @return bool
     */
    public function exists(string $id): bool;
}
