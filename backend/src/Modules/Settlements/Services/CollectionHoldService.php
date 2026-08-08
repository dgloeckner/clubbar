<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\DTOs\CollectionHoldDto;
use App\Modules\Settlements\Repositories\CollectionHoldRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;

/**
 * The admin side of the collection hold (ruling #148 §4-§5).
 *
 * Placing a hold is not here: a hold is only ever placed by a bank return, so
 * it belongs to {@see SettlementReversalService} where it commits in the same
 * transaction as the reversal that caused it. What an admin does is see the
 * holds and clear them.
 */
class CollectionHoldService
{
    public function __construct(
        private CollectionHoldRepository $collectionHoldRepository,
        private MembersRepository $membersRepository,
        private AuditService $auditService,
    ) {}

    /**
     * @return array{items: list<CollectionHoldDto>, total_held_cents: int}
     */
    public function listHeld(): array
    {
        $rows = $this->collectionHoldRepository->findAllHeld();

        return [
            'items' => array_map(static fn(array $row): CollectionHoldDto => CollectionHoldDto::fromRow($row), $rows),
            'total_held_cents' => array_sum(array_map(static fn(array $row): int => (int) $row['balance_cents'], $rows)),
        ];
    }

    /**
     * Let the next run collect from this member again.
     *
     * @throws NotFoundException 404 when the member does not exist.
     * @throws BusinessRuleException 409 when the member is not on hold — silently
     *         succeeding would report a hold as cleared that someone else already
     *         cleared, and the audit log would show two clears for one hold.
     */
    public function clearHold(string $memberId, string $adminUserId): CollectionHoldDto
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        if (empty($member['collection_hold'])) {
            throw new BusinessRuleException('This member is not on collection hold.');
        }

        $this->collectionHoldRepository->clear($memberId, $adminUserId);

        $this->auditService->log(
            action: AuditAction::COLLECTION_HOLD_CLEARED,
            entityType: EntityType::MEMBER,
            entityId: $memberId,
            oldValues: ['collection_hold' => true, 'reason' => $member['collection_hold_reason'] ?? null],
            newValues: ['collection_hold' => false],
            adminUserId: $adminUserId,
        );

        return CollectionHoldDto::fromRow([
            'member_id' => $memberId,
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'collection_hold_reason' => $member['collection_hold_reason'] ?? null,
            'held_at' => $member['held_at'] ?? null,
            'held_by_admin_id' => $member['held_by_admin_id'] ?? null,
        ]);
    }
}
