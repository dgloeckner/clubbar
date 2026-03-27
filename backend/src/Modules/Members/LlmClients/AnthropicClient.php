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
