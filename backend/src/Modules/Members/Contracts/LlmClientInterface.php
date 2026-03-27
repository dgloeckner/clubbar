<?php

declare(strict_types=1);

namespace App\Modules\Members\Contracts;

interface LlmClientInterface
{
    /**
     * Send image bytes (base64-encoded) and a prompt to the LLM.
     * Returns the raw text content of the model response.
     *
     * @param string $assistantPrefill  When non-empty, prepended as an assistant-turn
     *                                  prefix to force the model to continue from that
     *                                  text (assistant prefilling).  The prefill is NOT
     *                                  included in the returned string.
     * @throws \RuntimeException on API or network failure
     */
    public function extractFromImage(string $base64, string $mimeType, string $prompt, string $assistantPrefill = ''): string;
}
