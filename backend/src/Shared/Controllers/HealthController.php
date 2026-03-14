<?php

declare(strict_types=1);

namespace App\Shared\Controllers;

use App\Shared\Services\HealthCheckService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HealthController
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    ) {}

    public function check(Request $request, Response $response): Response
    {
        return $this->json($response, $this->healthCheckService->check()->toArray());
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
