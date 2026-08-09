<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\MandateDocumentService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MandateDocumentController
{
    use JsonResponder;

    public function __construct(
        private MandateDocumentService $mandateDocumentService,
        private MembersRepository $membersRepository,
    ) {}

    /**
     * POST /admin/members/{memberId}/mandate-document
     * Upload or replace a mandate document. Returns 200 on success (upsert).
     */
    public function upload(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $adminId  = $request->getAttribute('admin_user_id');

        if (!$this->membersRepository->exists($memberId)) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Member not found'], 404);
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

        try {
            $doc = $this->mandateDocumentService->upload($memberId, $uploadedFile, $adminId);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, [
                'error'    => 'validation_error',
                'messages' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        return $this->json($response, $doc->toArray());
    }

    /**
     * GET /admin/members/{memberId}/mandate-document
     * Stream the stored PDF as a download.
     * Returns 404 for both "member not found" and "member has no document" —
     * a single neutral message to avoid leaking member existence.
     *
     * The response hands back a file an admin uploaded, so it is served as an
     * attachment rather than rendered in the site's origin, and marked nosniff
     * so no browser re-types it from its content (#107). Upload-time sniffing
     * already keeps non-PDFs out of the store; these two headers mean the
     * download path does not depend on that being airtight.
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];

        $filePath = $this->mandateDocumentService->getAbsoluteFilePath($memberId);
        if ($filePath === null) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'No mandate document found'], 404);
        }

        $response->getBody()->write((string) file_get_contents($filePath));
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', 'attachment; filename="mandate.pdf"')
            ->withHeader('Content-Length', (string) filesize($filePath));
    }

    /**
     * DELETE /admin/members/{memberId}/mandate-document
     * Delete document file and DB record (GDPR / manual removal).
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $adminId  = $request->getAttribute('admin_user_id');

        if (!$this->membersRepository->exists($memberId)) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Member not found'], 404);
        }

        $doc = $this->mandateDocumentService->findByMemberId($memberId);
        if ($doc === null) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'No mandate document for this member'], 404);
        }

        $this->mandateDocumentService->deleteForMember($memberId, $adminId);

        return $response->withStatus(204);
    }
}
