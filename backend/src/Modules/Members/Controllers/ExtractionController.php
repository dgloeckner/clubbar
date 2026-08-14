<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Contracts\ExtractionServiceInterface;
use App\Shared\Http\JsonResponder;
use App\Shared\Utils\MimeSniffer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ExtractionController
{
    use JsonResponder;

    // Parity with the removed upload path, which accepted PDF scans too
    // (ADR-0037). Whether a PDF actually extracts depends on the configured
    // provider — DirectExtractionService now forwards PDFs to the LLM's
    // vision API when it supports the document content type (Anthropic does;
    // OpenAI does not and fails with a clear message), and the Google Vision
    // OCR pipeline still turns a PDF away, same as before.
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(
        private ?ExtractionServiceInterface $extractionService,
    ) {}

    /**
     * POST /api/admin/mandate-document/extract
     *
     * Stateless extraction endpoint — no DB writes, no file storage.
     * Used by the "create member from scan" flow.
     *
     * Returns:
     *   200 { fields: { first_name: { value, confidence }, ... } }
     *   409 LLM not configured
     *   422 File missing or invalid type
     *   500 Extraction failed (LLM error or parse failure)
     */
    public function extract(Request $request, Response $response): Response
    {
        if ($this->extractionService === null) {
            return $this->json($response, [
                'error'   => 'extraction_not_configured',
                'message' => 'Extraction is not configured. Set LLM_API_KEY (and optionally GCLOUD_VISION_API for the Vision OCR pipeline).',
            ], 409);
        }

        $files = $request->getUploadedFiles();
        if (empty($files['file'])) {
            return $this->validationFailed($response, ['file' => ['A file is required']]);
        }

        $uploadedFile = $files['file'];
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $this->validationFailed($response, ['file' => ['File upload failed (error code: ' . $uploadedFile->getError() . ')']]);
        }

        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $bytes = (string) $stream->getContents();

        // Sniffed, not declared: the multipart Content-Type is the client's word
        // for what it sent, and the type decides what gets forwarded to the LLM
        // provider as an image part (#107).
        $mimeType = MimeSniffer::detect($bytes);
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return $this->validationFailed($response, ['file' => ["Unsupported file type '{$mimeType}'. Allowed: JPEG, PNG."]]);
        }

        try {
            $result = $this->extractionService->extract($bytes, $mimeType);
            return $this->json($response, $result->toArray());
        } catch (\Throwable) {
            return $this->json($response, [
                'error'   => 'extraction_failed',
                'message' => 'Extraction failed. Check server logs for details.',
            ], 500);
        }
    }
}
