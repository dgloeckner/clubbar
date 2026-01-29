<?php

namespace App\Http\Modules\Members\Services;

use App\DTOs\SyncResultDto;
use App\Http\Modules\Members\DTOs\MemberAdminDto;
use App\Http\Modules\Members\DTOs\MemberDto;
use App\Http\Modules\Members\Enums\SupportedLanguage;
use App\Http\Modules\Members\Repositories\MembersRepository;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Services\BaseService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * MembersService - Business Logic for Members Module
 *
 * Handles member-specific operations:
 * - Terminal API: delta sync, language updates
 * - Admin API: CRUD operations with audit logging
 * - GDPR: export and anonymization with audit logging
 *
 * Extends BaseService (Pattern 010) for standard CRUD operations.
 * Implements Pattern 004: Service Layer with business logic isolation.
 * Integrates Pattern 016 (Audit Logging) for all master data changes.
 */
class MembersService extends BaseService
{
    /**
     * Initialize service with Members repository and Audit service.
     *
     * Dependencies injected by service provider (Pattern 008):
     * - MembersRepository: Data access layer (Pattern 011)
     * - AuditService: Cross-cutting concern for audit logging (Pattern 016)
     *
     * @param MembersRepository $membersRepository
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly MembersRepository $membersRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($membersRepository);
    }

    /**
     * Get members modified since timestamp (Terminal API).
     *
     * Returns delta sync result for /api/sync/members endpoint.
     * Queries database for active members modified since timestamp.
     *
     * @param int $since Unix timestamp (from query param)
     * @return SyncResultDto Contains members[], cursor, hasMore
     */
    public function syncSince(int $since): SyncResultDto
    {
        // Query database for members modified since timestamp
        $memberModels = $this->membersRepository->findModifiedSince($since);

        // Transform models to DTOs
        $members = $memberModels->map(fn($model) => $this->transform($model))->values();

        // Get latest timestamp for cursor (for next sync)
        $cursor = $memberModels->isNotEmpty()
            ? $memberModels->last()->updated_at->format('Y-m-d\TH:i:s\Z')
            : date('Y-m-d\TH:i:s\Z');

        return new SyncResultDto(
            items: $members->all(),
            cursor: $cursor,
            hasMore: false,  // TODO: Implement pagination if member count exceeds threshold
        );
    }

    /**
     * Update member's preferred language (Terminal API).
     *
     * Updates /api/sync/members/{memberId}/language endpoint.
     * Queries database and updates member language preference.
     *
     * @param string $memberId UUID of member
     * @param SupportedLanguage $language New language preference
     * @return MemberDto Updated member data
     */
    public function updateLanguage(string $memberId, SupportedLanguage $language): MemberDto
    {
        // Update member language in database
        $member = $this->membersRepository->updateById($memberId, [
            'preferred_language' => $language->value,
        ]);

        if (!$member) {
            throw new \Exception("Member not found: $memberId");
        }

        // Transform to DTO
        return $this->transform($member);
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
        // Build query with filters
        $query = $this->membersRepository->query();
        $query = $this->applyFilters($query, $filters);

        // Get total count before pagination
        $total = $query->count();

        // Apply pagination and fetch results
        $memberModels = $query
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($limit)
            ->get();

        // Transform models to admin DTOs
        $items = $memberModels->map(fn($model) => new MemberAdminDto(
            id: $model->id,
            cardUid: $model->card_uid,
            firstName: $model->first_name,
            lastName: $model->last_name,
            email: $model->email,
            phone: $model->phone,
            preferredLanguage: $model->preferred_language,
            isActive: $model->is_active,
            isSepaValid: !empty($model->iban) && !empty($model->mandate_reference),
            ibanMasked: $model->iban ? substr($model->iban, 0, 2) . '****' . substr($model->iban, -4) : null,
            deletedAt: $model->deleted_at ? new DateTimeImmutable($model->deleted_at->format('c')) : null,
            createdAt: new DateTimeImmutable($model->created_at->format('c')),
            updatedAt: new DateTimeImmutable($model->updated_at->format('c')),
        ))->all();

        return new PaginatedResultDto(
            items: array_map(fn($dto) => $dto->toArray(), $items),
            total: $total,
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
        $member = $this->membersRepository->findById($memberId);

        if (!$member) {
            throw new \Exception("Member not found: $memberId");
        }

        return new MemberAdminDto(
            id: $member->id,
            cardUid: $member->card_uid,
            firstName: $member->first_name,
            lastName: $member->last_name,
            email: $member->email,
            phone: $member->phone,
            preferredLanguage: $member->preferred_language,
            isActive: $member->is_active,
            isSepaValid: !empty($member->iban) && !empty($member->mandate_reference),
            ibanMasked: $member->iban ? substr($member->iban, 0, 2) . '****' . substr($member->iban, -4) : null,
            deletedAt: $member->deleted_at ? new DateTimeImmutable($member->deleted_at->format('c')) : null,
            createdAt: new DateTimeImmutable($member->created_at->format('c')),
            updatedAt: new DateTimeImmutable($member->updated_at->format('c')),
        );
    }

    /**
     * Create a new member (Admin API).
     *
     * Logs creation to audit_log with admin context.
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
        // Create member in database
        $member = $this->membersRepository->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'card_uid' => $cardUid,
            'preferred_language' => $language->value,
            'is_active' => true,
        ]);

        // Log member creation (Pattern 016: Audit Logging)
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::MEMBER,
            entityId: $member->id,
            oldValues: null,
            newValues: [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'card_uid' => $cardUid,
                'preferred_language' => $language->value,
                'is_active' => true,
            ],
            adminUserId: $this->getCurrentAdminUserId(),
        );

        // Transform to admin DTO
        return new MemberAdminDto(
            id: $member->id,
            cardUid: $member->card_uid,
            firstName: $member->first_name,
            lastName: $member->last_name,
            email: $member->email,
            phone: $member->phone,
            preferredLanguage: $member->preferred_language,
            isActive: $member->is_active,
            isSepaValid: !empty($member->iban) && !empty($member->mandate_reference),
            ibanMasked: $member->iban ? substr($member->iban, 0, 2) . '****' . substr($member->iban, -4) : null,
            deletedAt: $member->deleted_at ? new DateTimeImmutable($member->deleted_at->format('c')) : null,
            createdAt: new DateTimeImmutable($member->created_at->format('c')),
            updatedAt: new DateTimeImmutable($member->updated_at->format('c')),
        );
    }

    /**
     * Update an existing member (Admin API).
     *
     * Logs only changed fields to audit_log for clarity.
     * Compares before/after state to capture deltas.
     *
     * @param string $memberId UUID of member
     * @param array $updateData Fields to update (snake_case keys)
     * @return MemberAdminDto Updated member
     */
    public function updateMember(string $memberId, array $updateData): MemberAdminDto
    {
        // Fetch current state (before update)
        $oldMember = $this->membersRepository->findById($memberId);

        if (!$oldMember) {
            throw new \Exception("Member not found: $memberId");
        }

        // Map camelCase input to snake_case for database
        $dbUpdateData = [];
        if (isset($updateData['firstName'])) {
            $dbUpdateData['first_name'] = $updateData['firstName'];
        }
        if (isset($updateData['lastName'])) {
            $dbUpdateData['last_name'] = $updateData['lastName'];
        }
        if (isset($updateData['email'])) {
            $dbUpdateData['email'] = $updateData['email'];
        }
        if (isset($updateData['phone'])) {
            $dbUpdateData['phone'] = $updateData['phone'];
        }
        if (isset($updateData['cardUid'])) {
            $dbUpdateData['card_uid'] = $updateData['cardUid'];
        }
        if (isset($updateData['preferredLanguage'])) {
            $dbUpdateData['preferred_language'] = $updateData['preferredLanguage'];
        }
        if (isset($updateData['isActive'])) {
            $dbUpdateData['is_active'] = $updateData['isActive'];
        }

        // Update in database
        $member = $this->membersRepository->updateById($memberId, $dbUpdateData);

        // Detect and log only changed fields (Pattern 016: Audit Logging)
        $changedFields = $this->detectChanges($oldMember, $member);
        if (!empty($changedFields['old'])) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::MEMBER,
                entityId: $member->id,
                oldValues: $changedFields['old'],
                newValues: $changedFields['new'],
                adminUserId: $this->getCurrentAdminUserId(),
            );
        }

        // Transform to DTO
        return new MemberAdminDto(
            id: $member->id,
            cardUid: $member->card_uid,
            firstName: $member->first_name,
            lastName: $member->last_name,
            email: $member->email,
            phone: $member->phone,
            preferredLanguage: $member->preferred_language,
            isActive: $member->is_active,
            isSepaValid: !empty($member->iban) && !empty($member->mandate_reference),
            ibanMasked: $member->iban ? substr($member->iban, 0, 2) . '****' . substr($member->iban, -4) : null,
            deletedAt: $member->deleted_at ? new DateTimeImmutable($member->deleted_at->format('c')) : null,
            createdAt: new DateTimeImmutable($member->created_at->format('c')),
            updatedAt: new DateTimeImmutable($member->updated_at->format('c')),
        );
    }

    /**
     * Delete a member (Admin API).
     *
     * Logs deletion to audit_log with member state before deletion.
     *
     * @param string $memberId UUID of member
     * @return bool Success
     * @throws \Exception When member not found
     */
    public function deleteMember(string $memberId): bool
    {
        // Fetch member state (before deletion)
        $member = $this->membersRepository->findById($memberId);

        if (!$member) {
            throw new \Exception("Member not found: $memberId");
        }

        // Perform deletion
        $success = $this->membersRepository->deleteById($memberId);

        if (!$success) {
            throw new \Exception("Member not found: $memberId");
        }

        // Log member deletion (Pattern 016: Audit Logging)
        $this->auditService->log(
            action: AuditAction::DELETE,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: [
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'is_active' => $member->is_active,
            ],
            newValues: null,
            adminUserId: $this->getCurrentAdminUserId(),
        );

        return $success;
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
     * Logs anonymization to audit_log with old values masked.
     * Per ADR-0013: IBAN never logged in full; masked as [MASKED] for privacy.
     *
     * @param string $memberId UUID of member
     * @return MemberAdminDto Anonymized member
     */
    public function anonymizeMember(string $memberId): MemberAdminDto
    {
        // Fetch member state (before anonymization)
        $oldMember = $this->membersRepository->findById($memberId);

        if (!$oldMember) {
            throw new \Exception("Member not found: $memberId");
        }

        // Call repository to anonymize (clears PII and marks as deleted)
        $success = $this->membersRepository->anonymize($memberId);

        if (!$success) {
            throw new \Exception("Member not found: $memberId");
        }

        // Fetch updated member
        $member = $this->membersRepository->findById($memberId);

        // Log anonymization (Pattern 016: Audit Logging)
        // Per ADR-0013: IBAN masked as [MASKED] for GDPR privacy
        $this->auditService->log(
            action: AuditAction::ANONYMIZE,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: [
                'first_name' => $oldMember->first_name,
                'last_name' => $oldMember->last_name,
                'email' => $oldMember->email,
                'iban' => '[MASKED]',  // Never log full IBAN, per ADR-0013
            ],
            newValues: [
                'first_name' => null,
                'last_name' => null,
                'email' => null,
                'card_uid' => $member->card_uid,
                'deleted_at' => $member->deleted_at?->format('Y-m-d\TH:i:s\Z'),
            ],
            adminUserId: $this->getCurrentAdminUserId(),
        );

        return new MemberAdminDto(
            id: $member->id,
            cardUid: $member->card_uid,
            firstName: $member->first_name,
            lastName: $member->last_name,
            email: $member->email,
            phone: $member->phone,
            preferredLanguage: $member->preferred_language,
            isActive: $member->is_active,
            isSepaValid: false,
            ibanMasked: null,
            deletedAt: $member->deleted_at ? new DateTimeImmutable($member->deleted_at->format('c')) : null,
            createdAt: new DateTimeImmutable($member->created_at->format('c')),
            updatedAt: new DateTimeImmutable($member->updated_at->format('c')),
        );
    }

    /**
     * Transform Model to MemberDto.
     *
     * Hook method from BaseService (Pattern 010).
     * Called by syncSince and other methods when transforming database models to DTOs.
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
            isSepaValid: !empty($entity->iban) && !empty($entity->mandate_reference),
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

    /**
     * Detect which fields changed between two member states
     *
     * Used by updateMember() to log only changed fields to audit_log.
     * Returns old and new values only for fields that differ.
     *
     * Pattern 016: Audit Logging - Improves readability by showing deltas
     *
     * @param Model $oldModel State before update
     * @param Model $newModel State after update
     * @return array ['old' => [...changed fields...], 'new' => [...changed fields...]]
     */
    private function detectChanges(Model $oldModel, Model $newModel): array
    {
        $oldData = $oldModel->getAttributes();
        $newData = $newModel->getAttributes();

        $oldValues = [];
        $newValues = [];

        foreach ($newData as $key => $newValue) {
            $oldValue = $oldData[$key] ?? null;

            // Only include fields that actually changed
            if ($oldValue !== $newValue) {
                $oldValues[$key] = $oldValue;
                $newValues[$key] = $newValue;
            }
        }

        return [
            'old' => $oldValues,
            'new' => $newValues,
        ];
    }

    /**
     * Get current admin user ID from request context
     *
     * Returns the UUID of the authenticated admin user performing the action.
     * Nullable for system actions or failed login attempts (per Pattern 013).
     *
     * Pattern 016: Audit Logging - Links audit entries to admin who performed action
     *
     * @return string|null UUID of current admin, or null if no authenticated user
     */
    private function getCurrentAdminUserId(): ?string
    {
        return request()->user()?->id;
    }
}
