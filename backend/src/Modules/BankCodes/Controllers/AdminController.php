<?php

declare(strict_types=1);

namespace App\Modules\BankCodes\Controllers;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private BankCodeService $bankCodeService,
    ) {}

    /**
     * GET /api/admin/bank-lookup?blz=37040044
     * Returns bank name and BIC for a given BLZ (Bankleitzahl).
     *
     * Takes only the 8-digit BLZ instead of the full IBAN so that member
     * account numbers never end up in access logs via the query string.
     */
    public function lookup(Request $request, Response $response): Response
    {
        $blz = $request->getQueryParams()['blz'] ?? null;

        if (!is_string($blz) || $blz === '') {
            return $this->json($response, ['error' => 'Missing blz parameter'], 400);
        }

        $bank = $this->bankCodeService->lookupByBlz($blz);

        if ($bank === null) {
            return $this->json($response, [
                'bank_name' => null,
                'bic' => null,
                'message' => 'blz must be an 8-digit German Bankleitzahl',
            ]);
        }

        return $this->json($response, $bank);
    }
}
