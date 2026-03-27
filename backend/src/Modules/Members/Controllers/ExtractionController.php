<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\ExtractionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ExtractionController
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(
        private ?ExtractionService $extractionService,
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
                'error'   => 'llm_not_configured',
                'message' => 'LLM extraction is not configured.',
            ], 409);
        }

        $files = $request->getUploadedFiles();
        if (empty($files['file'])) {
            return $this->json($response, [
                'error'    => 'validation_error',
                'messages' => ['file' => ['A file is required']],
            ], 422);
        }

        $uploadedFile = $files['file'];
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, [
                'error'    => 'validation_error',
                'messages' => ['file' => ['File upload failed (error code: ' . $uploadedFile->getError() . ')']],
            ], 422);
        }

        $mimeType = $uploadedFile->getClientMediaType() ?? '';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return $this->json($response, [
                'error'    => 'validation_error',
                'messages' => ['file' => ["Unsupported file type '{$mimeType}'. Allowed: JPEG, PNG, PDF."]],
            ], 422);
        }

        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $bytes = (string) $stream->getContents();

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

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
