<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Notifications\Services\SchedulerStatusService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Has the mail drain ever run, and what to schedule if it has not (#405).
 *
 * Read by the banner that sits on every page until the answer is yes.
 *
 * ### Two audiences, one route (#677)
 *
 * The gate this reports on refuses the *treasurer's* button: finalizing a
 * direct debit is blocked until a drain run has been observed, and every
 * settlement route belongs to the Kassenwart. So the warning has to reach that
 * office — which from the day roles shipped it could not, because the route was
 * `admin`-only and the banner's fetch answered 403 on every page a Kassenwart
 * opened.
 *
 * The grant widened; the payload did not. `admin` still reads the whole
 * response — the cron command naming this installation's document root, the URL
 * trigger, the drain's PHP build, the observed interval — because `admin` is
 * the office that can act on it. A Kassenwart reads `SchedulerStatusDto`'s
 * office view: the `verified` flag and the recommended interval, enough to say
 * *a scheduled run is missing and collections are blocked until somebody
 * schedules one*, and nothing describing the deployment's shape (ADR-0031).
 *
 * The role is read from what `AdminSessionAuth` resolved, not from anything the
 * caller sent, and the redaction is the DTO's — the same allow-list every
 * future field has to be added to deliberately.
 *
 * A Getränkewart is not in the grant at all: they cannot finalize anything, so
 * the block never lands on them, and their session makes no request here.
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
        $status = $this->schedulerStatusService->status();
        $roles = $request->getAttribute('admin_roles') ?? [];
        $isAdmin = in_array(AdminRole::ADMIN, $roles, true);

        return $this->json($response, $isAdmin ? $status->toArray() : $status->toOfficeArray());
    }
}
