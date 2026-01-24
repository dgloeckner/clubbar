<?php

namespace App\Http\Modules\Members\Services;

use App\DTOs\SyncResultDto;
use App\Http\Modules\Members\DTOs\MemberDto;
use App\Http\Modules\Members\Enums\SupportedLanguage;
use App\Http\Modules\Members\Repositories\MembersRepository;
use App\Shared\Services\BaseService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * MembersService - Business Logic for Members Module
 *
 * Handles member-specific operations:
 * - Terminal API: delta sync, language updates
 * - Admin API: CRUD operations (future)
 * - GDPR: export and anonymization (future)
 *
 * Extends BaseService (Pattern 010) for standard CRUD operations.
 * Implements Pattern 004: Service Layer with business logic isolation.
 *
 * Current State (Phase 3):
 * - Returns mock data (same as previous SyncService)
 * - Repository created but not integrated
 * - Ready for database integration in Milestone 4
 */
class MembersService extends BaseService
{
    /**
     * Initialize service with Members repository.
     *
     * Repository is injected by service provider (Pattern 008).
     *
     * @param MembersRepository $membersRepository
     */
    public function __construct(
        private readonly MembersRepository $membersRepository,
    ) {
        parent::__construct($membersRepository);
    }

    /**
     * Get members modified since timestamp (Terminal API).
     *
     * Returns delta sync result for /api/sync/members endpoint.
     * Currently returns mock data; will use repository in M4.
     *
     * @param int $since Unix timestamp (from query param)
     * @return SyncResultDto Contains members[], cursor, hasMore
     */
    public function syncSince(int $since): SyncResultDto
    {
        // Mock data (same as current SyncService::syncMembers)
        $members = [
            new MemberDto(
                id: '123e4567-e89b-12d3-a456-426614174000',
                cardUid: '04:d2:3e:5a:10:80:80',
                firstName: 'Max',
                lastName: 'Mustermann',
                preferredLanguage: 'de',
                isActive: true,
                isSepaValid: true,
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-06-15T10:00:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-20T14:23:45Z'),
            ),
            new MemberDto(
                id: '223e4567-e89b-12d3-a456-426614174001',
                cardUid: '04:d2:3e:5a:10:80:90',
                firstName: 'Anna',
                lastName: 'Schmidt',
                preferredLanguage: 'en',
                isActive: true,
                isSepaValid: true,
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-07-01T12:30:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-21T10:00:00Z'),
            ),
        ];

        return new SyncResultDto(
            items: $members,
            cursor: '2025-01-21T10:00:00Z',
            hasMore: false,
        );
    }

    /**
     * Update member's preferred language (Terminal API).
     *
     * Updates /api/sync/members/{memberId}/language endpoint.
     * Currently returns mock member; will use repository in M4.
     *
     * @param string $memberId UUID of member
     * @param SupportedLanguage $language New language preference
     * @return MemberDto Updated member data
     */
    public function updateLanguage(string $memberId, SupportedLanguage $language): MemberDto
    {
        // Mock data: find member and return with updated language
        // In M4, this will call: $this->membersRepository->updateById($memberId, ['preferred_language' => $language->value])

        return match ($memberId) {
            '123e4567-e89b-12d3-a456-426614174000' => new MemberDto(
                id: $memberId,
                cardUid: '04:d2:3e:5a:10:80:80',
                firstName: 'Max',
                lastName: 'Mustermann',
                preferredLanguage: $language->value,
                isActive: true,
                isSepaValid: true,
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-06-15T10:00:00Z'),
                updatedAt: new DateTimeImmutable(),
            ),
            '223e4567-e89b-12d3-a456-426614174001' => new MemberDto(
                id: $memberId,
                cardUid: '04:d2:3e:5a:10:80:90',
                firstName: 'Anna',
                lastName: 'Schmidt',
                preferredLanguage: $language->value,
                isActive: true,
                isSepaValid: true,
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-07-01T12:30:00Z'),
                updatedAt: new DateTimeImmutable(),
            ),
            default => throw new \Exception("Member not found: $memberId"),
        };
    }

    /**
     * Transform Model to MemberDto.
     *
     * Hook method from BaseService (Pattern 010).
     * Called by listWithPagination() and other BaseService methods.
     *
     * In M4, this will be called when repository queries database.
     * For now, not used (using mock data directly).
     *
     * @param Model $entity Member model
     * @return MemberDto
     */
    protected function transform(Model $entity): MemberDto
    {
        return new MemberDto(
            id: $entity->id,
            cardUid: $entity->card_uid,
            firstName: $entity->first_name,
            lastName: $entity->last_name,
            preferredLanguage: $entity->preferred_language,
            isActive: $entity->is_active,
            isSepaValid: $entity->is_sepa_valid,
            deletedAt: $entity->deleted_at ? new DateTimeImmutable($entity->deleted_at->format('c')) : null,
            createdAt: new DateTimeImmutable($entity->created_at->format('c')),
            updatedAt: new DateTimeImmutable($entity->updated_at->format('c')),
        );
    }

    /**
     * Apply filters to member queries.
     *
     * Hook method from BaseService (Pattern 010).
     * Called by listWithPagination() to filter results.
     *
     * Supported filters:
     * - is_active: bool - Filter by active status
     * - language: string - Filter by language preference
     *
     * @param mixed $query Eloquent query builder
     * @param array $filters Filter criteria
     * @return mixed Modified query builder
     */
    protected function applyFilters($query, array $filters)
    {
        if (isset($filters['is_active'])) {
            $query = $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['language'])) {
            $query = $query->where('preferred_language', $filters['language']);
        }

        return $query;
    }
}
