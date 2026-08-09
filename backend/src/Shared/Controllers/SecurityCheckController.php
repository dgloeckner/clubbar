<?php

declare(strict_types=1);

namespace App\Shared\Controllers;

use App\Shared\Http\JsonResponder;
use App\Shared\Services\SecurityCheckService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The security self-check, for an admin who wants to know whether the
 * installation is still as protected as the day it was installed (#247).
 *
 * Admin-authenticated, and deliberately so: the report is a map of this
 * installation's weak points, naming the paths that hold the mandates and
 * saying which protections are not in force.
 */
class SecurityCheckController
{
    use JsonResponder;

    public function __construct(
        private readonly SecurityCheckService $securityCheckService,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->securityCheckService->check($request->getServerParams())->toArray());
    }
}
