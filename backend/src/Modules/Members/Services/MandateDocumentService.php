<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\DTOs\MandateDocumentDto;
use App\Modules\Members\Repositories\MandateDocumentRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Http\Message\UploadedFileInterface;

class MandateDocumentService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private MandateDocumentRepository $mandateDocumentRepository,
        private AuditService $auditService,
    ) {}

    /**
     * Absolute path to the mandates storage directory.
     * Located at backend/storage/mandates/ — outside the web root (public/).
     */
    public function getStorageDir(): string
    {
        // __DIR__ = backend/src/Modules/Members/Services
        return dirname(__DIR__, 4) . '/storage/mandates';
    }

    /**
     * Upload or replace a member's mandate document.
     * Converts images to PDF via dompdf. Idempotent (upsert).
     *
     * @throws \InvalidArgumentException on validation failure
     */
    public function upload(
        string $memberId,
        UploadedFileInterface $uploadedFile,
        ?string $adminId,
    ): MandateDocumentDto {
        $mimeType = $uploadedFile->getClientMediaType() ?? '';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unsupported file type '{$mimeType}'. Allowed: JPEG, PNG, PDF."
            );
        }
        if ($uploadedFile->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File exceeds the 10 MB size limit.');
        }

        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $content = (string) $stream->getContents();

        if ($mimeType !== 'application/pdf') {
            $content = $this->convertImageToPdf($content, $mimeType);
        }

        $storageDir = $this->getStorageDir();
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $absolutePath = $storageDir . '/' . $memberId . '.pdf';
        file_put_contents($absolutePath, $content);

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
        $row = $this->mandateDocumentRepository->findByMemberId($memberId);
        if ($row === null) {
            return;
        }

        $absolutePath = $this->getStorageDir() . '/' . $memberId . '.pdf';
        if (file_exists($absolutePath)) {
            unlink($absolutePath);
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
    }

    private function convertImageToPdf(string $imageContent, string $mimeType): string
    {
        $base64  = base64_encode($imageContent);
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
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
