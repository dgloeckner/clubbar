<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Health check endpoint (no auth required).
     *
     * Returns server status and timestamp.
     * Used by terminal to verify connectivity.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601ZuluString(),
        ]);
    }
}
