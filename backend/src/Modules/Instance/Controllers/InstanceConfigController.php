<?php

declare(strict_types=1);

namespace App\Modules\Instance\Controllers;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InstanceConfigController
{
    use JsonResponder;

    public function __construct(
        private InstanceConfigService $instanceConfigService,
        private Validator $validator,
    ) {}

    /**
     * Public — no session required. Read before login (the login page itself
     * shows the instance name) and by the Terminal, per ADR-0034.
     */
    public function show(Request $request, Response $response): Response
    {
        $config = $this->instanceConfigService->getConfig();
        return $this->json($response, $config->toArray());
    }

    public function update(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        $rules = [
            'instance_name' => ['required', 'string', 'max:100'],
        ];

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $config = $this->instanceConfigService->updateConfig($body, $adminId);

        if (!$config) {
            return $this->json($response, ['error' => 'Failed to update instance configuration'], 500);
        }

        return $this->json($response, $config->toArray());
    }
}
