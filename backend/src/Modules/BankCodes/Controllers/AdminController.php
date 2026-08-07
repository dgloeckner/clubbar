<?php

declare(strict_types=1);

namespace App\Modules\BankCodes\Controllers;

use App\Modules\BankCodes\Repositories\BankCodesRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private BankCodesRepository $repository,
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

        if ($blz === null || $blz === '') {
            $response->getBody()->write(json_encode(['error' => 'Missing blz parameter']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (preg_match('/^\d{8}$/', $blz) !== 1) {
            $response->getBody()->write(json_encode([
                'bank_name' => null,
                'bic' => null,
                'message' => 'blz must be an 8-digit German Bankleitzahl',
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
