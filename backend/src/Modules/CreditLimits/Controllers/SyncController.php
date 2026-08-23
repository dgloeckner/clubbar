<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Controllers;

use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The club policy a terminal caches (ADR-0046 decision 1).
 *
 * Two channels carry a limit to a terminal, on purpose. The **club default**
 * is here; a member's **own ceiling** rides their row on `/sync/members`, and
 * the terminal coalesces the two locally because it decides at checkout with
 * nothing reachable. A pre-resolved per-member figure was rejected:
 * `/sync/members` is a delta on `updated_at`, and a club setting touches no
 * member row, so the new default would reach each terminal one member at a
 * time, whenever those members next changed for unrelated reasons.
 *
 * **Not on `/health`, and this is the rule rather than a preference.**
 * `/health` carries only what a terminal needs *before* it can authenticate —
 * reachability, and the ADR-0035 pairing identity that decides whether it may
 * sync at all. By the time a ceiling matters the terminal is bearer
 * authenticated and already polling `/sync/*`. The path cannot be renamed
 * either: `docker-compose.yml`'s healthcheck, `scripts/dev-stack.sh`, both
 * deploy workflows, `package/install.php`, `RuntimeHardening` and the package
 * smoke tests all depend on it, so the boundary is what stops it accreting
 * club configuration again.
 *
 * The route lives inside the existing `/api/sync` group, which is what gives
 * it `TerminalTokenAuth` and the terminal rate limit with no new wiring — and
 * why there is no `RouteRoleMap` entry: that map covers the session-auth
 * `/api/admin` and `/api/auth` groups.
 */
class SyncController
{
    use JsonResponder;

    public function __construct(
        private CreditLimitConfigService $creditLimitConfigService,
    ) {}

    public function config(Request $request, Response $response): Response
    {
        $clubDefault = $this->creditLimitConfigService->policy()->clubDefault();

        return $this->json($response, [
            'credit_limit' => [
                'default_limit_cents' => $clubDefault->limitCents,
                'warn_threshold_percent' => $clubDefault->warnThresholdPercent,
                // Derived and sent, so the terminal never reproduces the
                // rounding rule. `intdiv`, matching `warnAtCents()` and the
                // dashboard's `DIV`: a boundary cent the two sides compute
                // differently is a member one of them warns and the other does
                // not.
                'warn_at_cents' => $clubDefault->warnAtCents(),
            ],
        ]);
    }
}
