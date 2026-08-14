<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\Controllers\CronController;
use Tests\Feature\HttpTestCase;

/**
 * An installation with no `cron.secret` (ADR-0038 rule 3).
 *
 * A separate file rather than a case in {@see CronDrainHttpTest} because the
 * distinction is made at boot: `AppConfig` reads the secret once, and the
 * absence of it is a property of the running installation rather than of a
 * request.
 *
 * The behaviour is the point of the default. Most installations schedule
 * `bin/cron.php`, which is the preferred trigger — no gateway timeout, no
 * secret in a URL — and those installations should not also be carrying a
 * public endpoint that drains their queue. Unconfigured means unmounted, not
 * "mounted and always refusing".
 */
class CronDrainDisabledHttpTest extends HttpTestCase
{
    protected function environment(): array
    {
        return ['CRON_SECRET' => ''] + parent::environment();
    }

    public function test_the_route_answers_404_when_no_secret_is_configured(): void
    {
        $response = $this->request('POST', '/api/cron/drain');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_no_secret_can_reach_a_route_that_is_switched_off(): void
    {
        // Including one an attacker guessed correctly against another
        // installation: with nothing configured there is nothing to match.
        $response = $this->request('POST', '/api/cron/drain', headers: [
            CronController::HEADER => 'dev-cron-secret-x',
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }
}
