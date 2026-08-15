<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Modules\Notifications\Services\SchedulerStatusService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Has the mail drain ever run, and what to schedule if it has not (#405).
 *
 * Read by the admin banner, which is on every page until the answer is yes.
 * Admin-authenticated: the response names this installation's document-root
 * path and, where the URL trigger is configured, the endpoint that drains its
 * announcement queue. Neither is a secret on its own — the secret itself is
 * never returned — but both describe the deployment's shape, which is the class
 * of detail ADR-0031 keeps behind a session.
 *
 * Read-only by design. Nothing here triggers a drain: a "verify" button that
 * ran the drain itself would prove the endpoint answers, not that anything is
 * scheduled to call it, which is the only thing this gate is asking.
 */
class SchedulerController
{
    use JsonResponder;

    public function __construct(private SchedulerStatusService $schedulerStatusService) {}

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->schedulerStatusService->status()->toArray());
    }
}
