<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Controllers;

use App\Modules\Settlements\Services\SepaConfigService;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SepaConfigController
{
    public function __construct(
        private SepaConfigService $sepaConfigService,
        private Validator $validator,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $config = $this->sepaConfigService->getConfig(masked: true);

        if (!$config) {
            return $this->json($response, ['error' => 'SEPA configuration not found'], 404);
        }

        return $this->json($response, $config->toArray());
    }

    public function update(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'creditor_name' => ['required', 'string', 'max:70'],
            'creditor_id' => ['required', 'string'],
            'creditor_iban' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $config = $this->sepaConfigService->updateConfig($body, $adminId);

        if (!$config) {
            return $this->json($response, ['error' => 'Failed to update SEPA configuration'], 500);
        }

        return $this->json($response, $config->toArray());
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
