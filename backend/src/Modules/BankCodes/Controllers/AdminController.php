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
     * GET /api/admin/bank-lookup?iban=DE89370400440532013000
     * Returns bank name and BIC for a given IBAN.
     */
    public function lookup(Request $request, Response $response): Response
    {
        $iban = $request->getQueryParams()['iban'] ?? null;

        if (!is_string($iban) || $iban === '') {
            return $this->json($response, ['error' => 'Missing iban parameter'], 400);
        }

        $bank = $this->bankCodeService->lookupByIban($iban);

        if ($bank === null) {
            return $this->json($response, [
                'bank_name' => null,
                'bic' => null,
                'message' => 'Only German IBANs (DE) are supported for bank lookup',
            ]);
        }

        return $this->json($response, $bank);
    }
}
