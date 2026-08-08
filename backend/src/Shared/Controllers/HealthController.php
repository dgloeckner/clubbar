<?php

declare(strict_types=1);

namespace App\Shared\Controllers;

use App\Shared\Services\HealthCheckService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HealthController
{
    use JsonResponder;

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    ) {}

    public function check(Request $request, Response $response): Response
    {
        return $this->json($response, $this->healthCheckService->check()->toArray());
    }
}
