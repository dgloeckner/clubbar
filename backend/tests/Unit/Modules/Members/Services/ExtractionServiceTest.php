<?php

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
