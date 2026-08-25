<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Notifications\Controllers\CronController;
use Tests\Feature\HttpTestCase;

/**
 * An installation with a cron secret but no recipient key (ADR-0049 decision 2,
 * #703).
 *
 * The second way to be unconfigured, and the newer one. Configuring
 * `backup.recipient_public_keys` is what switches backups on — there is no
 * separate enabled flag that could disagree with the keys — so a club that has
 * not configured one has no backup endpoint either.
 *
 * **404, not 204.** A 204 would be indistinguishable, to the panel calling it,
 * from a working backup: the scheduler would report success every night for a
 * club that has never had an archive. The route being absent is the honest
 * answer, and it is the same answer the drain gives when *its* precondition is
 * missing.
 *
 * A separate file rather than a case in {@see BackupCronHttpTest} for the same
 * reason {@see BackupCronDisabledHttpTest} is one: `AppConfig` reads the keys
 * once at boot, so their absence is a property of the running installation
 * rather than of a request.
 */
class BackupCronNoKeyHttpTest extends HttpTestCase
{
    private const SECRET = 'test-cron-secret-0123456789abcdef';

    protected function environment(): array
    {
        return [
            'CRON_SECRET' => self::SECRET,
            'BACKUP_RECIPIENT_PUBLIC_KEYS' => '',
        ] + parent::environment();
    }

    public function test_the_route_answers_404_when_no_recipient_key_is_configured(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'Backups are off until a key is configured, so there is no such endpoint yet.'
        );
    }

    /**
     * The mail drain shares the secret and is unaffected: one credential, two
     * jobs, and only one of them needs a backup key.
     */
    public function test_the_drain_is_still_mounted_on_the_same_secret(): void
    {
        $response = $this->request('POST', '/api/cron/drain', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertNotSame(404, $response->getStatusCode());
    }
}
