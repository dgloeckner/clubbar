<?php

namespace App\Http\Modules\Settlements\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Settlements\Requests\UpdateSepaConfigRequest;
use App\Http\Modules\Settlements\Services\SepaConfigService;
use Illuminate\Http\JsonResponse;

/**
 * SepaConfigController - SEPA Configuration Management
 *
 * Handles SEPA configuration (creditor details) for XML generation
 *
 * Implements Pattern 006: Thin Controllers
 * Implements ADR-0007: SEPA Configuration Management
 */
class SepaConfigController extends Controller
{
    public function __construct(
        private readonly SepaConfigService $sepaConfigService,
    ) {}

    /**
     * GET /api/admin/sepa-config - Get SEPA Configuration
     *
     * Returns current SEPA configuration with masked sensitive fields
     */
    public function show(): JsonResponse
    {
        $config = $this->sepaConfigService->getConfig();

        if (!$config) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($config->toArray());
    }

    /**
     * PUT /api/admin/sepa-config - Update SEPA Configuration
     *
     * Updates organization's SEPA details
     * Prevents creditor_id from being changed once set (immutability per ADR-0007)
     */
    public function update(UpdateSepaConfigRequest $request): JsonResponse
    {
        $adminId = $request->session()->get('admin_id');

        try {
            $config = $this->sepaConfigService->updateConfig(
                attributes: [
                    'creditor_id' => $request->creditorId(),
                    'creditor_name' => $request->creditorName(),
                    'creditor_iban' => $request->creditorIban(),
                    'creditor_address_street' => $request->creditorAddressStreet(),
                    'creditor_address_city' => $request->creditorAddressCity(),
                    'creditor_address_country' => $request->creditorAddressCountry(),
                ],
                adminUserId: $adminId,
            );

            if (!$config) {
                return response()->json(['error' => 'not_found'], 404);
            }

            return response()->json($config->toArray());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
