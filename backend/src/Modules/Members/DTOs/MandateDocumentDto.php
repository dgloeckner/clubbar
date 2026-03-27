<?php

declare(strict_types=1);

namespace App\Modules\Members\DTOs;

final readonly class MandateDocumentDto
{
    /**
     * @param array<string, array{value: string|null, confidence: string|null}>|null $extraction
     */
    public function __construct(
        public string  $uploadedAt,
        public int     $fileSizeBytes,
        public string  $originalFilename,
        public ?string $extractionStatus,
        public ?array  $extraction,
    ) {}

    public static function fromRow(array $row): self
    {
        $extraction = null;
        if (!empty($row['extracted_data'])) {
            $decoded = json_decode((string) $row['extracted_data'], true);
            $extraction = is_array($decoded) ? $decoded : null;
        }

        return new self(
            uploadedAt:       \App\Shared\Utils\DateFormatter::toUtcIso($row['updated_at']),
            fileSizeBytes:    (int) $row['file_size_bytes'],
            originalFilename: $row['original_filename'],
            extractionStatus: $row['extraction_status'] ?? null,
            extraction:       $extraction,
        );
    }

    public function toArray(): array
    {
        return [
            'uploaded_at'       => $this->uploadedAt,
            'file_size_bytes'   => $this->fileSizeBytes,
            'original_filename' => $this->originalFilename,
            'extraction_status' => $this->extractionStatus,
            'extraction'        => $this->extraction,
        ];
    }
}
