<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class MembersServiceTest extends TestCase
{
    private MembersRepository $membersRepository;
    private TransactionsRepository $transactionsRepository;
    private AuditService $auditService;
    private MembersService $membersService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        // Create service instance
        $this->membersService = new MembersService(
            $this->membersRepository,
            $this->transactionsRepository,
            $this->auditService
        );
    }

    public function test_syncSince_returns_cursor_in_milliseconds_when_no_rows(): void
    {
        // Mock repository to return empty array (no rows)
        $this->membersRepository
            ->expects($this->once())
            ->method('findModifiedSince')
            ->with($this->anything())
            ->willReturn([]);

        $result = $this->membersService->syncSince(9999999999999);

        // Cursor should be in milliseconds (13 digits, > 1700000000000)
        $this->assertGreaterThan(1700000000000, $result->cursor);
        $this->assertLessThan(2000000000000, $result->cursor);
    }
}
