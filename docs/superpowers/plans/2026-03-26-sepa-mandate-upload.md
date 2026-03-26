# SEPA Mandate Document Upload — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow admins to upload scanned SEPA mandate documents for existing members, with client-side image compression and server-side PDF conversion via dompdf.

**Architecture:** New `mandate_documents` table + `MandateDocumentService` (upload/convert/delete) + `MandateDocumentController` (3 REST endpoints) in the Members module. `AdminController::anonymize` extended to delete documents. Frontend `MandateDocumentSection` component added to the edit member modal, uploads immediately on click independent of Save.

**Tech Stack:** PHP 8.3/Slim 4, dompdf (existing), PDO/MariaDB CHAR(36) UUIDs, React 18/TypeScript/Tailwind, `browser-image-compression`, `heic2any`, Playwright E2E

**Spec:** `docs/superpowers/specs/2026-03-26-sepa-mandate-upload-design.md`

---

## File Map

### New backend files
- `backend/db/migrations/005_mandate_documents.sql`
- `backend/storage/mandates/.gitkeep`
- `backend/src/Modules/Members/DTOs/MandateDocumentDto.php`
- `backend/src/Modules/Members/Repositories/MandateDocumentRepository.php`
- `backend/src/Modules/Members/Services/MandateDocumentService.php`
- `backend/src/Modules/Members/Controllers/MandateDocumentController.php`

### Modified backend files
- `backend/src/Shared/Enums/AuditAction.php` — add 2 cases
- `backend/src/Modules/Members/Controllers/AdminController.php` — inject MandateDocumentService, extend `show()` and `anonymize()`
- `backend/src/ServiceFactory.php` — register repository, service, controller
- `backend/src/routes.php` — 3 new routes

### OAS
- `api/admin.yaml` — MandateDocument schema + 3 new endpoints + Member response update

### New frontend files
- `admin-frontend/src/api/mandateDocument.ts` — typed API functions
- `admin-frontend/src/components/MandateDocumentSection.tsx`

### Modified frontend files
- `admin-frontend/src/api/client.ts` — export `adminAxios` instance
- `admin-frontend/src/pages/MembersPage.tsx` — add section to edit modal

### New E2E files
- `e2etests/fixtures/files/` — test image/PDF fixtures
- `e2etests/tests/admin/mandate-document.spec.ts`

---

## Chunk 1: Backend Data Layer

### Task 1: Database migration

**Files:**
- Create: `backend/db/migrations/005_mandate_documents.sql`
- Create: `backend/storage/mandates/.gitkeep`

- [ ] **Step 1: Create storage directory**

```bash
mkdir -p /Users/dg/dev/frgs-vereinsbar/backend/storage/mandates
touch /Users/dg/dev/frgs-vereinsbar/backend/storage/mandates/.gitkeep
```

- [ ] **Step 2: Write migration**

```sql
-- 005_mandate_documents.sql
-- One scanned SEPA mandate PDF per member.
-- extraction_status/extracted_data are placeholders for future LLM extraction.

CREATE TABLE mandate_documents (
    id               CHAR(36)            NOT NULL,
    member_id        CHAR(36)            NOT NULL,
    file_path        VARCHAR(255)        NOT NULL,
    original_filename VARCHAR(255)       NOT NULL,
    file_size_bytes  INT UNSIGNED        NOT NULL,
    extraction_status ENUM('pending','completed','failed') NULL DEFAULT NULL,
    extracted_data   JSON                NULL,
    uploaded_by_admin_id CHAR(36)        NOT NULL,
    created_at       TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_mandate_documents_member (member_id),

    CONSTRAINT fk_mandate_documents_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_mandate_documents_admin
        FOREIGN KEY (uploaded_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 3: Run migration in Docker**

```bash
docker compose exec backend php /app/db/migrate.php
```

Expected: `Applied: 005_mandate_documents.sql`

- [ ] **Step 4: Verify table exists**

```bash
docker compose exec database mysql -u root -proot vereinsbar -e "DESCRIBE mandate_documents;"
```

Expected: table with all 10 columns listed.

- [ ] **Step 5: Commit**

```bash
git add backend/db/migrations/005_mandate_documents.sql backend/storage/mandates/.gitkeep
git commit -m "feat(backend): add mandate_documents migration and storage directory"
```

---

### Task 2: Extend AuditAction enum

**Files:**
- Modify: `backend/src/Shared/Enums/AuditAction.php`

- [ ] **Step 1: Add two new cases**

Open `backend/src/Shared/Enums/AuditAction.php` and add after the last existing case:

```php
    case MANDATE_DOCUMENT_UPLOAD = 'mandate_document_upload';
    case MANDATE_DOCUMENT_DELETE = 'mandate_document_delete';
```

Final file should end with:

```php
    case TOTP_ENROLLED = 'totp_enrolled';
    case TOTP_RESET = 'totp_reset';
    case MANDATE_DOCUMENT_UPLOAD = 'mandate_document_upload';
    case MANDATE_DOCUMENT_DELETE = 'mandate_document_delete';
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
docker compose exec backend php -l /app/src/Shared/Enums/AuditAction.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Shared/Enums/AuditAction.php
git commit -m "feat(backend): add MANDATE_DOCUMENT_UPLOAD and MANDATE_DOCUMENT_DELETE audit actions"
```

---

## Chunk 2: Backend Repository + DTO

### Task 3: MandateDocumentDto

**Files:**
- Create: `backend/src/Modules/Members/DTOs/MandateDocumentDto.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\DTOs;

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
            uploadedAt: \App\Shared\Utils\DateFormatter::toUtcIso($row['updated_at']),
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
```

- [ ] **Step 2: Verify syntax**

```bash
docker compose exec backend php -l /app/src/Modules/Members/DTOs/MandateDocumentDto.php
```

Expected: `No syntax errors detected`

---

### Task 4: MandateDocumentRepository

**Files:**
- Create: `backend/src/Modules/Members/Repositories/MandateDocumentRepository.php`

- [ ] **Step 1: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Repositories;

use App\Shared\Logging\Logger;
use PDO;

class MandateDocumentRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findByMemberId(string $memberId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mandate_documents WHERE member_id = :member_id'
        );
        $stmt->execute(['member_id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Insert on first upload, update on replacement.
     */
    public function upsert(array $data): array
    {
        $existing = $this->findByMemberId($data['member_id']);

        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE mandate_documents
                 SET file_path             = :file_path,
                     original_filename     = :original_filename,
                     file_size_bytes       = :file_size_bytes,
                     uploaded_by_admin_id  = :uploaded_by_admin_id,
                     extraction_status     = NULL,
                     extracted_data        = NULL
                 WHERE member_id = :member_id'
            );
            $stmt->execute([
                'file_path'            => $data['file_path'],
                'original_filename'    => $data['original_filename'],
                'file_size_bytes'      => $data['file_size_bytes'],
                'uploaded_by_admin_id' => $data['uploaded_by_admin_id'],
                'member_id'            => $data['member_id'],
            ]);
        } else {
            $id = $this->generateUuid();
            $stmt = $this->db->prepare(
                'INSERT INTO mandate_documents
                     (id, member_id, file_path, original_filename, file_size_bytes, uploaded_by_admin_id)
                 VALUES
                     (:id, :member_id, :file_path, :original_filename, :file_size_bytes, :uploaded_by_admin_id)'
            );
            $stmt->execute([
                'id'                   => $id,
                'member_id'            => $data['member_id'],
                'file_path'            => $data['file_path'],
                'original_filename'    => $data['original_filename'],
                'file_size_bytes'      => $data['file_size_bytes'],
                'uploaded_by_admin_id' => $data['uploaded_by_admin_id'],
            ]);
        }

        return (array) $this->findByMemberId($data['member_id']);
    }

    public function deleteByMemberId(string $memberId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM mandate_documents WHERE member_id = :member_id'
        );
        $stmt->execute(['member_id' => $memberId]);
        return $stmt->rowCount() > 0;
    }

    private function generateUuid(): string
    {
        // Matches the cryptographically-secure pattern used in all other repositories
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
docker compose exec backend php -l /app/src/Modules/Members/Repositories/MandateDocumentRepository.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/DTOs/MandateDocumentDto.php \
        backend/src/Modules/Members/Repositories/MandateDocumentRepository.php
git commit -m "feat(backend): add MandateDocumentDto and MandateDocumentRepository"
```

---

## Chunk 3: Backend Service

### Task 5: MandateDocumentService

**Files:**
- Create: `backend/src/Modules/Members/Services/MandateDocumentService.php`

- [ ] **Step 1: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\DTOs\MandateDocumentDto;
use App\Modules\Members\Repositories\MandateDocumentRepository;
use App\Modules\Members\Repositories\MembersRepository;
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
        private MembersRepository $membersRepository,
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
```

- [ ] **Step 2: Verify syntax**

```bash
docker compose exec backend php -l /app/src/Modules/Members/Services/MandateDocumentService.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/Services/MandateDocumentService.php
git commit -m "feat(backend): add MandateDocumentService with dompdf image-to-PDF conversion"
```

---

## Chunk 4: Backend Controller + Wiring

### Task 6: MandateDocumentController

**Files:**
- Create: `backend/src/Modules/Members/Controllers/MandateDocumentController.php`

- [ ] **Step 1: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\MandateDocumentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MandateDocumentController
{
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
     * Stream the stored PDF inline.
     * Returns 404 for both "member not found" and "member has no document" —
     * a single neutral message to avoid leaking member existence.
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
            ->withHeader('Content-Disposition', 'inline; filename="mandate.pdf"')
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

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
docker compose exec backend php -l /app/src/Modules/Members/Controllers/MandateDocumentController.php
```

Expected: `No syntax errors detected`

---

### Task 7: ServiceFactory + routes

**Files:**
- Modify: `backend/src/ServiceFactory.php`
- Modify: `backend/src/routes.php`

- [ ] **Step 1: Add imports to ServiceFactory.php**

In the imports section, alongside the other Members imports, add:

```php
use App\Modules\Members\Repositories\MandateDocumentRepository;
use App\Modules\Members\Services\MandateDocumentService;
use App\Modules\Members\Controllers\MandateDocumentController;
```

- [ ] **Step 2: Add FQCN_MAP entry**

In the `FQCN_MAP` constant, inside the `// Members` comment block, add:

```php
MandateDocumentController::class => 'getMandateDocumentController',
```

- [ ] **Step 3: Add getter methods**

After `getMembersRepository()`, add:

```php
public function getMandateDocumentRepository(): MandateDocumentRepository
{
    return $this->resolve(MandateDocumentRepository::class, fn() => new MandateDocumentRepository($this->pdo, $this->logger));
}
```

After `getMembersService()`, add:

```php
public function getMandateDocumentService(): MandateDocumentService
{
    return $this->resolve(MandateDocumentService::class, fn() => new MandateDocumentService(
        $this->getMandateDocumentRepository(),
        $this->getMembersRepository(),
        $this->getAuditService(),
    ));
}
```

After `getMembersAdminController()`, add:

```php
public function getMandateDocumentController(): MandateDocumentController
{
    return $this->resolve(MandateDocumentController::class, fn() => new MandateDocumentController(
        $this->getMandateDocumentService(),
        $this->getMembersRepository(),
    ));
}
```

- [ ] **Step 4: Verify ServiceFactory syntax**

```bash
docker compose exec backend php -l /app/src/ServiceFactory.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5: Add routes to routes.php**

In `routes.php`, inside the `/api/admin` group after the existing `anonymize` and `export` member routes, add:

```php
use App\Modules\Members\Controllers\MandateDocumentController;
```

(Add this import at the top of the file alongside the other use statements.)

Then in the `/api/admin` group, directly after this existing line:
```php
$group->post('/members/{memberId}/anonymize', [MembersAdminController::class, 'anonymize']);
```
add:

```php
// Mandate documents
$group->post('/members/{memberId}/mandate-document', [MandateDocumentController::class, 'upload']);
$group->get('/members/{memberId}/mandate-document', [MandateDocumentController::class, 'download']);
$group->delete('/members/{memberId}/mandate-document', [MandateDocumentController::class, 'delete']);
```

- [ ] **Step 6: Verify routes syntax**

```bash
docker compose exec backend php -l /app/src/routes.php
```

Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add backend/src/Modules/Members/Controllers/MandateDocumentController.php \
        backend/src/ServiceFactory.php \
        backend/src/routes.php
git commit -m "feat(backend): add MandateDocumentController, wire ServiceFactory and routes"
```

---

### Task 8: Extend AdminController — show + anonymize

**Files:**
- Modify: `backend/src/Modules/Members/Controllers/AdminController.php`

The `show()` method must include `mandate_document` in the response. The `anonymize()` method must delete the document before anonymizing.

- [ ] **Step 1: Inject MandateDocumentService into AdminController**

Change the constructor from:

```php
public function __construct(
    private MembersService $membersService,
    private Validator $validator,
    private SepaConfigService $sepaConfigService,
) {}
```

To:

```php
use App\Modules\Members\Services\MandateDocumentService;
// (add to the use block at the top of the file)

public function __construct(
    private MembersService $membersService,
    private Validator $validator,
    private SepaConfigService $sepaConfigService,
    private MandateDocumentService $mandateDocumentService,
) {}
```

- [ ] **Step 2: Extend show() to include mandate_document**

Replace the existing `show()` method body. Note: `membersService->getMember()` throws if the member does not exist (same behaviour as before this change — no regression):


```php
public function show(Request $request, Response $response, array $args): Response
{
    $memberId = $args['memberId'];
    $member   = $this->membersService->getMember($memberId);
    $doc      = $this->mandateDocumentService->findByMemberId($memberId);

    $data                     = $member->toArray();
    $data['mandate_document'] = $doc?->toArray();

    return $this->json($response, $data);
}
```

- [ ] **Step 3: Extend anonymize() to delete document**

Replace the existing `anonymize()` method body:

```php
public function anonymize(Request $request, Response $response, array $args): Response
{
    $memberId = $args['memberId'];
    $adminId  = $request->getAttribute('admin_user_id');

    // GDPR: delete mandate document before anonymizing member record
    $this->mandateDocumentService->deleteForMember($memberId, $adminId);

    $member = $this->membersService->anonymizeMember($memberId, $adminId);

    return $this->json($response, $member->toArray());
}
```

- [ ] **Step 4: Update getMembersAdminController in ServiceFactory**

The constructor now requires a 4th argument. Find `getMembersAdminController()` in `ServiceFactory.php` and update it to inject `$this->getMandateDocumentService()`:

```php
public function getMembersAdminController(): MembersAdminController
{
    return $this->resolve(MembersAdminController::class, fn() => new MembersAdminController(
        $this->getMembersService(),
        $this->getValidator(),
        $this->getSepaConfigService(),
        $this->getMandateDocumentService(),
    ));
}
```

- [ ] **Step 5: Restart PHP-FPM and verify app boots**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
curl -s http://localhost:8080/api/health | jq .
```

Expected: `{ "status": "ok" }`

- [ ] **Step 6: Smoke-test the endpoints exist**

```bash
# Should return 401 (not 404) — routes are registered
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8080/api/admin/members/test/mandate-document
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/admin/members/test/mandate-document
curl -s -o /dev/null -w "%{http_code}" -X DELETE http://localhost:8080/api/admin/members/test/mandate-document
```

Expected: `401` for all three.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Modules/Members/Controllers/AdminController.php \
        backend/src/ServiceFactory.php
git commit -m "feat(backend): extend AdminController show/anonymize with mandate document support"
```

---

## Chunk 5: OAS Schema

### Task 9: Update api/admin.yaml

**Files:**
- Modify: `api/admin.yaml`

- [ ] **Step 1: Add MandateDocument component schema**

In the `components/schemas` section, add:

```yaml
MandateDocument:
  type: object
  required:
    - uploaded_at
    - file_size_bytes
    - original_filename
  properties:
    uploaded_at:
      type: string
      format: date-time
      description: When the document was last uploaded or replaced
    file_size_bytes:
      type: integer
      description: Size of the stored PDF in bytes
    original_filename:
      type: string
      description: Original filename as provided by the client
    extraction_status:
      type: string
      nullable: true
      enum: [pending, completed, failed]
      description: LLM extraction status; null if extraction has never been attempted
```

- [ ] **Step 2: Add mandate_document field to Member response schema**

Find the `Member` (or `MemberAdmin`) schema in `components/schemas` and add:

```yaml
mandate_document:
  nullable: true
  description: Stored mandate document info, or null if none uploaded
  allOf:
    - $ref: '#/components/schemas/MandateDocument'
```

- [ ] **Step 3: Add the 3 new endpoint definitions**

After the `POST /admin/members/{memberId}/anonymize` definition, add:

```yaml
/admin/members/{memberId}/mandate-document:
  parameters:
    - name: memberId
      in: path
      required: true
      schema:
        type: string
        format: uuid
  post:
    summary: Upload or replace mandate document
    tags: [Members]
    requestBody:
      required: true
      content:
        multipart/form-data:
          schema:
            type: object
            required: [file]
            properties:
              file:
                type: string
                format: binary
                description: JPEG, PNG, or PDF (max 10 MB; HEIC converted to JPEG client-side)
    responses:
      '200':
        description: Document created or replaced (upsert)
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/MandateDocument'
      '401':
        $ref: '#/components/responses/Unauthorized'
      '404':
        $ref: '#/components/responses/NotFound'
      '422':
        $ref: '#/components/responses/ValidationError'
  get:
    summary: Stream the stored mandate PDF
    tags: [Members]
    responses:
      '200':
        description: PDF file
        content:
          application/pdf:
            schema:
              type: string
              format: binary
      '401':
        $ref: '#/components/responses/Unauthorized'
      '404':
        $ref: '#/components/responses/NotFound'
  delete:
    summary: Delete mandate document (GDPR)
    tags: [Members]
    responses:
      '204':
        description: Deleted
      '401':
        $ref: '#/components/responses/Unauthorized'
      '404':
        $ref: '#/components/responses/NotFound'
```

- [ ] **Step 4: Commit**

```bash
git add api/admin.yaml
git commit -m "feat(oas): add MandateDocument schema and mandate-document endpoints"
```

---

## Chunk 6: Frontend

### Task 10: Export axios instance + API client functions

**Files:**
- Modify: `admin-frontend/src/api/client.ts`
- Create: `admin-frontend/src/api/mandateDocument.ts`

- [ ] **Step 1: Export adminAxios from client.ts**

At the end of `client.ts`, after the `downloadFile` export, add:

```typescript
// ─── Named export for non-orval API calls (file upload, streaming) ────────────

export { axiosInstance as adminAxios }
```

- [ ] **Step 2: Create mandateDocument.ts**

```typescript
import { adminAxios } from './client'

export interface MandateDocumentInfo {
  uploaded_at: string
  file_size_bytes: number
  original_filename: string
  extraction_status: string | null
}

/**
 * Upload or replace a mandate document for a member.
 * Accepts JPEG, PNG, or PDF (HEIC must be converted to JPEG client-side first).
 */
export async function uploadMandateDocument(
  memberId: string,
  file: File
): Promise<MandateDocumentInfo> {
  const formData = new FormData()
  formData.append('file', file)

  const response = await adminAxios.post<MandateDocumentInfo>(
    `/admin/members/${memberId}/mandate-document`,
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } }
  )
  return response.data
}

/**
 * Open the stored PDF inline in a new browser tab.
 */
export function openMandateDocument(memberId: string): void {
  window.open(`/api/admin/members/${memberId}/mandate-document`, '_blank')
}

/**
 * Delete the stored mandate document.
 */
export async function deleteMandateDocument(memberId: string): Promise<void> {
  await adminAxios.delete(`/admin/members/${memberId}/mandate-document`)
}
```

- [ ] **Step 3: Install npm packages**

```bash
cd admin-frontend && npm install browser-image-compression heic2any
```

- [ ] **Step 4: Verify build compiles**

```bash
cd admin-frontend && npm run type-check
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add admin-frontend/src/api/client.ts \
        admin-frontend/src/api/mandateDocument.ts \
        admin-frontend/package.json \
        admin-frontend/package-lock.json
git commit -m "feat(frontend): add mandate document API client and install compression libs"
```

---

### Task 11: MandateDocumentSection component

**Files:**
- Create: `admin-frontend/src/components/MandateDocumentSection.tsx`

The component has three internal states: `idle` (no file selected), `selected` (file chosen, not yet uploaded), `stored` (document already on server).

- [ ] **Step 1: Write the component**

```tsx
import React, { useRef, useState } from 'react'
import imageCompression from 'browser-image-compression'
import heic2any from 'heic2any'
import {
  MandateDocumentInfo,
  deleteMandateDocument,
  openMandateDocument,
  uploadMandateDocument,
} from '../api/mandateDocument'
import { useTranslation } from 'react-i18next'

interface Props {
  memberId: string
  initialDocument: MandateDocumentInfo | null
}

type ComponentState = 'idle' | 'selected' | 'uploading' | 'stored'

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

export function MandateDocumentSection({ memberId, initialDocument }: Props) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)

  const [state, setState] = useState<ComponentState>(
    initialDocument ? 'stored' : 'idle'
  )
  const [document, setDocument] = useState<MandateDocumentInfo | null>(initialDocument)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [originalSize, setOriginalSize] = useState<number>(0)
  const [error, setError] = useState<string | null>(null)

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.files?.[0]
    if (!raw) return
    setError(null)
    setOriginalSize(raw.size)

    try {
      let processedFile: File = raw

      // Convert HEIC to JPEG first.
      // heic2any returns Blob | Blob[] — take first item when it returns an array
      // (burst/multi-image HEIC files).
      if (raw.type === 'image/heic' || raw.name.toLowerCase().endsWith('.heic')) {
        const result = await heic2any({ blob: raw, toType: 'image/jpeg', quality: 0.85 })
        const blob   = Array.isArray(result) ? result[0] : result
        processedFile = new File(
          [blob],
          raw.name.replace(/\.heic$/i, '.jpg'),
          { type: 'image/jpeg' }
        )
      }

      // Compress images (not PDFs)
      if (processedFile.type !== 'application/pdf') {
        processedFile = await imageCompression(processedFile, {
          maxSizeMB: 2,
          maxWidthOrHeight: 2000,
          useWebWorker: true,
        })
      }

      setSelectedFile(processedFile)
      setState('selected')
    } catch (err) {
      setError(t('mandateDocument.processingError'))
    }

    // Reset input so the same file can be re-selected if cancelled
    if (inputRef.current) inputRef.current.value = ''
  }

  async function handleUpload() {
    if (!selectedFile) return
    setState('uploading')
    setError(null)

    try {
      const doc = await uploadMandateDocument(memberId, selectedFile)
      setDocument(doc)
      setSelectedFile(null)
      setState('stored')
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { messages?: { file?: string[] } } } })
          ?.response?.data?.messages?.file?.[0] ?? t('mandateDocument.uploadError')
      setError(msg)
      setState('selected')
    }
  }

  function handleCancel() {
    setSelectedFile(null)
    setError(null)
    setState(document ? 'stored' : 'idle')
  }

  function handleReplace() {
    setState('idle')
    setSelectedFile(null)
  }

  return (
    <div
      style={{
        borderTop: '1px solid #e2e8f0',
        paddingTop: '16px',
        marginTop: '8px',
      }}
      data-testid="mandate-document-section"
    >
      <div
        style={{
          fontSize: '11px',
          fontWeight: 600,
          color: '#64748b',
          letterSpacing: '0.05em',
          textTransform: 'uppercase',
          marginBottom: '10px',
        }}
      >
        {t('mandateDocument.title')}
      </div>

      {error && (
        <div
          style={{
            color: '#dc2626',
            fontSize: '12px',
            marginBottom: '8px',
            padding: '6px 10px',
            background: '#fef2f2',
            borderRadius: '4px',
          }}
          data-testid="mandate-document-error"
        >
          {error}
        </div>
      )}

      {/* ── Idle: file picker ── */}
      {state === 'idle' && (
        <label
          style={{
            display: 'block',
            border: '2px dashed #cbd5e1',
            borderRadius: '8px',
            padding: '20px',
            textAlign: 'center',
            cursor: 'pointer',
            color: '#94a3b8',
          }}
          data-testid="mandate-document-dropzone"
        >
          <input
            ref={inputRef}
            type="file"
            accept="image/*,.pdf"
            style={{ display: 'none' }}
            onChange={handleFileChange}
            data-testid="mandate-document-input"
          />
          <div style={{ fontSize: '24px', marginBottom: '6px' }}>📎</div>
          <div style={{ fontSize: '13px', fontWeight: 500, color: '#475569', marginBottom: '4px' }}>
            {t('mandateDocument.dropzone')}
          </div>
          <div style={{ fontSize: '11px' }}>JPEG · PNG · HEIC · PDF</div>
        </label>
      )}

      {/* ── Selected: preview before upload ── */}
      {(state === 'selected' || state === 'uploading') && selectedFile && (
        <div
          style={{
            border: '2px solid #3b82f6',
            borderRadius: '8px',
            padding: '12px',
            background: '#eff6ff',
          }}
          data-testid="mandate-document-preview"
        >
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', marginBottom: '10px' }}>
            <div style={{ fontSize: '28px', flexShrink: 0 }}>
              {selectedFile.type === 'application/pdf' ? '📄' : '🖼️'}
            </div>
            <div>
              <div style={{ fontSize: '13px', fontWeight: 600, color: '#1e40af' }}>
                {selectedFile.name}
              </div>
              <div style={{ fontSize: '11px', color: '#64748b' }}>
                {formatBytes(originalSize)} → {formatBytes(selectedFile.size)}{' '}
                {selectedFile.type !== 'application/pdf' && `(${t('mandateDocument.compressed')})`}
              </div>
              {selectedFile.type !== 'application/pdf' && (
                <div style={{ fontSize: '11px', color: '#94a3b8' }}>
                  {t('mandateDocument.willConvert')}
                </div>
              )}
            </div>
          </div>
          <div style={{ display: 'flex', gap: '8px' }}>
            <button
              onClick={handleUpload}
              disabled={state === 'uploading'}
              style={{
                flex: 1,
                padding: '8px',
                background: state === 'uploading' ? '#93c5fd' : '#3b82f6',
                color: 'white',
                border: 'none',
                borderRadius: '6px',
                cursor: state === 'uploading' ? 'wait' : 'pointer',
                fontSize: '13px',
                fontWeight: 500,
              }}
              data-testid="mandate-document-upload-btn"
            >
              {state === 'uploading' ? t('mandateDocument.uploading') : t('mandateDocument.upload')}
            </button>
            {state !== 'uploading' && (
              <button
                onClick={handleCancel}
                style={{
                  padding: '8px 12px',
                  background: '#f1f5f9',
                  color: '#64748b',
                  border: 'none',
                  borderRadius: '6px',
                  cursor: 'pointer',
                  fontSize: '13px',
                }}
                data-testid="mandate-document-cancel-btn"
              >
                ✕
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── Stored: document info ── */}
      {state === 'stored' && document && (
        <div
          style={{
            border: '1px solid #bbf7d0',
            borderRadius: '8px',
            padding: '12px',
            background: '#f0fdf4',
          }}
          data-testid="mandate-document-stored"
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '10px' }}>
            <div style={{ fontSize: '28px' }}>📄</div>
            <div>
              <div
                style={{ fontSize: '13px', fontWeight: 600, color: '#166534' }}
                data-testid="mandate-document-filename"
              >
                {document.original_filename}
              </div>
              <div style={{ fontSize: '11px', color: '#64748b' }}>
                {formatBytes(document.file_size_bytes)} · {t('mandateDocument.uploaded')}{' '}
                {formatDate(document.uploaded_at)}
              </div>
            </div>
          </div>
          <div style={{ display: 'flex', gap: '8px' }}>
            <button
              onClick={() => openMandateDocument(memberId)}
              style={{
                flex: 1,
                padding: '8px',
                background: '#f8fafc',
                border: '1px solid #e2e8f0',
                borderRadius: '6px',
                cursor: 'pointer',
                fontSize: '13px',
              }}
              data-testid="mandate-document-view-btn"
            >
              👁 {t('mandateDocument.view')}
            </button>
            <button
              onClick={handleReplace}
              style={{
                padding: '8px 12px',
                background: '#fef2f2',
                color: '#dc2626',
                border: '1px solid #fecaca',
                borderRadius: '6px',
                cursor: 'pointer',
                fontSize: '13px',
              }}
              data-testid="mandate-document-replace-btn"
            >
              {t('mandateDocument.replace')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
```

- [ ] **Step 2: Add i18n keys**

Open `admin-frontend/src/locales/de.json` (and `en.json`). Add the following under a `mandateDocument` key:

```json
"mandateDocument": {
  "title": "SEPA-Mandat Dokument",
  "dropzone": "Datei hier ablegen oder klicken",
  "compressed": "komprimiert",
  "willConvert": "Wird zu PDF konvertiert",
  "upload": "Hochladen",
  "uploading": "Wird hochgeladen…",
  "view": "Ansehen",
  "replace": "Ersetzen",
  "uploaded": "Hochgeladen",
  "processingError": "Datei konnte nicht verarbeitet werden.",
  "uploadError": "Upload fehlgeschlagen."
}
```

For `en.json`:

```json
"mandateDocument": {
  "title": "SEPA Mandate Document",
  "dropzone": "Drop file here or click to browse",
  "compressed": "compressed",
  "willConvert": "Will be converted to PDF",
  "upload": "Upload",
  "uploading": "Uploading…",
  "view": "View",
  "replace": "Replace",
  "uploaded": "Uploaded",
  "processingError": "Could not process file.",
  "uploadError": "Upload failed."
}
```

- [ ] **Step 3: Type-check**

```bash
cd admin-frontend && npm run type-check
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add admin-frontend/src/components/MandateDocumentSection.tsx \
        admin-frontend/src/locales/
git commit -m "feat(frontend): add MandateDocumentSection component"
```

---

### Task 12: Integrate MandateDocumentSection into MembersPage

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`

The `MandateDocumentSection` must appear at the bottom of the **edit** modal (not the create modal). The full member response from `GET /admin/members/{memberId}` already includes `mandate_document` after our backend change.

- [ ] **Step 1: Import the component**

At the top of `MembersPage.tsx`, add:

```tsx
import { MandateDocumentSection } from '../components/MandateDocumentSection'
```

- [ ] **Step 2: Update the Member type to include mandate_document**

First, import `MandateDocumentInfo` at the top of `MembersPage.tsx`:

```typescript
import type { MandateDocumentInfo } from '../api/mandateDocument'
```

Then find the local `Member` type or interface definition and add the field using the imported type (no inline duplication):

```typescript
mandate_document: MandateDocumentInfo | null
```

- [ ] **Step 3: Add the section to the edit modal**

Locate the section inside the modal that is only shown when `editingMember` is set (the SEPA status indicator area). After the SEPA status badge but before the form's submit buttons, add:

```tsx
{editingMember && (
  <MandateDocumentSection
    memberId={editingMember.id}
    initialDocument={editingMember.mandate_document ?? null}
  />
)}
```

- [ ] **Step 4: Type-check and build**

```bash
cd admin-frontend && npm run type-check && npm run build
```

Expected: no errors, build succeeds.

- [ ] **Step 5: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(frontend): integrate MandateDocumentSection into member edit modal"
```

---

## Chunk 7: E2E Tests

### Task 13: Create test fixture files

**Files:**
- Create: `e2etests/fixtures/files/test-mandate.jpg`
- Create: `e2etests/fixtures/files/test-mandate.png`
- Create: `e2etests/fixtures/files/test-mandate.pdf`
- Create: `e2etests/fixtures/files/test-mandate-large.jpg`

These are static binary fixtures committed to the repo. Create them once with:

- [ ] **Step 1: Create fixture directory**

```bash
mkdir -p e2etests/fixtures/files
```

- [ ] **Step 2: Generate fixtures with Node.js**

Run this script from the project root to create minimal valid test files:

```bash
node -e "
const fs = require('fs');
const path = require('path');
const dir = 'e2etests/fixtures/files';

// Minimal valid 1×1 white JPEG (baseline JFIF)
const jpegBytes = Buffer.from(
  'ffd8ffe000104a46494600010100000100010000' +
  'ffdb004300080606070605080707070909080a0c' +
  '140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20' +
  '242e2720222c231c1c2837292c30313434341f27' +
  '39' + '3d38323c2e333432' +
  'ffc0000b080001000101011100ffc4001f000001' +
  '05010101010100000000000000000102030405060' +
  '70809' + '0affc40000ffda0008010100003f00f50000' +
  'ffd9',
  'hex'
);

// Use a real minimal JPEG
const minJpeg = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoH' +
'BwYIDAoMCwsKCwsNCxAQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBD' +
'AQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU' +
'FBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/' +
'EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/' +
'aAAwDAQACEQMRAD8AJQAB/9k=', 'base64');

fs.writeFileSync(path.join(dir, 'test-mandate.jpg'), minJpeg);
fs.writeFileSync(path.join(dir, 'test-mandate.png'), Buffer.from(
  '89504e470d0a1a0a0000000d49484452000000010000000108020000009001' +
  '2e00000000c4944415478016360f8cfc00000000200016d8f58c0000000049454e44ae426082',
  'hex'
));

// Minimal PDF
const pdf = '%PDF-1.4\n1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n' +
  '2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n' +
  '3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]>>\nendobj\n' +
  'xref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000062 00000 n\n' +
  '0000000119 00000 n\ntrailer\n<</Size 4 /Root 1 0 R>>\nstartxref\n196\n%%EOF';
fs.writeFileSync(path.join(dir, 'test-mandate.pdf'), pdf);

console.log('Fixtures created in', dir);
"
```

- [ ] **Step 3: Create large JPEG fixture using ImageMagick**

The large fixture must be a **valid JPEG** (dompdf will try to render it server-side). Use ImageMagick:

```bash
# Creates a valid 3000×2000 JPEG (~2–3 MB depending on compression)
convert -size 3000x2000 gradient:white-gray e2etests/fixtures/files/test-mandate-large.jpg
```

If ImageMagick is not installed locally:
```bash
brew install imagemagick   # macOS
# then re-run the convert command above
```

Verify it is a real JPEG and ≥1 MB:
```bash
file e2etests/fixtures/files/test-mandate-large.jpg   # should say "JPEG image"
wc -c e2etests/fixtures/files/test-mandate-large.jpg  # should be ≥ 500000 bytes
```

- [ ] **Step 4: Add HEIC fixture note**

HEIC files cannot be generated programmatically without platform-native tools. For CI:
- Save `e2etests/fixtures/files/test-mandate.heic` from an iPhone photo, or
- Skip the HEIC test on CI (mark with `test.skip` if file absent).

- [ ] **Step 5: Commit fixtures**

```bash
git add e2etests/fixtures/files/
git commit -m "test(e2e): add mandate document test fixtures"
```

---

### Task 14: E2E tests

**Files:**
- Create: `e2etests/tests/admin/mandate-document.spec.ts`

- [ ] **Step 1: Write the failing tests first**

```typescript
import { test, expect } from '../../fixtures/pageObjects'
import { resolve } from 'path'
import { readFileSync, existsSync } from 'fs'
import { csrfHeaders } from '../../utils/csrf'

const FIXTURES = resolve(__dirname, '../../fixtures/files')

// Helper: create an isolated member and return its id
async function createTestMember(page: import('@playwright/test').Page): Promise<string> {
  const ts = Date.now()
  const resp = await page.request.post('http://localhost:8080/api/admin/members', {
    data: {
      first_name: `MandateTest`,
      last_name: `User${ts}`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2025-01-01',
      preferred_language: 'de',
    },
    headers: await csrfHeaders(page),
  })
  expect(resp.ok()).toBe(true)
  const body = await resp.json()
  return body.id
}

test.describe('Mandate Document API', () => {
  // ── Upload: JPEG ──────────────────────────────────────────────────────────
  test('POST uploads JPEG and returns document info', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members') // triggers auth
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.jpg', mimeType: 'image/jpeg', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.jpg')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body).toHaveProperty('uploaded_at')
    expect(body).toHaveProperty('file_size_bytes')
    expect(body.original_filename).toBe('test-mandate.jpg')
    expect(body.extraction_status).toBeNull()
  })

  // ── Upload: PNG ───────────────────────────────────────────────────────────
  test('POST uploads PNG and returns document info', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.png', mimeType: 'image/png', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.png')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body.original_filename).toBe('test-mandate.png')
  })

  // ── Upload: PDF ───────────────────────────────────────────────────────────
  test('POST uploads PDF stored as-is', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body.original_filename).toBe('test-mandate.pdf')
  })

  // ── GET streams PDF ───────────────────────────────────────────────────────
  test('GET returns PDF after upload', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    // Upload first
    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )

    expect(getResp.status()).toBe(200)
    expect(getResp.headers()['content-type']).toContain('application/pdf')
  })

  // ── GET: no document → 404 ────────────────────────────────────────────────
  test('GET returns 404 when no document exists', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )

    expect(resp.status()).toBe(404)
  })

  // ── Replace existing document ─────────────────────────────────────────────
  test('POST replaces existing document', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    // Upload JPEG first
    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.jpg', mimeType: 'image/jpeg', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.jpg')) } },
        headers: await csrfHeaders(page),
      }
    )

    // Replace with PDF
    const replaceResp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(replaceResp.status()).toBe(200)
    const body = await replaceResp.json()
    expect(body.original_filename).toBe('test-mandate.pdf')

    // GET should return the new file
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(200)
  })

  // ── DELETE ────────────────────────────────────────────────────────────────
  test('DELETE removes document and GET returns 404', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    // Upload
    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    // Delete
    const delResp = await page.request.delete(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      { headers: await csrfHeaders(page) }
    )
    expect(delResp.status()).toBe(204)

    // GET should now be 404
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(404)
  })

  // ── GDPR anonymize deletes document ───────────────────────────────────────
  test('anonymize deletes mandate document', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    // Upload
    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    // Anonymize member
    const anonResp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/anonymize`,
      { headers: await csrfHeaders(page) }
    )
    expect(anonResp.ok()).toBe(true)

    // Document should be gone
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(404)
  })

  // ── Invalid file type → 422 ───────────────────────────────────────────────
  test('POST rejects unsupported file type', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'doc.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: Buffer.from('fake docx') } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(422)
  })

  // ── Large image: uploaded size is smaller than input ─────────────────────
  test('POST with large JPEG stores compressed file smaller than input', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    const largeFile = readFileSync(resolve(FIXTURES, 'test-mandate-large.jpg'))

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate-large.jpg', mimeType: 'image/jpeg', buffer: largeFile } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    // Stored as PDF; the dompdf output should be smaller than the raw input JPEG
    expect(body.file_size_bytes).toBeLessThan(largeFile.length)
  })

  // ── HEIC upload (skipped in CI if fixture absent) ─────────────────────────
  test('POST uploads HEIC converted to PDF', async ({ page }) => {
    const heicPath = resolve(FIXTURES, 'test-mandate.heic')
    if (!existsSync(heicPath)) {
      test.skip(true, 'HEIC fixture not available — add e2etests/fixtures/files/test-mandate.heic from an iPhone photo')
      return
    }

    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.heic', mimeType: 'image/heic', buffer: readFileSync(heicPath) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.headers()['content-type']).toContain('application/pdf')
  })

  // ── Non-existent member → 404 ─────────────────────────────────────────────
  test('POST returns 404 for non-existent member', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')

    const resp = await page.request.post(
      'http://localhost:8080/api/admin/members/00000000-0000-0000-0000-000000000000/mandate-document',
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(404)
  })

  // ── GET /admin/members/{id} includes mandate_document field ───────────────
  test('GET member response includes mandate_document field', async ({ page }) => {
    await page.goto('http://localhost:8080/admin/members')
    const memberId = await createTestMember(page)

    // Before upload: null
    const before = await page.request.get(`http://localhost:8080/api/admin/members/${memberId}`)
    expect((await before.json()).mandate_document).toBeNull()

    // Upload
    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    // After upload: has fields
    const after = await page.request.get(`http://localhost:8080/api/admin/members/${memberId}`)
    const doc = (await after.json()).mandate_document
    expect(doc).not.toBeNull()
    expect(doc).toHaveProperty('uploaded_at')
    expect(doc).toHaveProperty('file_size_bytes')
    expect(doc.original_filename).toBe('test-mandate.pdf')
    expect(doc.extraction_status).toBeNull()
  })
})
```

- [ ] **Step 2: Run tests (expect failures — backend not yet deployed)**

```bash
cd e2etests && npm test -- tests/admin/mandate-document.spec.ts --workers=1
```

Expected: tests fail with connection errors if backend not running, or 401 if auth not configured — verify the test structure itself runs without syntax errors.

- [ ] **Step 3: Restart PHP and run tests for real**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
cd e2etests && npm test -- tests/admin/mandate-document.spec.ts --workers=4
```

Expected: all tests pass.

- [ ] **Step 4: Run full test suite to check for regressions**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: all existing tests still pass.

- [ ] **Step 5: Commit**

```bash
git add e2etests/tests/admin/mandate-document.spec.ts
git commit -m "test(e2e): add mandate document E2E tests (upload, replace, delete, GDPR, validation)"
```
