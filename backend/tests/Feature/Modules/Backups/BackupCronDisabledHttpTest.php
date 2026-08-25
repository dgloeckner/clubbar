<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Notifications\Controllers\CronController;
use Tests\Feature\HttpTestCase;

/**
 * An installation with no `cron.secret` (ADR-0049, #690).
 *
 * A separate file rather than a case in {@see BackupCronHttpTest} for the same
 * reason the drain has one: the distinction is made at boot, because
 * `AppConfig` reads the secret once, so its absence is a property of the
 * running installation rather than of a request.
 *
 * Unconfigured means **unmounted**, not "mounted and always refusing". Most
 * installations schedule `bin/backup.php`, which is the preferred trigger, and
 * those should not also be carrying a public endpoint that writes a database
 * dump.
 */
class BackupCronDisabledHttpTest extends HttpTestCase
{
    protected function environment(): array
    {
        return ['CRON_SECRET' => ''] + parent::environment();
    }

    public function test_the_route_answers_404_when_no_secret_is_configured(): void
    {
        $response = $this->request('POST', '/api/cron/backup');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_no_secret_can_reach_a_route_that_is_switched_off(): void
    {
        // Including one an attacker guessed correctly against another
        // installation: with nothing configured there is nothing to match.
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => 'dev-cron-secret-x',
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }
}
