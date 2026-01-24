<?php

namespace App\Http\Modules\Members\Services;

use App\DTOs\SyncResultDto;
use App\Http\Modules\Members\DTOs\MemberAdminDto;
use App\Http\Modules\Members\DTOs\MemberDto;
use App\Http\Modules\Members\Enums\SupportedLanguage;
use App\Http\Modules\Members\Repositories\MembersRepository;
use App\Shared\DTOs\PaginatedResultDto;
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
     * List members with pagination and filters (Admin API).
     *
     * Supports pagination (limit, offset) and filtering (is_active, language).
     * Uses admin DTO for extended field visibility.
     *
     * @param int $limit Items per page
     * @param int $offset Starting position
     * @param array $filters Filter criteria
     * @return PaginatedResultDto Members with pagination metadata
     */
    public function listMembers(int $limit, int $offset, array $filters = []): PaginatedResultDto
    {
        // Mock data: return paginated mock members
        // In M4, this will call: $this->membersRepository->query()->paginate(...)

        $allMembers = [
            $this->getMockMemberForAdmin('123e4567-e89b-12d3-a456-426614174000'),
            $this->getMockMemberForAdmin('223e4567-e89b-12d3-a456-426614174001'),
        ];

        // Apply filters
        if (isset($filters['is_active'])) {
            $allMembers = array_filter($allMembers, fn($m) => $m->isActive === $filters['is_active']);
        }

        if (isset($filters['language'])) {
            $allMembers = array_filter($allMembers, fn($m) => $m->preferredLanguage === $filters['language']);
        }

        // Apply pagination
        $items = array_slice(
            array_values($allMembers),
            $offset,
            $limit
        );

        return new PaginatedResultDto(
            items: array_map(fn($m) => $m->toArray(), $items),
            total: count($allMembers),
            limit: $limit,
            offset: $offset,
        );
    }

    /**
     * Get single member by ID (Admin API).
     *
     * Returns member with extended admin fields.
     *
     * @param string $memberId UUID of member
     * @return MemberAdminDto Member data with admin fields
     */
    public function getMember(string $memberId): MemberAdminDto
    {
        // Mock data: return mock member with admin fields
        // In M4, this will call: $this->membersRepository->findById($memberId)

        return match ($memberId) {
            '123e4567-e89b-12d3-a456-426614174000' => $this->getMockMemberForAdmin('123e4567-e89b-12d3-a456-426614174000'),
            '223e4567-e89b-12d3-a456-426614174001' => $this->getMockMemberForAdmin('223e4567-e89b-12d3-a456-426614174001'),
            default => throw new \Exception("Member not found: $memberId"),
        };
    }

    /**
     * Create a new member (Admin API).
     *
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param string|null $phone
     * @param string|null $cardUid
     * @param SupportedLanguage $language
     * @return MemberAdminDto Created member
     */
    public function createMember(
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        ?string $cardUid,
        SupportedLanguage $language,
    ): MemberAdminDto {
        // Mock data: return newly created member
        // In M4, this will call: $this->membersRepository->create([...])

        $id = 'new-id-' . uniqid();

        return new MemberAdminDto(
            id: $id,
            cardUid: $cardUid,
            firstName: $firstName,
            lastName: $lastName,
            email: $email,
            phone: $phone,
            preferredLanguage: $language->value,
            isActive: true,
            isSepaValid: false,
            ibanMasked: null,
            deletedAt: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Update an existing member (Admin API).
     *
     * @param string $memberId UUID of member
     * @param array $updateData Fields to update
     * @return MemberAdminDto Updated member
     */
    public function updateMember(string $memberId, array $updateData): MemberAdminDto
    {
        // Mock data: return updated member
        // In M4, this will call: $this->membersRepository->updateById($memberId, $updateData)

        $member = $this->getMember($memberId);

        return new MemberAdminDto(
            id: $member->id,
            cardUid: $updateData['cardUid'] ?? $member->cardUid,
            firstName: $updateData['firstName'] ?? $member->firstName,
            lastName: $updateData['lastName'] ?? $member->lastName,
            email: $updateData['email'] ?? $member->email,
            phone: $updateData['phone'] ?? $member->phone,
            preferredLanguage: $updateData['preferredLanguage'] ?? $member->preferredLanguage,
            isActive: $member->isActive,
            isSepaValid: $member->isSepaValid,
            ibanMasked: $member->ibanMasked,
            deletedAt: $member->deletedAt,
            createdAt: $member->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Delete a member (Admin API).
     *
     * @param string $memberId UUID of member
     * @return bool Success
     */
    public function deleteMember(string $memberId): bool
    {
        // Mock: verify member exists
        $this->getMember($memberId);
        // In M4, this will call: $this->membersRepository->deleteById($memberId)
        return true;
    }

    /**
     * Export member data for GDPR request (Admin API).
     *
     * @param string $memberId UUID of member
     * @return array Member data export
     */
    public function exportMember(string $memberId): array
    {
        $member = $this->getMember($memberId);

        return [
            'member' => $member->toArray(),
            'transactions' => [],  // Will be populated from transaction history in M4
            'bookings' => [],      // Will be populated from booking history in M4
            'export_timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Anonymize member data (GDPR Art. 17).
     *
     * @param string $memberId UUID of member
     * @return MemberAdminDto Anonymized member
     */
    public function anonymizeMember(string $memberId): MemberAdminDto
    {
        $member = $this->getMember($memberId);

        // Mark as deleted (soft delete)
        // In M4, this will call: $this->membersRepository->updateById($memberId, ['deleted_at' => now()])

        return new MemberAdminDto(
            id: $member->id,
            cardUid: null,
            firstName: 'DELETED',
            lastName: 'DELETED',
            email: 'deleted@example.com',
            phone: null,
            preferredLanguage: $member->preferredLanguage,
            isActive: false,
            isSepaValid: false,
            ibanMasked: null,
            deletedAt: new DateTimeImmutable(),
            createdAt: $member->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Helper: Get mock member for admin responses
     *
     * @param string $id
     * @return MemberAdminDto
     */
    private function getMockMemberForAdmin(string $id): MemberAdminDto
    {
        return match ($id) {
            '123e4567-e89b-12d3-a456-426614174000' => new MemberAdminDto(
                id: $id,
                cardUid: '04:d2:3e:5a:10:80:80',
                firstName: 'Max',
                lastName: 'Mustermann',
                email: 'max@example.com',
                phone: '+41791234567',
                preferredLanguage: 'de',
                isActive: true,
                isSepaValid: true,
                ibanMasked: 'DE89****8372',
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-06-15T10:00:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-20T14:23:45Z'),
            ),
            '223e4567-e89b-12d3-a456-426614174001' => new MemberAdminDto(
                id: $id,
                cardUid: '04:d2:3e:5a:10:80:90',
                firstName: 'Anna',
                lastName: 'Schmidt',
                email: 'anna@example.com',
                phone: '+41798765432',
                preferredLanguage: 'en',
                isActive: true,
                isSepaValid: true,
                ibanMasked: 'CH9300****3696',
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-07-01T12:30:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-21T10:00:00Z'),
            ),
            default => throw new \Exception("Member not found: $id"),
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
