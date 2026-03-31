<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\Contracts\VisionClientInterface;
use App\Modules\Members\Services\ExtractionService;
use PHPUnit\Framework\TestCase;

class ExtractionServiceTest extends TestCase
{
    /** Minimal Vision API response with one paragraph of two words */
    private function visionResponse(string $word1, float $conf1, string $word2, float $conf2): array
    {
        $makeWord = fn(string $text, float $conf) => [
            'symbols' => array_map(
                fn($ch) => ['text' => $ch, 'confidence' => $conf],
                str_split($text)
            ),
        ];
        return [
            'responses' => [[
                'fullTextAnnotation' => [
                    'pages' => [[
                        'blocks' => [[
                            'paragraphs' => [[
                                'words' => [$makeWord($word1, $conf1), $makeWord($word2, $conf2)],
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ];
    }

    private function makeService(array $visionResult, string $llmResponse): ExtractionService
    {
        $vision = $this->createMock(VisionClientInterface::class);
        $vision->method('recognize')->willReturn($visionResult);

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('extractFromText')->willReturn($llmResponse);

        return new ExtractionService($vision, $llm);
    }

    private function fullLlmResponse(array $overrides = []): string
    {
        $fields = array_merge([
            'firstName'         => ['value' => 'Max',                    'confidence' => 'high'],
            'lastName'          => ['value' => 'Mustermann',             'confidence' => 'high'],
            'email'             => ['value' => 'max@example.com',        'confidence' => 'medium'],
            'street'            => ['value' => 'Hauptstraße 1',          'confidence' => 'high'],
            'zipCode'           => ['value' => '12345',                  'confidence' => 'high'],
            'city'              => ['value' => 'Berlin',                 'confidence' => 'high'],
            'accountHolderName' => ['value' => 'Max Mustermann',         'confidence' => 'medium'],
            'cardUid'           => ['value' => 'A1B2C3D4',               'confidence' => 'high'],
            'iban'              => ['value' => 'DE89370400440532013000', 'confidence' => 'high'],
            'mandateDate'       => ['value' => '15.01.2026',             'confidence' => 'high'],
        ], $overrides);
        return json_encode($fields);
    }

    public function test_extract_parses_all_fields(): void
    {
        $service = $this->makeService(
            $this->visionResponse('Max', 0.95, 'Mustermann', 0.90),
            $this->fullLlmResponse()
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('Max',              $result->fields['first_name']['value']);
        $this->assertSame('high',             $result->fields['first_name']['confidence']);
        $this->assertSame('Hauptstraße 1',    $result->fields['street']['value']);
        $this->assertSame('12345',            $result->fields['zip_code']['value']);
        $this->assertSame('Berlin',           $result->fields['city']['value']);
        $this->assertSame('A1B2C3D4',         $result->fields['card_uid']['value']);
        $this->assertSame('2026-01-15',       $result->fields['mandate_signed_at']['value']);
    }

    public function test_extract_iban_checksum_valid_sets_confidence_high_and_checksumValid_true(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.5, 'y', 0.5),
            $this->fullLlmResponse(['iban' => ['value' => 'DE89370400440532013000', 'confidence' => 'low']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('DE89370400440532013000', $result->fields['iban']['value']);
        $this->assertSame('high',                   $result->fields['iban']['confidence']);
        $this->assertTrue($result->fields['iban']['checksumValid']);
    }

    public function test_extract_iban_checksum_fails_sets_low_and_checksumValid_false(): void
    {
        // DE89370400440532013001 has last digit 1 instead of 0 — not correctable via
        // visual lookalikes (1→0 is not a handwriting confusion pair), so stays low.
        $service = $this->makeService(
            $this->visionResponse('x', 0.5, 'y', 0.5),
            $this->fullLlmResponse(['iban' => ['value' => 'DE89370400440532013001', 'confidence' => 'high']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('low',  $result->fields['iban']['confidence']);
        $this->assertFalse($result->fields['iban']['checksumValid']);
    }

    public function test_extract_iban_single_digit_misread_is_auto_corrected(): void
    {
        // Real OCR misread from sepa-form.jpg: '7' read instead of '1' at position 4.
        // DE02700100100006820101 fails MOD-97; brute-force finds the unique fix.
        $service = $this->makeService(
            $this->visionResponse('x', 0.5, 'y', 0.5),
            $this->fullLlmResponse(['iban' => ['value' => 'DE02700100100006820101', 'confidence' => 'low']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('DE02100100100006820101', $result->fields['iban']['value']);
        $this->assertSame('medium',                 $result->fields['iban']['confidence']);
        $this->assertTrue($result->fields['iban']['checksumValid']);
        $this->assertFalse($result->needsReview);
    }

    public function test_extract_iban_ambiguous_correction_stays_low(): void
    {
        // When brute-force finds 0 or 2+ valid substitutions it must not auto-correct.
        // DE89370400440532013001 has no single-lookalike substitution that yields a
        // valid IBAN, so it stays low/invalid — no correction applied.
        $service = $this->makeService(
            $this->visionResponse('x', 0.5, 'y', 0.5),
            $this->fullLlmResponse(['iban' => ['value' => 'DE89370400440532013001', 'confidence' => 'high']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('DE89370400440532013001', $result->fields['iban']['value']);
        $this->assertSame('low',                    $result->fields['iban']['confidence']);
        $this->assertFalse($result->fields['iban']['checksumValid']);
    }

    public function test_extract_mandate_date_normalized_from_dd_mm_yyyy(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            $this->fullLlmResponse(['mandateDate' => ['value' => '03.05.2026', 'confidence' => 'high']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('2026-05-03', $result->fields['mandate_signed_at']['value']);
        $this->assertSame('high',       $result->fields['mandate_signed_at']['confidence']);
    }

    public function test_extract_mandate_date_invalid_format_sets_low_confidence(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            $this->fullLlmResponse(['mandateDate' => ['value' => 'not-a-date', 'confidence' => 'high']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('low', $result->fields['mandate_signed_at']['confidence']);
    }

    public function test_extract_email_without_at_sign_sets_low_confidence(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            $this->fullLlmResponse(['email' => ['value' => 'notanemail', 'confidence' => 'high']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('low', $result->fields['email']['confidence']);
    }

    public function test_extract_zip_code_non_5_digits_sets_low_confidence(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            $this->fullLlmResponse(['zipCode' => ['value' => '1234', 'confidence' => 'high']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('low', $result->fields['zip_code']['confidence']);
    }

    public function test_extract_needs_review_true_when_any_field_low(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            $this->fullLlmResponse(['firstName' => ['value' => 'X', 'confidence' => 'low']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertTrue($result->needsReview);
    }

    public function test_extract_needs_review_false_when_all_high_or_medium(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            $this->fullLlmResponse()
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertFalse($result->needsReview);
    }

    public function test_extract_handles_null_fields(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            json_encode(['firstName' => ['value' => 'Anna', 'confidence' => 'high']])
        );

        $result = $service->extract('fake-bytes', 'image/jpeg');

        $this->assertSame('Anna', $result->fields['first_name']['value']);
        $this->assertNull($result->fields['last_name']['value']);
        $this->assertNull($result->fields['last_name']['confidence']);
    }

    public function test_extract_throws_on_pdf_mime_type(): void
    {
        $vision = $this->createMock(VisionClientInterface::class);
        $vision->expects($this->never())->method('recognize');
        $llm = $this->createMock(LlmClientInterface::class);

        $service = new ExtractionService($vision, $llm);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PDF/');
        $service->extract('fake-bytes', 'application/pdf');
    }

    public function test_extract_throws_on_invalid_json_from_llm(): void
    {
        $service = $this->makeService(
            $this->visionResponse('x', 0.9, 'y', 0.9),
            'this is not json'
        );

        $this->expectException(\RuntimeException::class);
        $service->extract('fake-bytes', 'image/jpeg');
    }
}
