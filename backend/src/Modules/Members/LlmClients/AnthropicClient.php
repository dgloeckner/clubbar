<?php

declare(strict_types=1);

namespace App\Modules\Members\LlmClients;

use App\Modules\Members\Contracts\LlmClientInterface;

class AnthropicClient implements LlmClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private int    $thinkingBudget = 0,
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

        $usingThinking = $this->thinkingBudget >= 1024;

        // Assistant prefilling is incompatible with extended thinking — skip it.
        if (!$usingThinking && $assistantPrefill !== '') {
            $messages[] = [
                'role'    => 'assistant',
                'content' => $assistantPrefill,
            ];
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $usingThinking ? $this->thinkingBudget + 2048 : 1024,
            'messages'   => $messages,
        ];

        if ($usingThinking) {
            $payload['thinking'] = [
                'type'         => 'enabled',
                'budget_tokens' => $this->thinkingBudget,
            ];
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $usingThinking ? 180 : 30,
            // Force HTTP/1.1 to avoid HTTP/2 stream issues in Docker environments.
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
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

        // With thinking enabled the response contains multiple blocks (thinking + text).
        // Find the first text block; prepend prefill only when thinking is off.
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');
                break;
            }
        }

        return (!$usingThinking && $assistantPrefill !== '') ? $assistantPrefill . $text : $text;
    }
}
