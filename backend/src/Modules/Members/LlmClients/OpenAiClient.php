<?php

declare(strict_types=1);

namespace App\Modules\Members\LlmClients;

use App\Modules\Members\Contracts\LlmClientInterface;

class OpenAiClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function extractFromImage(string $base64, string $mimeType, string $prompt, string $assistantPrefill = ''): string
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

    public function extractFromText(string $userMessage, string $systemPrompt): string
    {
        $payload = [
            'model'           => $this->model,
            'max_tokens'      => 1024,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMessage],
            ],
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
