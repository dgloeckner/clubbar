<?php

declare(strict_types=1);

namespace App\Modules\Members\LlmClients;

use App\Modules\Members\Contracts\LlmClientInterface;

class AnthropicClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function extractFromImage(string $base64, string $mimeType, string $prompt, string $assistantPrefill = ''): string
    {
        // Anthropic supports both images and PDFs natively via different content types.
        $contentType = $mimeType === 'application/pdf' ? 'document' : 'image';

        $messages = [[
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
        ]];

        // Assistant prefilling: add a partial assistant turn so the model continues
        // directly from the given text without any preamble.
        if ($assistantPrefill !== '') {
            $messages[] = [
                'role'    => 'assistant',
                'content' => $assistantPrefill,
            ];
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => 1024,
            'messages'   => $messages,
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

        // The API returns only the completion after the prefill; prepend it back
        // so callers receive the full response text they expect.
        $text = (string) ($body['content'][0]['text'] ?? '');
        return $assistantPrefill !== '' ? $assistantPrefill . $text : $text;
    }
}
