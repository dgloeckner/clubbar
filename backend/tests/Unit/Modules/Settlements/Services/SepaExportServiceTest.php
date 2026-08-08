<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SepaExportService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

class SepaExportServiceTest extends TestCase
{
    private SepaConfigRepository $sepaConfigRepository;
    private MembersRepository $membersRepository;
    private SettlementsRepository $settlementsRepository;
    private SepaExportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sepaConfigRepository = $this->createMock(SepaConfigRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);

        $this->service = new SepaExportService(
            $this->sepaConfigRepository,
            $this->membersRepository,
            $this->settlementsRepository,
        );
    }

    public function test_export_throws_notFoundException_when_the_settlement_is_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->export('missing-id');
    }

    public function test_export_refuses_a_cancelled_settlement_with_an_accurate_error(): void
    {
        // #114 / #142 §5. This never had a guard: cancellation used to delete
        // the items, so the export failed by accident with "Settlement has no
        // items" — and now that the rows survive it would not fail at all, and
        // would instruct the bank to collect a run the club called off.
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => 'settlement-1',
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 1,
            'execution_date' => '2026-08-20',
        ]);

        // The refusal comes before anything else is even looked up.
        $this->sepaConfigRepository->expects($this->never())->method('getConfig');
        $this->settlementsRepository->expects($this->never())->method('findItemsBySettlementId');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/was cancelled and cannot be exported/i');

        $this->service->export('settlement-1');
    }
}
