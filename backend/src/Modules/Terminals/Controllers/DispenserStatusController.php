<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Controllers;

use App\Modules\Terminals\Services\DispenserStatusService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `PUT /api/sync/terminal-status` — a terminal filing what it knows about its
 * own peripherals (ADR-0057, #952).
 *
 * The terminal identity comes from its bearer token, never the body: the same
 * authority rule ADR-0033 §6 applies to every other terminal-authenticated
 * write, and it is what stops one kiosk reporting a jam against another.
 *
 * **One status code, always `204`.** Not a courtesy — a validation error here
 * would be a sync cycle a terminal could fail over telemetry, and a report body
 * is exactly the thing a firmware release changes. The reason a report was
 * dropped goes to the log, which is where the person asking the question is.
 */
class DispenserStatusController
{
    public function __construct(
        private DispenserStatusService $dispenserStatusService,
    ) {}

    public function report(Request $request, Response $response): Response
    {
        $this->dispenserStatusService->record(
            (string) $request->getAttribute('terminal_id'),
            $request->getParsedBody(),
            $request->getBody()->getSize(),
        );

        return $response->withStatus(204);
    }
}
