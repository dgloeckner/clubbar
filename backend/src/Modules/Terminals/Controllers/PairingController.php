<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Controllers;

use App\Modules\Terminals\Services\PairingService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PairingController
{
    use JsonResponder;

    public function __construct(
        private PairingService $pairingService,
    ) {}

    /**
     * Staff at the bar confirmed it is safe to trust this backend again after
     * a pairing mismatch (ADR-0035). The terminal identity comes from its own
     * bearer token, never the request body — the same authority rule
     * ADR-0033 §6 already applies to every other terminal-authenticated write.
     */
    public function acknowledge(Request $request, Response $response): Response
    {
        $terminalId = $request->getAttribute('terminal_id');
        $instanceId = $this->pairingService->acknowledgeRepair($terminalId);

        return $this->json($response, ['instance_id' => $instanceId]);
    }
}
