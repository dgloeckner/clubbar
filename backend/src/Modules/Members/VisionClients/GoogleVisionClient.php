<?php

declare(strict_types=1);

namespace App\Modules\Members\VisionClients;

use App\Modules\Members\Contracts\VisionClientInterface;

class GoogleVisionClient implements VisionClientInterface
{
    private const API_URL = 'https://vision.googleapis.com/v1/images:annotate';

    public function __construct(private string $apiKey) {}

    public function recognize(string $imageBytes): array
    {
        $payload = [
            'requests' => [[
                'image'        => ['content' => base64_encode($imageBytes)],
                'features'     => [['type' => 'DOCUMENT_TEXT_DETECTION']],
                'imageContext' => ['languageHints' => ['de']],
            ]],
        ];

        $ch = curl_init(self::API_URL . '?key=' . urlencode($this->apiKey));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false || $curlError !== '') {
            throw new \RuntimeException('Google Vision API cURL error: ' . $curlError);
        }

        $body = json_decode((string) $response, true);

        if ($httpCode !== 200) {
            $msg = $body['error']['message'] ?? $response;
            throw new \RuntimeException("Google Vision API error {$httpCode}: {$msg}");
        }

        return $body;
    }
}
