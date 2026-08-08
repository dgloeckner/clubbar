<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Contracts\ExtractionServiceInterface;
use App\Modules\Members\DTOs\MandateDocumentDto;
use App\Modules\Members\Repositories\MandateDocumentRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Http\Message\UploadedFileInterface;

class MandateDocumentService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private MandateDocumentRepository  $mandateDocumentRepository,
        private AuditService               $auditService,
        private Logger                     $logger,
        private ?ExtractionServiceInterface $extractionService = null,
    ) {}

    public function getStorageDir(): string
    {
        return dirname(__DIR__, 4) . '/storage/mandates';
    }

    /**
     * Upload or replace a member's mandate document.
     * If ExtractionService is configured, extraction runs synchronously on the original bytes.
     * Extraction failure is non-fatal — upload still succeeds.
     *
     * @throws \InvalidArgumentException on validation failure
     */
    public function upload(
        string              $memberId,
        UploadedFileInterface $uploadedFile,
        ?string             $adminId,
    ): MandateDocumentDto {
        $mimeType = $uploadedFile->getClientMediaType() ?? '';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unsupported file type '{$mimeType}'. Allowed: JPEG, PNG, PDF."
            );
        }
        if (($uploadedFile->getSize() ?? 0) > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File exceeds the 10 MB size limit.');
        }

        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $originalBytes = (string) $stream->getContents(); // keep original for LLM extraction

        $content = $originalBytes;
        if ($mimeType !== 'application/pdf') {
            $content = $this->convertImageToPdf($content, $mimeType);
        }

        $storageDir = $this->getStorageDir();
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $absolutePath = $storageDir . '/' . $memberId . '.pdf';
        if (file_put_contents($absolutePath, $content) === false) {
            throw new \RuntimeException('Failed to write mandate document to storage.');
        }

        $originalFilename = $uploadedFile->getClientFilename() ?? 'mandate.pdf';
        $fileSizeBytes    = strlen($content);
        $relativePath     = 'mandates/' . $memberId . '.pdf';

        $row = $this->mandateDocumentRepository->upsert([
            'member_id'            => $memberId,
            'file_path'            => $relativePath,
            'original_filename'    => $originalFilename,
            'file_size_bytes'      => $fileSizeBytes,
            'uploaded_by_admin_id' => $adminId ?? '',
        ]);

        $this->auditService->log(
            action:      AuditAction::MANDATE_DOCUMENT_UPLOAD,
            entityType:  EntityType::MEMBER,
            entityId:    $memberId,
            oldValues:   null,
            newValues:   ['original_filename' => $originalFilename, 'file_size_bytes' => $fileSizeBytes],
            adminUserId: $adminId,
        );

        // Run extraction on original bytes (not the dompdf-converted PDF).
        // Silently skipped when ExtractionService is null (LLM not configured).
        if ($this->extractionService !== null) {
            try {
                $extractionResult = $this->extractionService->extract($originalBytes, $mimeType);
                $this->mandateDocumentRepository->updateExtraction(
                    $memberId,
                    'completed',
                    $extractionResult->toArray(),
                );
                $row['extraction_status'] = 'completed';
                $row['extracted_data']    = json_encode($extractionResult->toArray());
            } catch (\Throwable $e) {
                $this->logger->error('Mandate document extraction failed', [
                    'member_id' => $memberId,
                    'error'     => $e->getMessage(),
                ]);
                $this->mandateDocumentRepository->updateExtraction($memberId, 'failed', null);
                $row['extraction_status'] = 'failed';
                $row['extracted_data']    = null;
            }
        }

        return MandateDocumentDto::fromRow($row);
    }

    /**
     * Returns the absolute filesystem path for streaming, or null if no document.
     */
    public function getAbsoluteFilePath(string $memberId): ?string
    {
        $row = $this->mandateDocumentRepository->findByMemberId($memberId);
        if ($row === null) {
            return null;
        }

        $path = $this->getStorageDir() . '/' . $memberId . '.pdf';
        return file_exists($path) ? $path : null;
    }

    public function findByMemberId(string $memberId): ?MandateDocumentDto
    {
        $row = $this->mandateDocumentRepository->findByMemberId($memberId);
        return $row !== null ? MandateDocumentDto::fromRow($row) : null;
    }

    /**
     * Delete document file and DB record. Idempotent — safe to call when no document exists.
     */
    public function deleteForMember(string $memberId, ?string $adminId = null): void
    {
        $orphanedFile = $this->deleteRecordForMember($memberId, $adminId);
        $this->removeStoredFile($orphanedFile);
    }

    /**
     * Delete the DB record (and audit the removal), leaving the stored PDF on
     * disk. Returns the absolute path of the now-orphaned file, or null when
     * the member had no document.
     *
     * Callers running inside a transaction must use this together with
     * removeStoredFile() *after* the commit: unlinking a PDF cannot be rolled
     * back, and the signed mandate is the club's legal proof of the
     * direct-debit authorization.
     */
    public function deleteRecordForMember(string $memberId, ?string $adminId = null): ?string
    {
        $row = $this->mandateDocumentRepository->findByMemberId($memberId);
        if ($row === null) {
            return null;
        }

        $this->mandateDocumentRepository->deleteByMemberId($memberId);

        $this->auditService->log(
            action:      AuditAction::MANDATE_DOCUMENT_DELETE,
            entityType:  EntityType::MEMBER,
            entityId:    $memberId,
            oldValues:   ['original_filename' => $row['original_filename']],
            newValues:   null,
            adminUserId: $adminId,
        );

        return $this->getStorageDir() . '/' . $memberId . '.pdf';
    }

    /**
     * Remove a stored mandate PDF left behind by deleteRecordForMember().
     * No-op for null (member had no document) or an already-missing file.
     */
    public function removeStoredFile(?string $absolutePath): void
    {
        if ($absolutePath !== null && file_exists($absolutePath)) {
            unlink($absolutePath);
        }
    }

    private function convertImageToPdf(string $imageContent, string $mimeType): string
    {
        // Fix EXIF orientation before embedding — phone cameras store pixels in
        // landscape order with an EXIF tag for display rotation. Dompdf ignores
        // EXIF, so without this the image appears rotated 90° in the PDF.
        $fixedContent = ImageOrientationFixer::fix($imageContent);

        // After orientation fix, the MIME type may have changed to JPEG.
        // Detect actual image dimensions to choose portrait/landscape orientation.
        $orientation = 'portrait';
        $imageInfo = @getimagesizefromstring($fixedContent);
        if ($imageInfo !== false) {
            [$width, $height] = $imageInfo;
            if ($width > $height) {
                $orientation = 'landscape';
            }
            // Update MIME type to match the (possibly re-encoded) image.
            $mimeType = $imageInfo['mime'] ?? $mimeType;
        }

        $base64  = base64_encode($fixedContent);
        $dataUri = "data:{$mimeType};base64,{$base64}";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body { margin: 0; padding: 0; }
  img  { max-width: 100%; height: auto; display: block; page-break-inside: avoid; }
</style>
</head>
<body><img src="{$dataUri}"></body>
</html>
HTML;

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
