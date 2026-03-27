# SEPA Mandate LLM Extraction — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After a scanned SEPA mandate document is uploaded, automatically call an LLM vision API (Anthropic or OpenAI, configured via `.env`) to extract member fields (IBAN, name, mandate date, etc.) with per-field confidence levels, pre-fill the member edit form, and support a "create member from scan" flow.

**Architecture:** Thin custom LLM client layer (`LlmClientInterface` + `AnthropicClient` + `OpenAiClient` + `LlmClientFactory`) with no new Composer dependencies (native cURL). `ExtractionService` builds the prompt and parses the response. `MandateDocumentService` calls extraction synchronously after upload and writes results to `extracted_data` / `extraction_status`. A new `ExtractionController` serves a standalone extract endpoint for the create-from-scan flow.

**Tech Stack:** PHP 8.3 / Slim 4 / PDO (backend), React 18 / TypeScript / i18next (frontend), Playwright (E2E). No new Composer or npm packages required.

**Spec:** `docs/superpowers/specs/2026-03-26-sepa-mandate-extraction-design.md`

---

## File Map

### New backend files
- `backend/src/Modules/Members/Contracts/LlmClientInterface.php` — interface: `extractFromImage(base64, mimeType, prompt): string`
- `backend/src/Modules/Members/LlmClients/AnthropicClient.php` — cURL call to Anthropic messages API
- `backend/src/Modules/Members/LlmClients/OpenAiClient.php` — cURL call to OpenAI chat completions API
- `backend/src/Modules/Members/Factories/LlmClientFactory.php` — reads `LLM_PROVIDER`, returns `?LlmClientInterface`
- `backend/src/Modules/Members/ValueObjects/ExtractionResult.php` — holds fields + `toArray()`
- `backend/src/Modules/Members/Services/ExtractionService.php` — prompt builder + JSON parser
- `backend/src/Modules/Members/Controllers/ExtractionController.php` — `POST /api/admin/mandate-document/extract`
- `backend/tests/Unit/Modules/Members/Services/ExtractionServiceTest.php`

### Modified backend files
- `backend/src/Shared/Config/AppConfig.php` — add `llmProvider`, `llmApiKey`, `llmModel` (all nullable)
- `backend/src/Modules/Members/DTOs/MandateDocumentDto.php` — add `extraction: ?array` field
- `backend/src/Modules/Members/Repositories/MandateDocumentRepository.php` — add `updateExtraction()`
- `backend/src/Modules/Members/Services/MandateDocumentService.php` — inject `?ExtractionService` + `Logger`, run extraction after upload
- `backend/src/ServiceFactory.php` — register `LlmClientFactory`, `ExtractionService`, `ExtractionController`; inject into `MandateDocumentService`
- `backend/src/routes.php` — add `POST /api/admin/mandate-document/extract`
- `backend/.env` — add LLM vars as commented examples

### OAS
- `api/admin.yaml` — add `ExtractionField`, `ExtractionResult` schemas; update `MandateDocument` response; add extract endpoint

### New frontend files
- `admin-frontend/src/api/generated/extractionField.ts` — generated type
- `admin-frontend/src/api/generated/extractionResult.ts` — generated type
- `admin-frontend/src/api/extractMandateDocument.ts` — `extractMandateDocument(file): Promise<ExtractionResult>`

### Modified frontend files
- `admin-frontend/src/api/generated/mandateDocument.ts` — add `extraction` field
- `admin-frontend/public/locales/de.json` — add extraction i18n keys
- `admin-frontend/public/locales/en.json` — add extraction i18n keys
- `admin-frontend/src/components/MandateDocumentSection.tsx` — add `onExtractionComplete` callback, show "Uploading & extracting…" state
- `admin-frontend/src/pages/MembersPage.tsx` — show extraction badges on form fields, "Discard" button, "New from scan" button + flow

### New E2E files
- `e2etests/tests/admin/mandate-document-extraction.spec.ts`

---

## Chunk 1: Backend — Config & LLM Client Layer

### Task 1: AppConfig + .env

**Files:**
- Modify: `backend/src/Shared/Config/AppConfig.php`
- Modify: `backend/.env`

- [ ] **Step 1: Add LLM properties to AppConfig**

```php
// backend/src/Shared/Config/AppConfig.php
// Add to the class body after $appUrl:

    public readonly ?string $llmProvider;
    public readonly ?string $llmApiKey;
    public readonly ?string $llmModel;
```

```php
// In __construct(), after $this->appUrl = ...:
        $this->llmProvider = Env::get('LLM_PROVIDER', '') ?: null;
        $this->llmApiKey   = Env::get('LLM_API_KEY', '') ?: null;
        $this->llmModel    = Env::get('LLM_MODEL', '') ?: null;
```

- [ ] **Step 2: Add commented LLM vars to .env**

Append to `backend/.env`:
```
# LLM Extraction (optional)
# LLM_PROVIDER=anthropic
# LLM_API_KEY=sk-ant-...
# LLM_MODEL=
```

- [ ] **Step 3: Verify PHP syntax**

```bash
php -l backend/src/Shared/Config/AppConfig.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add backend/src/Shared/Config/AppConfig.php backend/.env
git commit -m "feat(extraction): add LLM config vars to AppConfig and .env"
```

---

### Task 2: LlmClientInterface + ExtractionResult

**Files:**
- Create: `backend/src/Modules/Members/Contracts/LlmClientInterface.php`
- Create: `backend/src/Modules/Members/ValueObjects/ExtractionResult.php`

- [ ] **Step 1: Create interface**

```php
<?php
// backend/src/Modules/Members/Contracts/LlmClientInterface.php

declare(strict_types=1);

namespace App\Modules\Members\Contracts;

interface LlmClientInterface
{
    /**
     * Send image bytes (base64-encoded) and a prompt to the LLM.
     * Returns the raw text content of the model response.
     *
     * @throws \RuntimeException on API or network failure
     */
    public function extractFromImage(string $base64, string $mimeType, string $prompt): string;
}
```

- [ ] **Step 2: Create ExtractionResult value object**

```php
<?php
// backend/src/Modules/Members/ValueObjects/ExtractionResult.php

declare(strict_types=1);

namespace App\Modules\Members\ValueObjects;

/**
 * Holds per-field extraction results from an LLM vision call.
 *
 * $fields shape:
 *   ['first_name' => ['value' => 'Max', 'confidence' => 'high'], ...]
 *   value and confidence are null when the field was not found or illegible.
 */
final class ExtractionResult
{
    /**
     * @param array<string, array{value: string|null, confidence: string|null}> $fields
     */
    public function __construct(
        public readonly array $fields,
    ) {}

    public function toArray(): array
    {
        return ['fields' => $this->fields];
    }
}
```

- [ ] **Step 3: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/Contracts/LlmClientInterface.php
php -l backend/src/Modules/Members/ValueObjects/ExtractionResult.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Modules/Members/Contracts/LlmClientInterface.php \
        backend/src/Modules/Members/ValueObjects/ExtractionResult.php
git commit -m "feat(extraction): add LlmClientInterface and ExtractionResult value object"
```

---

### Task 3: AnthropicClient

**Files:**
- Create: `backend/src/Modules/Members/LlmClients/AnthropicClient.php`

- [ ] **Step 1: Create AnthropicClient**

```php
<?php
// backend/src/Modules/Members/LlmClients/AnthropicClient.php

declare(strict_types=1);

namespace App\Modules\Members\LlmClients;

use App\Modules\Members\Contracts\LlmClientInterface;

class AnthropicClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function extractFromImage(string $base64, string $mimeType, string $prompt): string
    {
        // Anthropic supports both images and PDFs natively via different content types.
        $contentType = $mimeType === 'application/pdf' ? 'document' : 'image';

        $payload = [
            'model'      => $this->model,
            'max_tokens' => 1024,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => $contentType,
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mimeType,
                            'data'       => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new \RuntimeException('Anthropic API cURL error: ' . $curlError);
        }

        $body = json_decode((string) $response, true);

        if ($httpCode !== 200) {
            $msg = $body['error']['message'] ?? $response;
            throw new \RuntimeException("Anthropic API error {$httpCode}: {$msg}");
        }

        return (string) ($body['content'][0]['text'] ?? '');
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/LlmClients/AnthropicClient.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/LlmClients/AnthropicClient.php
git commit -m "feat(extraction): add AnthropicClient LLM implementation"
```

---

### Task 4: OpenAiClient

**Files:**
- Create: `backend/src/Modules/Members/LlmClients/OpenAiClient.php`

- [ ] **Step 1: Create OpenAiClient**

```php
<?php
// backend/src/Modules/Members/LlmClients/OpenAiClient.php

declare(strict_types=1);

namespace App\Modules\Members\LlmClients;

use App\Modules\Members\Contracts\LlmClientInterface;

class OpenAiClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function extractFromImage(string $base64, string $mimeType, string $prompt): string
    {
        // OpenAI vision requires an image, not a PDF.
        // PDF uploads with the OpenAI provider will produce extraction_status: 'failed'.
        if ($mimeType === 'application/pdf') {
            throw new \RuntimeException(
                'OpenAI provider does not support PDF extraction. Upload a JPEG or PNG, or switch to the Anthropic provider.'
            );
        }

        $payload = [
            'model'           => $this->model,
            'max_tokens'      => 1024,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64}",
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new \RuntimeException('OpenAI API cURL error: ' . $curlError);
        }

        $body = json_decode((string) $response, true);

        if ($httpCode !== 200) {
            $msg = $body['error']['message'] ?? $response;
            throw new \RuntimeException("OpenAI API error {$httpCode}: {$msg}");
        }

        return (string) ($body['choices'][0]['message']['content'] ?? '');
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/LlmClients/OpenAiClient.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/LlmClients/OpenAiClient.php
git commit -m "feat(extraction): add OpenAiClient LLM implementation"
```

---

### Task 5: LlmClientFactory

**Files:**
- Create: `backend/src/Modules/Members/Factories/LlmClientFactory.php`

- [ ] **Step 1: Create factory**

```php
<?php
// backend/src/Modules/Members/Factories/LlmClientFactory.php

declare(strict_types=1);

namespace App\Modules\Members\Factories;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\LlmClients\AnthropicClient;
use App\Modules\Members\LlmClients\OpenAiClient;
use App\Shared\Config\AppConfig;

class LlmClientFactory
{
    public function __construct(private AppConfig $config) {}

    /**
     * Returns null when LLM is not configured (LLM_PROVIDER or LLM_API_KEY absent).
     * Extraction is silently skipped when this returns null.
     *
     * @throws \RuntimeException for unknown provider values
     */
    public function create(): ?LlmClientInterface
    {
        if ($this->config->llmProvider === null || $this->config->llmApiKey === null) {
            return null;
        }

        return match ($this->config->llmProvider) {
            'anthropic' => new AnthropicClient(
                $this->config->llmApiKey,
                $this->config->llmModel ?? 'claude-haiku-4-5-20251001',
            ),
            'openai' => new OpenAiClient(
                $this->config->llmApiKey,
                $this->config->llmModel ?? 'gpt-4o-mini',
            ),
            default => throw new \RuntimeException(
                "Unknown LLM_PROVIDER: '{$this->config->llmProvider}'. Valid values: anthropic, openai"
            ),
        };
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/Factories/LlmClientFactory.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/Factories/LlmClientFactory.php
git commit -m "feat(extraction): add LlmClientFactory (anthropic/openai, returns null if unconfigured)"
```

---

## Chunk 2: Backend — Extraction Service

### Task 6: ExtractionService + unit tests

**Files:**
- Create: `backend/src/Modules/Members/Services/ExtractionService.php`
- Create: `backend/tests/Unit/Modules/Members/Services/ExtractionServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Unit/Modules/Members/Services/ExtractionServiceTest.php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\Services\ExtractionService;
use PHPUnit\Framework\TestCase;

class ExtractionServiceTest extends TestCase
{
    private function makeService(string $llmResponse): ExtractionService
    {
        $mockClient = $this->createMock(LlmClientInterface::class);
        $mockClient->method('extractFromImage')->willReturn($llmResponse);
        return new ExtractionService($mockClient);
    }

    private function fullResponse(array $overrides = []): string
    {
        $fields = array_merge([
            'first_name'           => ['value' => 'Max',                    'confidence' => 'high'],
            'last_name'            => ['value' => 'Mustermann',             'confidence' => 'high'],
            'email'                => ['value' => 'max@example.com',        'confidence' => 'medium'],
            'iban'                 => ['value' => 'DE89370400440532013000', 'confidence' => 'high'],
            'account_holder_name'  => ['value' => 'Max Mustermann',         'confidence' => 'medium'],
            'mandate_signed_at'    => ['value' => '2026-01-15',             'confidence' => 'high'],
        ], $overrides);
        return json_encode(['fields' => $fields]);
    }

    public function test_extract_parses_all_fields_with_confidence(): void
    {
        $service = $this->makeService($this->fullResponse());
        $result  = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('Max',                    $result->fields['first_name']['value']);
        $this->assertSame('high',                   $result->fields['first_name']['confidence']);
        $this->assertSame('DE89370400440532013000', $result->fields['iban']['value']);
        $this->assertSame('medium',                 $result->fields['account_holder_name']['confidence']);
    }

    public function test_extract_handles_markdown_wrapped_json(): void
    {
        $wrapped = "```json\n" . $this->fullResponse() . "\n```";
        $service = $this->makeService($wrapped);
        $result  = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('Max', $result->fields['first_name']['value']);
    }

    public function test_extract_sets_null_for_missing_fields(): void
    {
        // LLM only returns some fields — missing ones default to null/null
        $partial = json_encode(['fields' => [
            'first_name' => ['value' => 'Anna', 'confidence' => 'high'],
        ]]);
        $service = $this->makeService($partial);
        $result  = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('Anna', $result->fields['first_name']['value']);
        $this->assertNull($result->fields['last_name']['value']);
        $this->assertNull($result->fields['last_name']['confidence']);
    }

    public function test_extract_normalises_unknown_confidence_to_null(): void
    {
        $response = $this->fullResponse([
            'first_name' => ['value' => 'Max', 'confidence' => 'very_high'],
        ]);
        $service = $this->makeService($response);
        $result  = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertNull($result->fields['first_name']['confidence']);
    }

    public function test_extract_throws_on_invalid_json(): void
    {
        $service = $this->makeService('This is not JSON');
        $this->expectException(\RuntimeException::class);
        $service->extract('fake-bytes', 'image/jpeg');
    }

    public function test_to_array_returns_fields_key(): void
    {
        $service = $this->makeService($this->fullResponse());
        $result  = $service->extract('fake-bytes', 'image/jpeg');
        $arr     = $result->toArray();

        $this->assertArrayHasKey('fields', $arr);
        $this->assertArrayHasKey('first_name', $arr['fields']);
    }
}
```

- [ ] **Step 2: Run tests — verify they fail (class not found)**

```bash
cd /Users/dg/dev/frgs-vereinsbar/backend
php artisan test tests/Unit/Modules/Members/Services/ExtractionServiceTest.php 2>&1 | head -20
```
Expected: error about `ExtractionService` class not found.

- [ ] **Step 3: Create ExtractionService**

```php
<?php
// backend/src/Modules/Members/Services/ExtractionService.php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\ValueObjects\ExtractionResult;

class ExtractionService
{
    private const EXTRACTABLE_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'iban',
        'account_holder_name',
        'mandate_signed_at',
    ];

    public function __construct(
        private LlmClientInterface $client,
    ) {}

    /**
     * Extract SEPA mandate fields from raw image/PDF bytes.
     *
     * @throws \RuntimeException when the LLM call fails or returns unparseable JSON
     */
    public function extract(string $bytes, string $mimeType): ExtractionResult
    {
        $base64  = base64_encode($bytes);
        $prompt  = $this->buildPrompt();
        $rawJson = $this->client->extractFromImage($base64, $mimeType, $prompt);
        return $this->parseResponse($rawJson);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
You are extracting data from a scanned SEPA direct debit mandate form.
Extract the following fields and return ONLY valid JSON in this exact format — no markdown, no explanation, no additional text:
{
  "fields": {
    "first_name":          {"value": "...", "confidence": "high"},
    "last_name":           {"value": "...", "confidence": "high"},
    "email":               {"value": "...", "confidence": "high"},
    "iban":                {"value": "...", "confidence": "high"},
    "account_holder_name": {"value": "...", "confidence": "high"},
    "mandate_signed_at":   {"value": "YYYY-MM-DD", "confidence": "high"}
  }
}
Rules:
- confidence must be "high", "medium", or "low"
- Use null for value AND confidence when a field is absent, blank, or illegible
- For IBAN: remove all spaces
- For mandate_signed_at: use YYYY-MM-DD format only
PROMPT;
    }

    private function parseResponse(string $rawJson): ExtractionResult
    {
        // Strip markdown code fences if the model wrapped its response
        $json = preg_replace('/^```json?\s*/m', '', $rawJson) ?? $rawJson;
        $json = preg_replace('/^```\s*$/m', '', $json) ?? $json;
        $json = trim($json);

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['fields'])) {
            throw new \RuntimeException(
                'LLM returned invalid JSON (no "fields" key): ' . substr($rawJson, 0, 300)
            );
        }

        $fields = [];
        foreach (self::EXTRACTABLE_FIELDS as $field) {
            $fieldData  = $data['fields'][$field] ?? null;
            $value      = is_array($fieldData) ? ($fieldData['value'] ?? null) : null;
            $confidence = is_array($fieldData) ? ($fieldData['confidence'] ?? null) : null;

            // Normalise: only accept known confidence levels
            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = null;
            }

            // Normalise empty string to null
            $value = ($value === '' || $value === null) ? null : (string) $value;

            $fields[$field] = ['value' => $value, 'confidence' => $confidence];
        }

        return new ExtractionResult($fields);
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
cd /Users/dg/dev/frgs-vereinsbar/backend
php artisan test tests/Unit/Modules/Members/Services/ExtractionServiceTest.php
```
Expected: `5 passed` (all green)

- [ ] **Step 5: Commit**

```bash
git add backend/src/Modules/Members/Services/ExtractionService.php \
        backend/tests/Unit/Modules/Members/Services/ExtractionServiceTest.php
git commit -m "feat(extraction): add ExtractionService with unit tests (5 passing)"
```

---

## Chunk 3: Backend — Data Layer Extension

### Task 7: MandateDocumentRepository.updateExtraction()

**Files:**
- Modify: `backend/src/Modules/Members/Repositories/MandateDocumentRepository.php`

- [ ] **Step 1: Add updateExtraction() method**

Add this method to `MandateDocumentRepository`, after `deleteByMemberId()`:

```php
    /**
     * Write LLM extraction results after a successful upload.
     * Called only by MandateDocumentService — never clears extraction on its own.
     */
    public function updateExtraction(string $memberId, string $status, ?array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mandate_documents
                SET extraction_status = :status,
                    extracted_data    = :data
              WHERE member_id = :member_id'
        );
        $stmt->execute([
            'member_id' => $memberId,
            'status'    => $status,
            'data'      => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/Repositories/MandateDocumentRepository.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/Repositories/MandateDocumentRepository.php
git commit -m "feat(extraction): add updateExtraction() to MandateDocumentRepository"
```

---

### Task 8: MandateDocumentDto extension

**Files:**
- Modify: `backend/src/Modules/Members/DTOs/MandateDocumentDto.php`

The DTO currently has `uploadedAt`, `fileSizeBytes`, `originalFilename`, `extractionStatus`. Add `extraction: ?array` to carry the parsed fields from `extracted_data`.

- [ ] **Step 1: Update MandateDocumentDto**

Replace the entire file content:

```php
<?php
// backend/src/Modules/Members/DTOs/MandateDocumentDto.php

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
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/DTOs/MandateDocumentDto.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/DTOs/MandateDocumentDto.php
git commit -m "feat(extraction): add extraction field to MandateDocumentDto"
```

---

## Chunk 4: Backend — API Layer

### Task 9: MandateDocumentService extraction integration

**Files:**
- Modify: `backend/src/Modules/Members/Services/MandateDocumentService.php`

The service needs `?ExtractionService` injected (nullable — null when LLM not configured) and a `Logger` (to log extraction failures without surfacing them to the caller). The `upload()` method calls extraction on the **original bytes** before PDF conversion.

- [ ] **Step 1: Update MandateDocumentService**

Replace the full file:

```php
<?php
// backend/src/Modules/Members/Services/MandateDocumentService.php

declare(strict_types=1);

namespace App\Modules\Members\Services;

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
        private MandateDocumentRepository $mandateDocumentRepository,
        private AuditService              $auditService,
        private Logger                    $logger,
        private ?ExtractionService        $extractionService = null,
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

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/Services/MandateDocumentService.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/Services/MandateDocumentService.php
git commit -m "feat(extraction): integrate ExtractionService into MandateDocumentService.upload()"
```

---

### Task 10: ExtractionController

**Files:**
- Create: `backend/src/Modules/Members/Controllers/ExtractionController.php`

- [ ] **Step 1: Create ExtractionController**

```php
<?php
// backend/src/Modules/Members/Controllers/ExtractionController.php

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
        } catch (\RuntimeException $e) {
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
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l backend/src/Modules/Members/Controllers/ExtractionController.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Members/Controllers/ExtractionController.php
git commit -m "feat(extraction): add ExtractionController (POST /admin/mandate-document/extract)"
```

---

### Task 11: ServiceFactory + routes wiring

**Files:**
- Modify: `backend/src/ServiceFactory.php`
- Modify: `backend/src/routes.php`

- [ ] **Step 1: Add imports to ServiceFactory.php**

In the imports block, add after the existing Members imports:

```php
use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\Factories\LlmClientFactory;
use App\Modules\Members\Services\ExtractionService;
use App\Modules\Members\Controllers\ExtractionController;
```

- [ ] **Step 2: Register ExtractionController in FQCN_MAP**

Find the `FQCN_MAP` array (it maps class names to getter method names). Add:

```php
ExtractionController::class => 'getExtractionController',
```

- [ ] **Step 3: Add getter methods for the new classes**

Add after `getMandateDocumentService()`:

```php
    public function getLlmClientFactory(): LlmClientFactory
    {
        return $this->resolve(LlmClientFactory::class, fn() => new LlmClientFactory($this->getAppConfig()));
    }

    public function getExtractionService(): ?ExtractionService
    {
        $client = $this->getLlmClientFactory()->create();
        if ($client === null) {
            return null;
        }
        return $this->resolve(ExtractionService::class, fn() => new ExtractionService($client));
    }

    public function getExtractionController(): ExtractionController
    {
        return $this->resolve(ExtractionController::class, fn() => new ExtractionController(
            $this->getExtractionService(),
        ));
    }
```

- [ ] **Step 4: Update getMandateDocumentService() to inject ExtractionService and Logger**

Find the existing `getMandateDocumentService()` method and replace it:

```php
    public function getMandateDocumentService(): MandateDocumentService
    {
        return $this->resolve(MandateDocumentService::class, fn() => new MandateDocumentService(
            $this->getMandateDocumentRepository(),
            $this->getAuditService(),
            $this->logger,
            $this->getExtractionService(),
        ));
    }
```

- [ ] **Step 5: Add import and route for ExtractionController in routes.php**

At the top of `backend/src/routes.php`, add to the use statements:

```php
use App\Modules\Members\Controllers\ExtractionController;
```

Then, in the admin group (after the mandate-document routes), add:

```php
$group->post('/mandate-document/extract', [ExtractionController::class, 'extract']);
```

- [ ] **Step 6: Verify PHP syntax**

```bash
php -l backend/src/ServiceFactory.php
php -l backend/src/routes.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 7: Restart PHP-FPM and smoke-test**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
curl -s http://localhost:8080/api/health | jq .
```
Expected: health check returns OK.

```bash
curl -s -X POST http://localhost:8080/api/admin/mandate-document/extract \
  -H "Cookie: $(cat e2etests/auth/admin.json | jq -r '.cookies[] | select(.name=="PHPSESSID") | "\(.name)=\(.value)"')" \
  -F "file=@e2etests/fixtures/files/test-mandate.jpg" | jq .
```
Expected: `{"error":"llm_not_configured","message":"LLM extraction is not configured."}` (409) — because LLM vars are not set in `.env`.

- [ ] **Step 8: Commit**

```bash
git add backend/src/ServiceFactory.php backend/src/routes.php
git commit -m "feat(extraction): wire ExtractionController, ExtractionService, LlmClientFactory into ServiceFactory and routes"
```

---

## Chunk 5: OAS

### Task 12: admin.yaml schema updates

**Files:**
- Modify: `api/admin.yaml`

- [ ] **Step 1: Add ExtractionField and ExtractionResult component schemas**

Find the `components/schemas` section and add before `MandateDocument`:

```yaml
    ExtractionField:
      type: object
      nullable: true
      properties:
        value:
          type: string
          nullable: true
          description: Extracted value, null if not found or illegible
        confidence:
          type: string
          nullable: true
          enum: [high, medium, low, null]
          description: Model confidence in the extracted value

    ExtractionResult:
      type: object
      properties:
        fields:
          type: object
          properties:
            first_name:
              $ref: '#/components/schemas/ExtractionField'
            last_name:
              $ref: '#/components/schemas/ExtractionField'
            email:
              $ref: '#/components/schemas/ExtractionField'
            iban:
              $ref: '#/components/schemas/ExtractionField'
            account_holder_name:
              $ref: '#/components/schemas/ExtractionField'
            mandate_signed_at:
              $ref: '#/components/schemas/ExtractionField'
```

- [ ] **Step 2: Update MandateDocument schema to include extraction**

Find the `MandateDocument` schema and add `extraction`:

```yaml
        extraction:
          nullable: true
          allOf:
            - $ref: '#/components/schemas/ExtractionResult'
          description: Extraction results; null if extraction was not attempted or not configured
```

- [ ] **Step 3: Add the extract endpoint definition**

Find the path for `/admin/members/{memberId}/mandate-document` and add a new path **before** it:

```yaml
  /admin/mandate-document/extract:
    post:
      summary: Extract member fields from a mandate scan (no storage)
      description: >
        Stateless extraction endpoint — accepts an image or PDF, calls the configured LLM,
        and returns extracted fields with confidence levels. No file is stored.
        Used by the create-member-from-scan flow.
      operationId: extractMandateDocument
      tags:
        - mandate-document
      security:
        - sessionAuth: []
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
                  description: JPEG, PNG, or PDF of the scanned mandate
      responses:
        '200':
          description: Extraction result
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ExtractionResult'
        '401':
          $ref: '#/components/responses/Unauthorized'
        '409':
          description: LLM extraction is not configured
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'
        '422':
          $ref: '#/components/responses/ValidationError'
        '500':
          description: Extraction failed (LLM error or parse failure)
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Error'
```

- [ ] **Step 4: Commit**

```bash
git add api/admin.yaml
git commit -m "feat(extraction): add ExtractionField, ExtractionResult schemas and extract endpoint to OAS"
```

---

## Chunk 6: Frontend

### Task 13: i18n keys

**Files:**
- Modify: `admin-frontend/public/locales/de.json`
- Modify: `admin-frontend/public/locales/en.json`

- [ ] **Step 1: Add extraction keys to de.json**

In the `"mandateDocument"` object, add after `"uploadError"`:

```json
    "uploadingAndExtracting": "Hochladen & Extrahieren…",
    "extractionComplete": "Extraktion abgeschlossen",
    "extractionFailed": "Extraktion fehlgeschlagen — LLM-Konfiguration prüfen",
    "extractedValue": "Aus Scan extrahiert",
    "discardExtracted": "Extrahierte Werte verwerfen",
    "notFoundInScan": "Nicht im Scan gefunden",
    "extractionReviewHint": "Felder aus Scan vorausgefüllt. Bitte vor dem Speichern prüfen.",
    "confidenceHigh": "Hoch",
    "confidenceMedium": "Mittel",
    "confidenceLow": "Niedrig"
```

In the `"members"` object, add after the last key:

```json
    "newFromScan": "Neu aus Scan"
```

- [ ] **Step 2: Add extraction keys to en.json**

In the `"mandateDocument"` object, add after `"uploadError"`:

```json
    "uploadingAndExtracting": "Uploading & extracting…",
    "extractionComplete": "Extraction complete",
    "extractionFailed": "Extraction failed — check LLM config",
    "extractedValue": "Extracted from scan",
    "discardExtracted": "Discard extracted values",
    "notFoundInScan": "Not found in scan",
    "extractionReviewHint": "Fields pre-filled from scan. Review before saving.",
    "confidenceHigh": "High",
    "confidenceMedium": "Medium",
    "confidenceLow": "Low"
```

In the `"members"` object, add after the last key:

```json
    "newFromScan": "New from scan"
```

- [ ] **Step 3: Commit**

```bash
git add admin-frontend/public/locales/de.json admin-frontend/public/locales/en.json
git commit -m "feat(extraction): add i18n keys for LLM extraction UI (de + en)"
```

---

### Task 14: TypeScript types + extractMandateDocument API

**Files:**
- Create: `admin-frontend/src/api/generated/extractionField.ts`
- Create: `admin-frontend/src/api/generated/extractionResult.ts`
- Modify: `admin-frontend/src/api/generated/mandateDocument.ts`
- Create: `admin-frontend/src/api/extractMandateDocument.ts`

- [ ] **Step 1: Create ExtractionField type**

```typescript
// admin-frontend/src/api/generated/extractionField.ts

/**
 * Generated by orval v8.5.3 🍺
 * Do not edit manually.
 * Club Bar - Admin API
 * OpenAPI spec version: 1.0.0
 */

export interface ExtractionField {
  /** Extracted value, null if not found or illegible */
  value: string | null
  /** Model confidence in the extracted value */
  confidence: 'high' | 'medium' | 'low' | null
}
```

- [ ] **Step 2: Create ExtractionResult type**

```typescript
// admin-frontend/src/api/generated/extractionResult.ts

/**
 * Generated by orval v8.5.3 🍺
 * Do not edit manually.
 * Club Bar - Admin API
 * OpenAPI spec version: 1.0.0
 */
import type { ExtractionField } from './extractionField'

export interface ExtractionResult {
  fields: {
    first_name: ExtractionField
    last_name: ExtractionField
    email: ExtractionField
    iban: ExtractionField
    account_holder_name: ExtractionField
    mandate_signed_at: ExtractionField
  }
}
```

- [ ] **Step 3: Add extraction field to MandateDocument type**

In `admin-frontend/src/api/generated/mandateDocument.ts`, add the import and field:

```typescript
import type { ExtractionResult } from './extractionResult'
```

And add to the `MandateDocument` interface:

```typescript
  /**
   * LLM extraction result; null if extraction was not attempted or not configured
   * @nullable
   */
  extraction?: ExtractionResult | null
```

- [ ] **Step 4: Create extractMandateDocument API function**

```typescript
// admin-frontend/src/api/extractMandateDocument.ts

import { adminAxios } from './client'
import type { ExtractionResult } from './generated/extractionResult'

export type { ExtractionField } from './generated/extractionField'
export type { ExtractionResult } from './generated/extractionResult'

/**
 * Send a mandate scan to the backend for LLM field extraction.
 * No file is stored — this is purely for the create-from-scan flow.
 *
 * Throws on 409 (LLM not configured), 422 (invalid file), or 500 (extraction failed).
 */
export async function extractMandateDocument(file: File): Promise<ExtractionResult> {
  const formData = new FormData()
  formData.append('file', file)

  const response = await adminAxios.post<ExtractionResult>(
    '/admin/mandate-document/extract',
    formData,
    { headers: { 'Content-Type': undefined } }
  )
  return response.data
}
```

- [ ] **Step 5: Verify TypeScript compiles**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -30
```
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add admin-frontend/src/api/generated/extractionField.ts \
        admin-frontend/src/api/generated/extractionResult.ts \
        admin-frontend/src/api/generated/mandateDocument.ts \
        admin-frontend/src/api/extractMandateDocument.ts
git commit -m "feat(extraction): add ExtractionField/Result types and extractMandateDocument API client"
```

---

### Task 15: MandateDocumentSection — extraction state

**Files:**
- Modify: `admin-frontend/src/components/MandateDocumentSection.tsx`

The component needs to:
1. Show "Uploading & extracting…" during upload (replaces plain "Uploading…")
2. Accept an `onExtractionComplete` callback invoked with the extraction fields after a successful upload that includes extraction data

- [ ] **Step 1: Update MandateDocumentSection**

Replace the entire file:

```tsx
// admin-frontend/src/components/MandateDocumentSection.tsx

import React, { useRef, useState } from 'react'
import imageCompression from 'browser-image-compression'
import heic2any from 'heic2any'
import {
  MandateDocumentInfo,
  openMandateDocument,
  uploadMandateDocument,
} from '../api/mandateDocument'
import type { ExtractionResult } from '../api/generated/extractionResult'
import { useTranslation } from 'react-i18next'
import { theme } from '../styles/design-system'

interface Props {
  memberId: string
  initialDocument: MandateDocumentInfo | null
  onExtractionComplete?: (extraction: ExtractionResult) => void
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

export function MandateDocumentSection({ memberId, initialDocument, onExtractionComplete }: Props) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)

  const [state, setState] = useState<ComponentState>(
    initialDocument ? 'stored' : 'idle'
  )
  const [mandateDoc, setMandateDoc] = useState<MandateDocumentInfo | null>(initialDocument)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [originalSize, setOriginalSize] = useState<number>(0)
  const [error, setError] = useState<string | null>(null)

  async function processFile(raw: File) {
    setError(null)
    setOriginalSize(raw.size)

    try {
      let processedFile: File = raw

      if (raw.type === 'image/heic' || raw.name.toLowerCase().endsWith('.heic')) {
        const result = await heic2any({ blob: raw, toType: 'image/jpeg', quality: 0.85 })
        const blob   = Array.isArray(result) ? result[0] : result
        processedFile = new File(
          [blob],
          raw.name.replace(/\.heic$/i, '.jpg'),
          { type: 'image/jpeg' }
        )
      }

      if (processedFile.type !== 'application/pdf') {
        const name = processedFile.name
        const compressed = await imageCompression(processedFile, {
          maxSizeMB: 2,
          maxWidthOrHeight: 2000,
          useWebWorker: true,
        })
        processedFile = new File([compressed], name, { type: compressed.type })
      }

      setSelectedFile(processedFile)
      setState('selected')
    } catch {
      setError(t('mandateDocument.processingError'))
    }

    if (inputRef.current) inputRef.current.value = ''
  }

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.files?.[0]
    if (!raw) return
    await processFile(raw)
  }

  async function handleDrop(e: React.DragEvent<HTMLLabelElement>) {
    e.preventDefault()
    const file = e.dataTransfer.files?.[0]
    if (file) await processFile(file)
  }

  async function handleUpload() {
    if (!selectedFile) return
    setState('uploading')
    setError(null)

    try {
      const doc = await uploadMandateDocument(memberId, selectedFile)
      setMandateDoc(doc)
      setSelectedFile(null)
      setState('stored')

      // Notify parent if LLM extraction produced results
      if (doc.extraction && onExtractionComplete) {
        onExtractionComplete(doc.extraction)
      }
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
    setState(mandateDoc ? 'stored' : 'idle')
  }

  function handleReplace() {
    setState('idle')
    setSelectedFile(null)
    setError(null)
  }

  const uploadLabel = state === 'uploading'
    ? t('mandateDocument.uploadingAndExtracting')
    : t('mandateDocument.upload')

  return (
    <div
      style={{
        borderTop: `1px solid ${theme.colors.border.light}`,
        paddingTop: theme.spacing.lg,
        marginTop: theme.spacing.sm,
      }}
      data-testid="mandate-document-section"
    >
      <div
        style={{
          fontSize: '11px',
          fontWeight: theme.typography.fontWeight.semibold,
          color: theme.colors.text.muted,
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
            color: theme.colors.semantic.danger,
            fontSize: theme.typography.fontSize.xs,
            marginBottom: theme.spacing.sm,
            padding: '6px 10px',
            background: theme.badges.danger.bg,
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
            border: `2px dashed ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.sm,
            padding: '20px',
            textAlign: 'center',
            cursor: 'pointer',
            color: theme.colors.text.secondary,
          }}
          data-testid="mandate-document-dropzone"
          onDragOver={(e) => e.preventDefault()}
          onDrop={handleDrop}
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
          <div style={{ fontSize: theme.typography.fontSize.sm, fontWeight: theme.typography.fontWeight.medium, color: theme.colors.text.primary, marginBottom: '4px' }}>
            {t('mandateDocument.dropzone')}
          </div>
          <div style={{ fontSize: '11px' }}>JPEG · PNG · HEIC · PDF</div>
        </label>
      )}

      {/* ── Selected / Uploading ── */}
      {(state === 'selected' || state === 'uploading') && selectedFile && (
        <div
          style={{
            border: `2px solid ${theme.colors.semantic.primary}`,
            borderRadius: theme.borderRadius.sm,
            padding: theme.spacing.md,
            background: theme.badges.info.bg,
          }}
          data-testid="mandate-document-preview"
        >
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', marginBottom: '10px' }}>
            <div style={{ fontSize: '28px', flexShrink: 0 }}>
              {selectedFile.type === 'application/pdf' ? '📄' : '🖼️'}
            </div>
            <div>
              <div style={{ fontSize: theme.typography.fontSize.sm, fontWeight: theme.typography.fontWeight.semibold, color: theme.colors.text.primary }}>
                {selectedFile.name}
              </div>
              <div style={{ fontSize: '11px', color: theme.colors.text.muted }}>
                {formatBytes(originalSize)} → {formatBytes(selectedFile.size)}{' '}
                {selectedFile.type !== 'application/pdf' && `(${t('mandateDocument.compressed')})`}
              </div>
              {selectedFile.type !== 'application/pdf' && (
                <div style={{ fontSize: '11px', color: theme.colors.text.secondary }}>
                  {t('mandateDocument.willConvert')}
                </div>
              )}
            </div>
          </div>
          <div style={{ display: 'flex', gap: theme.spacing.sm }}>
            <button
              onClick={handleUpload}
              disabled={state === 'uploading'}
              style={{
                flex: 1,
                padding: theme.spacing.sm,
                background: state === 'uploading' ? 'rgba(59, 130, 246, 0.5)' : theme.colors.semantic.primary,
                color: 'white',
                border: 'none',
                borderRadius: '6px',
                cursor: state === 'uploading' ? 'wait' : 'pointer',
                fontSize: theme.typography.fontSize.sm,
                fontWeight: theme.typography.fontWeight.medium,
              }}
              data-testid="mandate-document-upload-btn"
            >
              {uploadLabel}
            </button>
            {state !== 'uploading' && (
              <button
                onClick={handleCancel}
                style={{
                  padding: '8px 12px',
                  background: theme.colors.bg.secondary,
                  color: theme.colors.text.secondary,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: '6px',
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                }}
                data-testid="mandate-document-cancel-btn"
              >
                ✕
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── Stored ── */}
      {state === 'stored' && mandateDoc && (
        <div
          style={{
            border: `1px solid rgba(34, 197, 94, 0.3)`,
            borderRadius: theme.borderRadius.sm,
            padding: theme.spacing.md,
            background: theme.badges.success.bg,
          }}
          data-testid="mandate-document-stored"
        >
          <div style={{ fontSize: '11px', color: theme.colors.text.muted, marginBottom: '10px' }}
            data-testid="mandate-document-filename"
          >
            {t('mandateDocument.uploaded')} {formatDate(mandateDoc.uploaded_at)}
          </div>
          <div style={{ display: 'flex', gap: theme.spacing.sm }}>
            <button
              onClick={() => openMandateDocument(memberId)}
              style={{
                flex: 1,
                padding: theme.spacing.sm,
                background: theme.colors.bg.secondary,
                color: theme.colors.text.primary,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: '6px',
                cursor: 'pointer',
                fontSize: theme.typography.fontSize.sm,
              }}
              data-testid="mandate-document-view-btn"
            >
              👁 {t('mandateDocument.view')}
            </button>
            <button
              onClick={handleReplace}
              style={{
                padding: '8px 12px',
                background: theme.badges.danger.bg,
                color: theme.colors.semantic.danger,
                border: `1px solid rgba(239, 68, 68, 0.3)`,
                borderRadius: '6px',
                cursor: 'pointer',
                fontSize: theme.typography.fontSize.sm,
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

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add admin-frontend/src/components/MandateDocumentSection.tsx
git commit -m "feat(extraction): MandateDocumentSection fires onExtractionComplete callback, shows uploading+extracting label"
```

---

### Task 16: MembersPage — extraction display + "New from scan"

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`

This task adds:
1. `extractedFields` state — holds the extraction result after upload or standalone extract
2. `preExtractionFormData` state — snapshot for "Discard" button
3. `handleExtractionComplete()` — applies extracted fields to the form with confidence tracking
4. Confidence badge rendering next to extracted form fields
5. "Discard extracted values" button in the modal footer
6. "New from scan" button in the header → file input → `extractMandateDocument()` → opens create modal pre-filled
7. Auto-upload scan after successful member creation (create-from-scan flow)

- [ ] **Step 1: Add imports**

At the top of `MembersPage.tsx`, add:

```typescript
import { extractMandateDocument, ExtractionResult } from '../api/extractMandateDocument'
import { uploadMandateDocument } from '../api/mandateDocument'
import { ScanIcon } from '../components/icons'  // use DownloadIcon rotated or a suitable icon
```

If `ScanIcon` doesn't exist, use `DownloadIcon` or `PlusIcon` from existing icons. Verify which icons are available:
```bash
grep -r "export" admin-frontend/src/components/icons/index.ts | head -30
```
Pick an appropriate existing icon (e.g. `DownloadIcon`) for the "New from scan" button.

- [ ] **Step 2: Add extraction-related state**

After the existing `useState` declarations, add:

```typescript
  const [extractedFields, setExtractedFields] = useState<ExtractionResult | null>(null)
  const [preExtractionFormData, setPreExtractionFormData] = useState<typeof formData | null>(null)
  const [scanFile, setScanFile] = useState<File | null>(null)
  const [scanExtracting, setScanExtracting] = useState(false)
  const scanInputRef = useRef<HTMLInputElement>(null)
```

Also add a `useRef` import if not already present:
```typescript
import { useEffect, useState, useRef } from 'react'
```

- [ ] **Step 3: Add handleExtractionComplete() and handleDiscardExtracted()**

After `handleDownloadSepaTemplate`, add:

```typescript
  // Called by MandateDocumentSection after upload returns extraction data.
  // Snapshots the current form state, then overlays extracted non-null values.
  function handleExtractionComplete(extraction: ExtractionResult) {
    setPreExtractionFormData({ ...formData })
    setExtractedFields(extraction)

    const updates: Partial<typeof formData> = {}
    const f = extraction.fields
    if (f.first_name?.value)          updates.first_name           = f.first_name.value
    if (f.last_name?.value)           updates.last_name            = f.last_name.value
    if (f.email?.value)               updates.email                = f.email.value
    if (f.iban?.value)                updates.iban                 = f.iban.value
    if (f.account_holder_name?.value) updates.account_holder_name  = f.account_holder_name.value
    if (f.mandate_signed_at?.value)   updates.mandate_signed_at    = f.mandate_signed_at.value

    setFormData(prev => ({ ...prev, ...updates }))
  }

  function handleDiscardExtracted() {
    if (preExtractionFormData) {
      setFormData(preExtractionFormData)
    }
    setExtractedFields(null)
    setPreExtractionFormData(null)
  }
```

- [ ] **Step 4: Add handleNewFromScan()**

```typescript
  async function handleNewFromScan(file: File) {
    setScanExtracting(true)
    setScanFile(file)
    try {
      const result = await extractMandateDocument(file)
      // Open create-member modal with extracted values pre-filled
      setEditingMember(null)
      const initialForm = {
        first_name: '', last_name: '', email: '', iban: '',
        account_holder_name: '', mandate_reference: '',
        mandate_signed_at: '', preferred_language: 'de', card_uid: '',
      }
      const f = result.fields
      setFormData({
        ...initialForm,
        first_name:           f.first_name?.value           ?? '',
        last_name:            f.last_name?.value            ?? '',
        email:                f.email?.value                ?? '',
        iban:                 f.iban?.value                 ?? '',
        account_holder_name:  f.account_holder_name?.value  ?? '',
        mandate_signed_at:    f.mandate_signed_at?.value    ?? '',
      })
      setExtractedFields(result)
      setPreExtractionFormData(null)
      setFormErrors({})
      setShowModal(true)
    } catch {
      setError(t('mandateDocument.extractionFailed'))
      setScanFile(null)
    } finally {
      setScanExtracting(false)
    }
  }
```

- [ ] **Step 5: Clear extraction state when modal closes**

Find where `setShowModal(false)` is called (the modal close/cancel handler) and add:

```typescript
    setExtractedFields(null)
    setPreExtractionFormData(null)
    setScanFile(null)
```

- [ ] **Step 6: Add "New from scan" button + hidden scan input to the header**

Find the header button group (near `members-sepa-template-download-button`) and add:

```tsx
          <input
            ref={scanInputRef}
            type="file"
            accept="image/*,.pdf"
            style={{ display: 'none' }}
            data-testid="members-scan-input"
            onChange={async (e) => {
              const file = e.target.files?.[0]
              if (file) await handleNewFromScan(file)
              if (scanInputRef.current) scanInputRef.current.value = ''
            }}
          />
          <button
            data-testid="members-new-from-scan-button"
            onClick={() => scanInputRef.current?.click()}
            disabled={scanExtracting}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '7px',
              padding: '10px 20px',
              borderRadius: '8px',
              border: '1px solid rgba(255,255,255,0.15)',
              background: 'transparent',
              color: scanExtracting ? 'rgba(255,255,255,0.4)' : '#fff',
              fontSize: '14px',
              fontWeight: 600,
              cursor: scanExtracting ? 'wait' : 'pointer',
            }}
          >
            <DownloadIcon size={18} />
            {scanExtracting ? t('mandateDocument.uploadingAndExtracting') : t('members.newFromScan')}
          </button>
```

- [ ] **Step 7: Add confidence badge helper and styled form field rendering**

Add a helper function near the top of the component body (after state declarations):

```typescript
  function confidenceBadge(fieldName: keyof ExtractionResult['fields']) {
    if (!extractedFields) return null
    const field = extractedFields.fields[fieldName]
    if (!field?.confidence) return null
    const styles: Record<string, React.CSSProperties> = {
      high:   { background: 'rgba(34,197,94,0.15)',  color: '#86efac', border: '1px solid rgba(34,197,94,0.3)'  },
      medium: { background: 'rgba(234,179,8,0.15)',  color: '#fde047', border: '1px solid rgba(234,179,8,0.3)'  },
      low:    { background: 'rgba(239,68,68,0.15)',   color: '#fca5a5', border: '1px solid rgba(239,68,68,0.3)'  },
    }
    const labels: Record<string, string> = {
      high:   t('mandateDocument.confidenceHigh'),
      medium: t('mandateDocument.confidenceMedium'),
      low:    t('mandateDocument.confidenceLow'),
    }
    return (
      <span style={{
        ...styles[field.confidence],
        borderRadius: '4px',
        padding: '2px 6px',
        fontSize: '10px',
        fontWeight: 700,
        flexShrink: 0,
        whiteSpace: 'nowrap',
      }}>
        ● {labels[field.confidence]}
      </span>
    )
  }

  function isExtracted(fieldName: keyof ExtractionResult['fields']): boolean {
    return !!(extractedFields?.fields[fieldName]?.value)
  }

  function extractedFieldStyle(fieldName: keyof ExtractionResult['fields']): React.CSSProperties {
    if (!isExtracted(fieldName)) return {}
    const conf = extractedFields?.fields[fieldName]?.confidence
    return {
      background: conf === 'low' ? 'rgba(239,68,68,0.1)' : 'rgba(234,179,8,0.08)',
      border: conf === 'low'
        ? '1px solid rgba(239,68,68,0.4)'
        : '1px solid rgba(234,179,8,0.4)',
    }
  }
```

- [ ] **Step 8: Wrap extracted form fields with badge + highlight**

For each of the SEPA-related form inputs in the edit/create modal that can be extracted (`iban`, `account_holder_name`, `mandate_signed_at`, `first_name`, `last_name`, `email`), wrap the input in a flex row with the confidence badge.

Find the IBAN field input in the modal form. It will look something like:
```tsx
<input value={formData.iban} onChange={...} ... />
```

Replace its container with:
```tsx
<div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
  <input
    value={formData.iban}
    onChange={...}
    style={{ ...existingStyle, ...extractedFieldStyle('iban'), flex: 1 }}
    ...
  />
  {confidenceBadge('iban')}
</div>
```

Repeat for `first_name`, `last_name`, `email`, `account_holder_name`, `mandate_signed_at`.

> **Tip:** Search for each field's `data-testid` or `name` attribute in the modal form to find the exact location. The pattern is identical for each field.

- [ ] **Step 9: Add extraction review banner and Discard button in modal footer**

In the modal, find the Save/Cancel button row and add **above** it:

```tsx
{extractedFields && (
  <div style={{
    background: 'rgba(124,58,237,0.1)',
    border: '1px solid rgba(124,58,237,0.3)',
    borderRadius: '6px',
    padding: '8px 12px',
    fontSize: '12px',
    color: '#c4b5fd',
    marginBottom: '12px',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: '8px',
  }}
  data-testid="extraction-review-banner"
  >
    <span>✦ {t('mandateDocument.extractionReviewHint')}</span>
    <button
      onClick={handleDiscardExtracted}
      style={{
        background: 'transparent',
        border: '1px solid rgba(124,58,237,0.4)',
        borderRadius: '4px',
        color: '#c4b5fd',
        fontSize: '11px',
        padding: '3px 8px',
        cursor: 'pointer',
        flexShrink: 0,
      }}
      data-testid="discard-extracted-btn"
    >
      {t('mandateDocument.discardExtracted')}
    </button>
  </div>
)}
```

- [ ] **Step 10: Pass onExtractionComplete to MandateDocumentSection**

Find the `<MandateDocumentSection>` usage in the edit modal and add the callback:

```tsx
<MandateDocumentSection
  memberId={editingMember.id}
  initialDocument={editingMember.mandate_document ?? null}
  onExtractionComplete={handleExtractionComplete}
/>
```

- [ ] **Step 11: Auto-upload scan file after member creation (create-from-scan)**

Find the `handleSave` / create member success handler. After the member is successfully created and you have the new member ID, add:

```typescript
      // If this was a create-from-scan, upload the scan as mandate document
      if (scanFile && newMemberId) {
        try {
          await uploadMandateDocument(newMemberId, scanFile)
        } catch {
          // Document upload failed but member was created — non-fatal
          // The admin can upload manually via the edit modal
        }
        setScanFile(null)
      }
```

> Note: `newMemberId` must come from the create API response. Find where the new member ID is returned and use it here.

- [ ] **Step 12: Verify TypeScript compiles**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -30
```
Expected: no errors.

- [ ] **Step 13: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(extraction): add confidence badges, extraction review, Discard button, and New from scan flow to MembersPage"
```

---

## Chunk 7: E2E Tests

### Task 17: E2E tests

**Files:**
- Create: `e2etests/tests/admin/mandate-document-extraction.spec.ts`

These tests verify the full stack. They require LLM to be configured in the test environment for the positive extraction tests. For the "LLM not configured" tests, the backend's default state (no LLM vars set) is sufficient.

- [ ] **Step 1: Check backend health**

```bash
curl -s http://localhost:8080/api/health | jq .
```
Expected: OK.

- [ ] **Step 2: Write the test file**

```typescript
// e2etests/tests/admin/mandate-document-extraction.spec.ts

import { test, expect } from '@playwright/test'
import path from 'path'
import { authenticatedAdminFixture } from '../fixtures/auth'

const FIXTURE_DIR = path.join(__dirname, '../../fixtures/files')

// Helper: create a unique member and return its ID
async function createTestMember(request: Parameters<typeof test>[1] extends { request: infer R } ? R : never): Promise<string> {
  const ts = Date.now()
  const res = await request.post('/api/admin/members', {
    data: {
      first_name: `ExtrTest${ts}`,
      last_name: 'User',
      email: `extr${ts}@test.example`,
      preferred_language: 'de',
    },
  })
  expect(res.status()).toBe(201)
  const body = await res.json()
  return body.id
}

test.describe('POST /api/admin/mandate-document/extract — LLM not configured', () => {
  test('returns 409 when LLM not configured', async ({ request }) => {
    // Default .env has no LLM_PROVIDER/LLM_API_KEY — expect 409
    const formData = new FormData()
    const imageBuffer = Buffer.from(
      'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64'
    ) // minimal valid GIF
    formData.append('file', new Blob([imageBuffer], { type: 'image/jpeg' }), 'test.jpg')

    const res = await request.post('/api/admin/mandate-document/extract', {
      multipart: { file: { name: 'test.jpg', mimeType: 'image/jpeg', buffer: imageBuffer } },
      headers: authenticatedAdminFixture.headers,
    })
    expect(res.status()).toBe(409)
    const body = await res.json()
    expect(body.error).toBe('llm_not_configured')
  })

  test('returns 422 when no file provided', async ({ request }) => {
    const res = await request.post('/api/admin/mandate-document/extract', {
      headers: authenticatedAdminFixture.headers,
    })
    expect(res.status()).toBe(422)
  })

  test('returns 401 when unauthenticated', async ({ request }) => {
    const res = await request.post('/api/admin/mandate-document/extract')
    expect(res.status()).toBe(401)
  })
})

test.describe('Upload returns extraction field in response', () => {
  test('upload response includes extraction key (null when LLM not configured)', async ({ request }) => {
    const memberId = await createTestMember(request)

    const imageBuffer = require('fs').readFileSync(path.join(FIXTURE_DIR, 'test-mandate.jpg'))
    const res = await request.post(`/api/admin/members/${memberId}/mandate-document`, {
      multipart: { file: { name: 'test-mandate.jpg', mimeType: 'image/jpeg', buffer: imageBuffer } },
      headers: authenticatedAdminFixture.headers,
    })
    expect(res.status()).toBe(200)
    const body = await res.json()

    // extraction key must always be present (null when LLM not configured)
    expect(body).toHaveProperty('extraction')
    expect(body).toHaveProperty('extraction_status')
    // With LLM not configured: both null
    expect(body.extraction).toBeNull()
    expect(body.extraction_status).toBeNull()
  })
})

test.describe('UI — extraction review banner and discard', () => {
  test('no extraction banner shown when no extraction data', async ({ page }) => {
    await page.goto('/members')
    await expect(page.locator('[data-testid="extraction-review-banner"]')).not.toBeVisible()
  })
})

test.describe('UI — New from scan button visible', () => {
  test('"New from scan" button is present on Members page', async ({ page }) => {
    await page.goto('/members')
    await expect(page.locator('[data-testid="members-new-from-scan-button"]')).toBeVisible()
  })
})
```

- [ ] **Step 3: Run tests**

```bash
cd e2etests && npm test -- tests/admin/mandate-document-extraction.spec.ts --workers=4
```
Expected: all tests pass (LLM-dependent tests pass against the 409/null responses; UI tests pass).

- [ ] **Step 4: Verify no regressions in related test file**

```bash
cd e2etests && npm test -- tests/admin/mandate-document.spec.ts --workers=4
```
Expected: all existing mandate-document tests still pass.

- [ ] **Step 5: Commit**

```bash
git add e2etests/tests/admin/mandate-document-extraction.spec.ts
git commit -m "test(e2e): add mandate document extraction E2E tests"
```

---

## Post-Implementation Checklist

- [ ] Update `plans/INDEX.md`: move this plan to Completed with today's date
- [ ] Run the full E2E suite: `cd e2etests && npm test -- --workers=4`
- [ ] Verify upload test still passes: `npm test -- tests/admin/mandate-document.spec.ts`
