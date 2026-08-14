<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Contracts\ExtractionServiceInterface;
use App\Modules\Members\Controllers\ExtractionController;
use App\Modules\Members\ValueObjects\ExtractionResult;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

/**
 * The stateless "create member from scan" endpoint decides from an upload's
 * bytes what it forwards to the LLM provider, not from the Content-Type the
 * caller attached to them (#107).
 */
class ExtractionControllerTest extends TestCase
{
    private ExtractionServiceInterface $extractionService;
    private ExtractionController $controller;

    protected function setUp(): void
    {
        $this->extractionService = $this->createMock(ExtractionServiceInterface::class);
        $this->controller = new ExtractionController($this->extractionService);
    }

    private function requestWith(string $bytes, string $declaredType): ServerRequestInterface
    {
        $file = new class ($bytes, $declaredType) implements UploadedFileInterface {
            public function __construct(private string $bytes, private string $declaredType) {}

            public function getStream(): StreamInterface
            {
                return (new StreamFactory())->createStream($this->bytes);
            }

            public function getSize(): ?int { return strlen($this->bytes); }
            public function getError(): int { return UPLOAD_ERR_OK; }
            public function getClientFilename(): ?string { return 'scan.jpg'; }
            public function getClientMediaType(): ?string { return $this->declaredType; }

            public function moveTo(string $targetPath): void
            {
                throw new \LogicException('Not used: the controller reads the stream.');
            }
        };

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/mandate-document/extract')
            ->withUploadedFiles(['file' => $file]);
    }

    /** A 1×1 PNG, built here so the test needs no fixture outside the backend tree. */
    private function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    public function test_a_real_image_is_forwarded_with_its_detected_type(): void
    {
        $this->extractionService
            ->expects($this->once())
            ->method('extract')
            ->with($this->pngBytes(), 'image/png')
            ->willReturn(new ExtractionResult(fields: ['first_name' => ['value' => 'Max', 'confidence' => 'high']]));

        // Declared JPEG, actually PNG: the provider is told what it is really
        // being sent.
        $response = $this->controller->extract($this->requestWith($this->pngBytes(), 'image/jpeg'), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Max', $this->decode($response)['fields']['first_name']['value']);
    }

    public function test_bytes_that_are_not_an_image_are_rejected_however_they_are_declared(): void
    {
        $this->extractionService->expects($this->never())->method('extract');

        $response = $this->controller->extract(
            $this->requestWith('<html><body>not an image</body></html>', 'image/png'),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $this->decode($response)['error']);
    }

    /**
     * ADR-0037: PDF is accepted at this endpoint for parity with the removed
     * upload path, which took JPEG, PNG and PDF alike. Whether extraction
     * actually succeeds for a PDF is a provider concern (DirectExtractionService
     * forwards it to the LLM; ExtractionService's Vision pipeline still
     * refuses it) — the controller's job is only to let the bytes through.
     */
    public function test_a_pdf_is_accepted(): void
    {
        $pdfBytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";

        $this->extractionService
            ->expects($this->once())
            ->method('extract')
            ->with($pdfBytes, 'application/pdf')
            ->willReturn(new ExtractionResult(fields: ['first_name' => ['value' => 'Max', 'confidence' => 'high']]));

        $response = $this->controller->extract(
            $this->requestWith($pdfBytes, 'image/jpeg'),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Max', $this->decode($response)['fields']['first_name']['value']);
    }

    public function test_a_missing_file_is_still_a_422(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/mandate-document/extract');

        $response = $this->controller->extract($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_the_endpoint_reports_409_when_no_llm_is_configured(): void
    {
        $controller = new ExtractionController(null);

        $response = $controller->extract($this->requestWith($this->pngBytes(), 'image/png'), new Response());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('extraction_not_configured', $this->decode($response)['error']);
    }
}
