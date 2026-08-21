<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Services;

use App\Modules\Transactions\Repositories\JugendschutzViolationsRepository;
use App\Modules\Transactions\Services\JugendschutzViolationService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Acknowledging a recorded violation (#622, ADR-0045 §3).
 *
 * The behaviour worth pinning is what acknowledgement *is not*: it does not
 * touch the violation, and it cannot be pointed at anything that is not one.
 */
class JugendschutzViolationServiceTest extends TestCase
{
    private JugendschutzViolationsRepository $repository;
    private AuditService $auditService;
    private JugendschutzViolationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(JugendschutzViolationsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->service = new JugendschutzViolationService($this->repository, $this->auditService);
    }

    public function test_acknowledging_records_who_did_it_and_when(): void
    {
        $this->repository->method('isViolation')->willReturn(true);
        $this->repository->method('acknowledge')->willReturn(true);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::JUGENDSCHUTZ_VIOLATION_ACKNOWLEDGED,
                EntityType::TRANSACTION,
                // Filed under the audit entry that carries the violation, so the
                // pair reads as one story when somebody asks how it was handled.
                '4711',
                null,
                $this->callback(fn (array $v) => $v['note'] === 'card withdrawn'),
                'admin-1',
            );

        $this->assertTrue($this->service->acknowledge(4711, 'admin-1', 'card withdrawn'));
    }

    /**
     * An id that is not a violation is a 404, not a silent no-op.
     *
     * The key is `audit_log.id`, which every audited action shares. Without
     * this the endpoint would accept a login's id and answer as though it had
     * done something.
     */
    public function test_an_entry_that_is_not_a_violation_is_not_found(): void
    {
        $this->repository->method('isViolation')->willReturn(false);
        $this->repository->expects($this->never())->method('acknowledge');
        $this->auditService->expects($this->never())->method('log');

        $this->expectException(NotFoundException::class);
        $this->service->acknowledge(4711, 'admin-1', null);
    }

    /**
     * The second admin to press the button is not an error.
     *
     * Two people looking at the same dashboard alert is the expected case, not
     * a race to report. The first acknowledgement — with its actor and note —
     * stands, and nothing is audited twice.
     */
    public function test_acknowledging_an_already_acknowledged_violation_is_idempotent(): void
    {
        $this->repository->method('isViolation')->willReturn(true);
        $this->repository->method('acknowledge')->willReturn(false);

        $this->auditService->expects($this->never())->method('log');

        $this->assertFalse($this->service->acknowledge(4711, 'admin-2', null));
    }

    /** What the dashboard asks for. */
    public function test_the_summary_is_passed_through(): void
    {
        $this->repository->method('unacknowledgedSummary')
            ->willReturn(['count' => 2, 'latest_occurred_at' => '2026-08-20 21:00:00']);

        $this->assertSame(
            ['count' => 2, 'latest_occurred_at' => '2026-08-20 21:00:00'],
            $this->service->unacknowledgedSummary(),
        );
    }
}
