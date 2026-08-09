<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Repositories\MandateDocumentRepository;
use App\Modules\Members\Services\MandateDocumentService;
use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use App\Shared\Enums\AuditAction;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Two properties of the mandate store that are easy to lose.
 *
 * Uploading: what reaches disk is decided by the file's own bytes, not by the
 * Content-Type the client attached to them (#107).
 *
 * Deleting: two operations with very different reversibility — the DB row can
 * roll back, the PDF on disk cannot. These tests pin the split that lets a
 * caller inside a transaction defer the irreversible half until after the
 * commit (#85).
 */
class MandateDocumentServiceTest extends TestCase
{
    private MandateDocumentRepository $repository;
    private AuditService $auditService;
    private string $dataDir;
    private string $storageDir;
    private array $envBackup = [];
    private MandateDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository   = $this->createMock(MandateDocumentRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        // The store follows the configured data directory (#245) — no override
        // here, so these tests exercise the same path resolution production
        // uses when the installer places that directory above the document root.
        $this->dataDir    = sys_get_temp_dir() . '/mandate-service-test-' . bin2hex(random_bytes(6));
        $this->storageDir = $this->dataDir . '/storage/mandates';
        mkdir($this->storageDir, 0777, true);

        $this->envBackup = $_ENV;
        $_ENV['DATA_DIR'] = $this->dataDir;
        Env::reset();

        $this->service = new MandateDocumentService(
            $this->repository,
            $this->auditService,
            $this->createMock(Logger::class),
            new AppConfig(),
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dataDir);
        $_ENV = $this->envBackup;
        Env::reset();

        parent::tearDown();
    }

    public function test_the_store_lives_in_the_configured_data_directory(): void
    {
        $this->assertSame(
            $this->dataDir . '/storage/mandates',
            $this->service->getStorageDir(),
            'A relocated data directory has to take the scanned mandates with it'
        );
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = "{$path}/{$entry}";
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
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

    /**
     * A multipart upload whose declared Content-Type is whatever the caller
     * chose — which is all `getClientMediaType()` ever reports.
     */
    private function upload(string $bytes, string $declaredType, string $filename = 'mandate.pdf'): UploadedFileInterface
    {
        return new class ($bytes, $declaredType, $filename) implements UploadedFileInterface {
            public function __construct(
                private string $bytes,
                private string $declaredType,
                private string $filename,
            ) {}

            public function getStream(): StreamInterface
            {
                return (new StreamFactory())->createStream($this->bytes);
            }

            public function getSize(): ?int { return strlen($this->bytes); }
            public function getError(): int { return UPLOAD_ERR_OK; }
            public function getClientFilename(): ?string { return $this->filename; }
            public function getClientMediaType(): ?string { return $this->declaredType; }

            public function moveTo(string $targetPath): void
            {
                throw new \LogicException('Not used: the service reads the stream.');
            }
        };
    }

    /**
     * Bytes are built here rather than read from `e2etests/fixtures` — that
     * directory is outside the `./backend:/app` mount, so a fixture path passes
     * on the host and fails in the container CLAUDE.md tells everyone to use.
     */
    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** A 1×1 PNG — enough for finfo to type it and for dompdf to embed it. */
    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    private function expectStoredRow(): void
    {
        $this->repository->method('upsert')->willReturn([
            'updated_at'        => '2026-01-01 12:00:00',
            'file_size_bytes'   => 1,
            'original_filename' => 'mandate.pdf',
            'extraction_status' => null,
            'extracted_data'    => null,
        ]);
    }

    // ── upload: the type comes from the bytes (#107) ───────

    /**
     * The multipart Content-Type is attacker-controlled, and `application/pdf`
     * is the one value that skips the dompdf re-render and reaches disk
     * untouched. Sniffing is what stops that from being a way to put chosen
     * bytes into the mandate store.
     */
    public function test_upload_rejects_content_that_is_not_a_pdf_however_it_is_declared(): void
    {
        $this->repository->expects($this->never())->method('upsert');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->upload(
            'member-1',
            $this->upload('<script>alert(1)</script>', 'application/pdf', 'mandate.pdf'),
            'admin-1',
        );
    }

    public function test_upload_names_the_detected_type_not_the_declared_one(): void
    {
        try {
            $this->service->upload('member-1', $this->upload('%!PS-Adobe-3.0', 'application/pdf'), 'admin-1');
            $this->fail('Expected the upload to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('application/pdf', $e->getMessage());
        }
    }

    public function test_upload_rejects_an_empty_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->upload('member-1', $this->upload('', 'application/pdf'), 'admin-1');
    }

    public function test_upload_stores_a_real_pdf_verbatim(): void
    {
        $this->expectStoredRow();

        $this->service->upload('member-1', $this->upload($this->pdfBytes(), 'application/pdf'), 'admin-1');

        $this->assertSame($this->pdfBytes(), file_get_contents($this->storageDir . '/member-1.pdf'));
    }

    /**
     * An image that claims to be a PDF used to be written to disk as-is and
     * then streamed back as `application/pdf`. The sniffed type decides, so it
     * takes the image branch and comes out a real PDF.
     */
    public function test_upload_converts_an_image_that_claims_to_be_a_pdf(): void
    {
        $this->expectStoredRow();

        $this->service->upload('member-1', $this->upload($this->pngBytes(), 'application/pdf', 'scan.pdf'), 'admin-1');

        $stored = (string) file_get_contents($this->storageDir . '/member-1.pdf');
        $this->assertStringStartsWith('%PDF', $stored);
        $this->assertNotSame($this->pngBytes(), $stored);
    }

    /**
     * The mirror case: a PDF sent as `image/png` is still a PDF and is stored
     * as one, rather than being fed to dompdf as an image.
     */
    public function test_upload_accepts_a_pdf_that_claims_to_be_an_image(): void
    {
        $this->expectStoredRow();

        $this->service->upload('member-1', $this->upload($this->pdfBytes(), 'image/png', 'scan.png'), 'admin-1');

        $this->assertSame($this->pdfBytes(), file_get_contents($this->storageDir . '/member-1.pdf'));
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
