<?php

declare(strict_types=1);

namespace App\Modules\Members\DTOs;

use App\Shared\Utils\DateFormatter;

final readonly class MandateDocumentDto
{
    public function __construct(
        public string $uploadedAt,
        public int $fileSizeBytes,
        public string $originalFilename,
        public ?string $extractionStatus,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            uploadedAt: DateFormatter::toUtcIso($row['updated_at']),
            fileSizeBytes: (int) $row['file_size_bytes'],
            originalFilename: $row['original_filename'],
            extractionStatus: $row['extraction_status'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'uploaded_at' => $this->uploadedAt,
            'file_size_bytes' => $this->fileSizeBytes,
            'original_filename' => $this->originalFilename,
            'extraction_status' => $this->extractionStatus,
        ];
    }
}
