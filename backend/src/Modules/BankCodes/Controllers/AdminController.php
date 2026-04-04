<?php

declare(strict_types=1);

namespace App\Modules\BankCodes\Controllers;

use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private BankCodesRepository $repository,
    ) {}

    /**
     * GET /api/admin/bank-lookup?iban=DE89370400440532013000
     * Returns bank name and BIC for a given IBAN.
     */
    public function lookup(Request $request, Response $response): Response
    {
        $iban = $request->getQueryParams()['iban'] ?? null;

        if ($iban === null || $iban === '') {
            $response->getBody()->write(json_encode(['error' => 'Missing iban parameter']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $blz = BankCodeService::extractBlz($iban);
        if ($blz === null) {
            $response->getBody()->write(json_encode([
                'bank_name' => null,
                'bic' => null,
                'message' => 'Only German IBANs (DE) are supported for bank lookup',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $row = $this->repository->findByBankCode($blz);

        $response->getBody()->write(json_encode([
            'bank_code' => $blz,
            'bank_name' => $row['bank_name'] ?? null,
            'short_name' => $row['short_name'] ?? null,
            'bic' => $row['bic'] ?? null,
            'postal_code' => $row['postal_code'] ?? null,
            'city' => $row['city'] ?? null,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
