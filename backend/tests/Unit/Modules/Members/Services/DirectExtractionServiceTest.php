<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\Services\DirectExtractionService;
use PHPUnit\Framework\TestCase;

class DirectExtractionServiceTest extends TestCase
{
    /**
     * Build a characters array for a 22-character IBAN.
     * All positions default to high confidence with no alternatives.
     * $overrides is keyed by position index with fields to override.
     */
    private function makeChars(string $iban, array $overrides = []): array
    {
        $chars = [];
        for ($i = 0; $i < strlen($iban); $i++) {
            $chars[$i] = [
                'position'     => $i,
                'value'        => $iban[$i],
                'confidence'   => 'high',
                'alternatives' => [],
            ];
        }
        foreach ($overrides as $pos => $data) {
            $chars[$pos] = array_merge($chars[$pos], $data);
        }
        return array_values($chars);
    }

    /** Build the full JSON response a vision-LLM would return. */
    private function fullResponse(array $ibanChars, array $fieldOverrides = []): string
    {
        $fields = array_merge([
            'firstName'         => ['value' => 'Max',             'confidence' => 'high'],
            'lastName'          => ['value' => 'Mustermann',      'confidence' => 'high'],
            'email'             => ['value' => 'max@example.com', 'confidence' => 'medium'],
            'street'            => ['value' => 'Hauptstraße 1',   'confidence' => 'high'],
            'zipCode'           => ['value' => '12345',           'confidence' => 'high'],
            'city'              => ['value' => 'Berlin',          'confidence' => 'high'],
            'accountHolderName' => ['value' => 'Max Mustermann',  'confidence' => 'medium'],
            'cardUid'           => ['value' => 'A1B2C3D4',        'confidence' => 'high'],
            'mandateDate'       => ['value' => '15.01.2026',      'confidence' => 'high'],
        ], $fieldOverrides);
        $fields['iban'] = ['characters' => $ibanChars];
        return json_encode($fields);
    }

    private function mockLlm(string $response): LlmClientInterface
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('extractFromImage')->willReturn($response);
        return $llm;
    }

    public function test_extract_throws_on_pdf_mime_type(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('extractFromImage');

        $service = new DirectExtractionService($llm);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PDF/');
        $service->extract('fake', 'application/pdf');
    }

    public function test_extract_throws_on_invalid_json_from_llm(): void
    {
        $service = new DirectExtractionService($this->mockLlm('not valid json'));
        $this->expectException(\RuntimeException::class);
        $service->extract('fake', 'image/jpeg');
    }

    public function test_extract_parses_non_iban_fields(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('Max',           $result->fields['first_name']['value']);
        $this->assertSame('high',          $result->fields['first_name']['confidence']);
        $this->assertSame('Mustermann',    $result->fields['last_name']['value']);
        $this->assertSame('Hauptstraße 1', $result->fields['street']['value']);
        $this->assertSame('12345',         $result->fields['zip_code']['value']);
        $this->assertSame('Berlin',        $result->fields['city']['value']);
        $this->assertSame('A1B2C3D4',      $result->fields['card_uid']['value']);
        $this->assertSame('2026-01-15',    $result->fields['mandate_signed_at']['value']);
    }

    public function test_extract_handles_absent_fields(): void
    {
        // LLM returns only firstName; all other fields should be null
        $service = new DirectExtractionService($this->mockLlm(
            json_encode(['firstName' => ['value' => 'Anna', 'confidence' => 'high'], 'iban' => null])
        ));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('Anna', $result->fields['first_name']['value']);
        $this->assertNull($result->fields['last_name']['value']);
        $this->assertNull($result->fields['last_name']['confidence']);
        $this->assertNull($result->fields['iban']['value']);
    }

    public function test_extract_iban_all_high_valid_base_no_repair(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('DE89370400440532013000', $result->fields['iban']['value']);
        $this->assertSame('high',                   $result->fields['iban']['confidence']);
        $this->assertTrue($result->fields['iban']['checksumValid']);
        $this->assertFalse($result->needsReview);
    }

    public function test_extract_iban_min_character_confidence_propagates_to_overall(): void
    {
        // Position 5 keeps correct value '7' but has medium confidence → overall stays medium
        $chars   = $this->makeChars('DE89370400440532013000', [5 => ['confidence' => 'medium']]);
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('DE89370400440532013000', $result->fields['iban']['value']);
        $this->assertSame('medium',                 $result->fields['iban']['confidence']);
        $this->assertTrue($result->fields['iban']['checksumValid']);
    }

    public function test_extract_iban_repairs_low_confidence_character_with_valid_alternative(): void
    {
        // Real OCR misread: '7' at position 4 should be '1' (DE02100100100006820101 is valid)
        $chars = $this->makeChars('DE02700100100006820101', [
            4 => ['value' => '7', 'confidence' => 'low', 'alternatives' => ['1']],
        ]);
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('DE02100100100006820101', $result->fields['iban']['value']);
        $this->assertSame('medium',                 $result->fields['iban']['confidence']);
        $this->assertTrue($result->fields['iban']['checksumValid']);
        $this->assertFalse($result->needsReview);
    }

    public function test_extract_iban_repairs_medium_confidence_character_with_valid_alternative(): void
    {
        // Same repair but character has medium confidence instead of low
        $chars = $this->makeChars('DE02700100100006820101', [
            4 => ['value' => '7', 'confidence' => 'medium', 'alternatives' => ['1']],
        ]);
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('DE02100100100006820101', $result->fields['iban']['value']);
        $this->assertSame('medium',                 $result->fields['iban']['confidence']);
        $this->assertTrue($result->fields['iban']['checksumValid']);
        $this->assertFalse($result->needsReview);
    }

    public function test_extract_iban_stays_low_when_alternative_does_not_fix_mod97(): void
    {
        // '5' at position 21 doesn't fix the checksum
        $chars = $this->makeChars('DE89370400440532013001', [
            21 => ['confidence' => 'low', 'alternatives' => ['5']],
        ]);
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('DE89370400440532013001', $result->fields['iban']['value']);
        $this->assertSame('low',                    $result->fields['iban']['confidence']);
        $this->assertFalse($result->fields['iban']['checksumValid']);
        $this->assertTrue($result->needsReview);
    }

    public function test_extract_iban_stays_low_when_no_alternatives_and_base_invalid(): void
    {
        // Invalid base, all characters high confidence but no alternatives → no repair
        $chars   = $this->makeChars('DE89370400440532013001');
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('DE89370400440532013001', $result->fields['iban']['value']);
        $this->assertSame('low',                    $result->fields['iban']['confidence']);
        $this->assertFalse($result->fields['iban']['checksumValid']);
    }

    public function test_extract_mandate_date_normalized_from_dd_mm_yyyy(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm(
            $this->fullResponse($chars, ['mandateDate' => ['value' => '03.05.2026', 'confidence' => 'high']])
        ));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('2026-05-03', $result->fields['mandate_signed_at']['value']);
        $this->assertSame('high',       $result->fields['mandate_signed_at']['confidence']);
    }

    public function test_extract_mandate_date_invalid_format_sets_low_confidence(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm(
            $this->fullResponse($chars, ['mandateDate' => ['value' => 'not-a-date', 'confidence' => 'high']])
        ));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('low', $result->fields['mandate_signed_at']['confidence']);
    }

    public function test_extract_email_without_at_sign_sets_low_confidence(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm(
            $this->fullResponse($chars, ['email' => ['value' => 'notanemail', 'confidence' => 'high']])
        ));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('low', $result->fields['email']['confidence']);
    }

    public function test_extract_zip_code_non_5_digits_sets_low_confidence(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm(
            $this->fullResponse($chars, ['zipCode' => ['value' => '1234', 'confidence' => 'high']])
        ));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertSame('low', $result->fields['zip_code']['confidence']);
    }

    public function test_extract_needs_review_true_when_any_field_low(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm(
            $this->fullResponse($chars, ['firstName' => ['value' => 'X', 'confidence' => 'low']])
        ));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertTrue($result->needsReview);
    }

    public function test_extract_needs_review_false_when_all_high_or_medium(): void
    {
        $chars   = $this->makeChars('DE89370400440532013000');
        $service = new DirectExtractionService($this->mockLlm($this->fullResponse($chars)));

        $result = $service->extract('fake', 'image/jpeg');

        $this->assertFalse($result->needsReview);
    }
}
