<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Services;

use App\Modules\Transactions\Repositories\JugendschutzViolationsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;

/**
 * What an admin can do about a recorded underage sale (#622, ADR-0045 §3).
 *
 * Which is deliberately very little: acknowledge it. There is no edit, no
 * dismiss-and-forget and no delete, because the violation is a record of
 * something that physically happened and invariant 4 says it stays true no
 * matter what changes afterwards. Acknowledging adds a fact beside it — *a
 * human has dealt with this* — and the only visible consequence is that the
 * dashboard stops asking.
 */
class JugendschutzViolationService
{
    public function __construct(
        private JugendschutzViolationsRepository $repository,
        private AuditService $auditService,
    ) {}

    /** @return array{count: int, latest_occurred_at: string|null} */
    public function unacknowledgedSummary(): array
    {
        return $this->repository->unacknowledgedSummary();
    }

    /**
     * What is still waiting to be looked at.
     *
     * @return list<array{id: int, transaction_id: string, min_age: int, recorded_at: string}>
     */
    public function listUnacknowledged(int $limit = 50): array
    {
        return $this->repository->listUnacknowledged($limit);
    }

    /**
     * Mark one violation as dealt with.
     *
     * @return bool True when this call is what acknowledged it; false when
     *              somebody had already done so. **Not** an error either way —
     *              two people looking at the same dashboard alert is the
     *              expected case, and the first acknowledgement, with its actor
     *              and note, is the one that stands.
     *
     * @throws NotFoundException When the id names no violation. The key is
     *         `audit_log.id`, shared by every audited action in the system, so
     *         without this the endpoint would accept a login's id and answer as
     *         though it had done something.
     */
    public function acknowledge(int $auditLogId, ?string $adminUserId, ?string $note): bool
    {
        if (!$this->repository->isViolation($auditLogId)) {
            throw new NotFoundException('No Jugendschutz violation with that id');
        }

        if (!$this->repository->acknowledge($auditLogId, $adminUserId, $note)) {
            return false;
        }

        // Filed under the *violation's* audit entry rather than under the
        // transaction, so the two read as one story: the incident, then how it
        // was handled. The entry above it is untouched — this is a later fact,
        // not an edit (migration 051 argues the point against 050's header).
        $this->auditService->log(
            action: AuditAction::JUGENDSCHUTZ_VIOLATION_ACKNOWLEDGED,
            entityType: EntityType::TRANSACTION,
            entityId: (string) $auditLogId,
            newValues: ['note' => $note],
            adminUserId: $adminUserId,
        );

        return true;
    }
}
