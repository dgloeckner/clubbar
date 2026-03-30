<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Services\OcrPreprocessor;
use PHPUnit\Framework\TestCase;

class OcrPreprocessorTest extends TestCase
{
    private function buildVisionResponse(array $paragraphs): array
    {
        $blocks = [];
        foreach ($paragraphs as $words) {
            $wordObjects = [];
            foreach ($words as [$text, $minConf]) {
                $symbols = [];
                foreach (str_split($text) as $ch) {
                    $symbols[] = ['text' => $ch, 'confidence' => $minConf];
                }
                $wordObjects[] = ['symbols' => $symbols];
            }
            $blocks[] = ['paragraphs' => [['words' => $wordObjects]]];
        }
        return [
            'responses' => [[
                'fullTextAnnotation' => [
                    'pages' => [['blocks' => $blocks]],
                ],
            ]],
        ];
    }

    public function test_flatten_single_paragraph(): void
    {
        $response = $this->buildVisionResponse([
            [['Susi', 0.59], ['Sommerfrische', 0.91]],
        ]);

        $result = (new OcrPreprocessor())->flatten($response);

        $this->assertSame('Susi(0.59) Sommerfrische(0.91)', $result);
    }

    public function test_flatten_multiple_paragraphs_separated_by_newline(): void
    {
        $response = $this->buildVisionResponse([
            [['Max', 0.95]],
            [['Müller', 0.88]],
        ]);

        $result = (new OcrPreprocessor())->flatten($response);

        $this->assertSame("Max(0.95)\nMüller(0.88)", $result);
    }

    public function test_flatten_word_confidence_is_min_of_symbol_confidences(): void
    {
        // word "DE89" has symbol confidences 0.99, 0.72, 0.95, 0.80 — min = 0.72
        $symbols = [
            ['text' => 'D', 'confidence' => 0.99],
            ['text' => 'E', 'confidence' => 0.72],
            ['text' => '8', 'confidence' => 0.95],
            ['text' => '9', 'confidence' => 0.80],
        ];
        $response = [
            'responses' => [[
                'fullTextAnnotation' => [
                    'pages' => [[
                        'blocks' => [[
                            'paragraphs' => [[
                                'words' => [['symbols' => $symbols]],
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ];

        $result = (new OcrPreprocessor())->flatten($response);

        $this->assertSame('DE89(0.72)', $result);
    }

    public function test_flatten_empty_response_returns_empty_string(): void
    {
        $response = ['responses' => [[]]];
        $result = (new OcrPreprocessor())->flatten($response);
        $this->assertSame('', $result);
    }

    public function test_flatten_skips_empty_paragraphs(): void
    {
        $response = $this->buildVisionResponse([
            [['Max', 0.95]],
            [],  // empty paragraph — no words
            [['Müller', 0.88]],
        ]);

        $result = (new OcrPreprocessor())->flatten($response);

        $this->assertSame("Max(0.95)\nMüller(0.88)", $result);
    }
}
