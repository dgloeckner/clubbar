<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

class OcrPreprocessor
{
    /**
     * Flatten a Google Vision DOCUMENT_TEXT_DETECTION response to compact text.
     *
     * Walks: responses[0].fullTextAnnotation.pages → blocks → paragraphs → words → symbols.
     * For each word: joins symbol texts, takes the minimum symbol confidence.
     * Output: one line per non-empty paragraph, format "word(minConf) word(minConf) …"
     */
    public function flatten(array $visionResponse): string
    {
        $pages = $visionResponse['responses'][0]['fullTextAnnotation']['pages'] ?? [];

        $lines = [];
        $paragraphIndex = 0;
        foreach ($pages as $page) {
            foreach ($page['blocks'] ?? [] as $block) {
                foreach ($block['paragraphs'] ?? [] as $paragraph) {
                    $paragraphIndex++;
                    $words = [];
                    foreach ($paragraph['words'] ?? [] as $word) {
                        $symbols = $word['symbols'] ?? [];
                        if (empty($symbols)) {
                            continue;
                        }
                        $text    = implode('', array_column($symbols, 'text'));
                        $minConf = min(array_column($symbols, 'confidence'));
                        $words[] = sprintf('%s(%.2f)', $text, $minConf);
                    }
                    if (!empty($words)) {
                        $lines[] = sprintf('[P%d] %s', $paragraphIndex, implode(' ', $words));
                    }
                }
            }
        }

        return implode("\n", $lines);
    }
}
