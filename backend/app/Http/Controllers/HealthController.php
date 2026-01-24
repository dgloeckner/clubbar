<?php

namespace App\Http\Controllers;

use App\Http\Requests\HealthRequest;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * HealthController
 *
 * Thin controller that routes health check requests.
 * Demonstrates Pattern 006: Thin Controllers with all patterns implemented.
 */
final class HealthController extends Controller
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    ) {}

    /**
     * Health check endpoint (no auth required)
     *
     * Flow:
     * 1. HealthRequest validates input (FormRequest pattern)
     * 2. Service executes health check logic
     * 3. Service returns HealthResponseDto (DTO pattern)
     * 4. Controller serializes DTO to JSON
     *
     * @param HealthRequest $request
     * @return JsonResponse
     */
    public function health(HealthRequest $request): JsonResponse
    {
        $response = $this->healthCheckService->check();

        return response()->json($response->toArray());
    }
}
