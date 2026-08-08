<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Repositories\MandateDocumentRepository;
use App\Modules\Members\Services\MandateDocumentService;
use App\Shared\Enums\AuditAction;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a mandate document is two operations with very different
 * reversibility: the DB row can roll back, the PDF on disk cannot. These
 * tests pin the split that lets a caller inside a transaction defer the
 * irreversible half until after the commit (#85).
 */
class MandateDocumentServiceTest extends TestCase
{
    private MandateDocumentRepository $repository;
    private AuditService $auditService;
    private string $storageDir;
    private MandateDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository   = $this->createMock(MandateDocumentRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->storageDir   = sys_get_temp_dir() . '/mandate-service-test-' . bin2hex(random_bytes(6));
        mkdir($this->storageDir, 0777, true);

        $storageDir = $this->storageDir;
        $this->service = new class (
            $this->repository,
            $this->auditService,
            $this->createMock(Logger::class),
            $storageDir,
        ) extends MandateDocumentService {
            public function __construct(
                MandateDocumentRepository $repository,
                AuditService $auditService,
                Logger $logger,
                private string $testStorageDir,
            ) {
                parent::__construct($repository, $auditService, $logger);
            }

            public function getStorageDir(): string
            {
                return $this->testStorageDir;
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->storageDir)) {
            rmdir($this->storageDir);
        }

        parent::tearDown();
    }

    private function storedPdf(string $memberId): string
    {
        $path = $this->storageDir . '/' . $memberId . '.pdf';
        file_put_contents($path, '%PDF-1.4 signed mandate');
        return $path;
    }

    private function documentRow(): array
    {
        return [
            'member_id'         => 'member-1',
            'file_path'         => 'mandates/member-1.pdf',
            'original_filename' => 'mandate-max-mustermann.pdf',
            'file_size_bytes'   => 22,
        ];
    }

    // ── deleteRecordForMember ──────────────────────────────

    public function test_deleteRecordForMember_leaves_the_pdf_on_disk_for_the_caller_to_remove(): void
    {
        $path = $this->storedPdf('member-1');
        $this->repository->method('findByMemberId')->willReturn($this->documentRow());
        $this->repository->expects($this->once())->method('deleteByMemberId')->with('member-1');

        $orphaned = $this->service->deleteRecordForMember('member-1', 'admin-1');

        $this->assertSame($path, $orphaned);
        $this->assertFileExists($path, 'The PDF must survive until the caller commits.');
    }

    public function test_deleteRecordForMember_audits_the_removal(): void
    {
        $this->repository->method('findByMemberId')->willReturn($this->documentRow());

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::MANDATE_DOCUMENT_DELETE,
                $this->anything(),
                'member-1',
                ['original_filename' => 'mandate-max-mustermann.pdf'],
                null,
                'admin-1',
            );

        $this->service->deleteRecordForMember('member-1', 'admin-1');
    }

    public function test_deleteRecordForMember_is_a_no_op_when_the_member_has_no_document(): void
    {
        $this->repository->method('findByMemberId')->willReturn(null);
        $this->repository->expects($this->never())->method('deleteByMemberId');
        $this->auditService->expects($this->never())->method('log');

        $this->assertNull($this->service->deleteRecordForMember('member-1', 'admin-1'));
    }

    // ── removeStoredFile ───────────────────────────────────

    public function test_removeStoredFile_unlinks_the_pdf(): void
    {
        $path = $this->storedPdf('member-1');

        $this->service->removeStoredFile($path);

        $this->assertFileDoesNotExist($path);
    }

    public function test_removeStoredFile_tolerates_null_and_a_missing_file(): void
    {
        $this->service->removeStoredFile(null);
        $this->service->removeStoredFile($this->storageDir . '/never-existed.pdf');

        $this->assertDirectoryExists($this->storageDir);
    }

    // ── deleteForMember (the standalone endpoint) ──────────

    public function test_deleteForMember_removes_both_the_record_and_the_pdf(): void
    {
        $path = $this->storedPdf('member-1');
        $this->repository->method('findByMemberId')->willReturn($this->documentRow());
        $this->repository->expects($this->once())->method('deleteByMemberId')->with('member-1');

        $this->service->deleteForMember('member-1', 'admin-1');

        $this->assertFileDoesNotExist($path);
    }

    public function test_deleteForMember_is_idempotent_when_no_document_exists(): void
    {
        $this->repository->method('findByMemberId')->willReturn(null);
        $this->repository->expects($this->never())->method('deleteByMemberId');

        $this->service->deleteForMember('member-1', 'admin-1');

        $this->assertCount(0, glob($this->storageDir . '/*') ?: []);
    }
}
