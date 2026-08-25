<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Services\BackupService;
use App\Modules\Notifications\Controllers\CronController;
use Tests\Feature\HttpTestCase;
use Tests\Support\TempTree;

/**
 * The URL backup trigger, through the real middleware stack (ADR-0049, #690).
 *
 * The second public, unauthenticated write in this application and the heavier
 * of the two: the drain sends what was already queued, while this produces and
 * stores a **database dump**. So most of what is checked here is what it
 * refuses, plus the one property unique to it — that it cannot be used to fill
 * the webspace quota.
 *
 * Dispatched in-process rather than only from the E2E suite, for the same
 * reason {@see \Tests\Feature\Modules\Notifications\CronDrainHttpTest} is: a
 * rejection has to be checked against a *wrong* secret as well as a missing
 * one, and the E2E environment knows only the configured one.
 *
 * **This installation is fully configured**, unlike the E2E stack: a recipient
 * key is set, so a request that gets past the gate really does write a sealed
 * archive. It lands in a temp tree ({@see TempTree}) that `DATA_DIR` points
 * this boot at, which is what makes "one call, one archive" assertable rather
 * than inferred — and what keeps the run out of the container's own data
 * directory. The unconfigured shapes are
 * {@see BackupCronDisabledHttpTest} and {@see BackupCronNoKeyHttpTest}.
 *
 * Part of #690 and #703, epic #686.
 */
class BackupCronHttpTest extends HttpTestCase
{
    use TempTree;

    private const SECRET = 'test-cron-secret-0123456789abcdef';

    private string $tempTree = '';
    private string $recipientKeys = '';

    protected function setUp(): void
    {
        // Assigned before parent::setUp(), which reads environment(), and before
        // anything that could skip — cleanup can then only ever point here
        // (CLAUDE.md, destructive test cleanup).
        $this->tempTree = self::makeTempTree('clubbar-backup-http');
        mkdir($this->tempTree . '/storage', 0777, true);
        mkdir($this->tempTree . '/logs', 0777, true);

        $this->recipientKeys = 'admin:' . bin2hex(
            sodium_crypto_box_publickey(sodium_crypto_box_keypair())
        );

        parent::setUp();
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->tempTree);

        parent::tearDown();
    }

    protected function environment(): array
    {
        return parent::environment() + [
            'CRON_SECRET' => self::SECRET,
            // Configuring a key is the on-switch (ADR-0049 decision 2). Without
            // it this route is not mounted at all.
            'BACKUP_RECIPIENT_PUBLIC_KEYS' => $this->recipientKeys,
            'DATA_DIR' => $this->tempTree,
        ];
    }

    public function test_the_header_form_is_accepted_and_writes_an_archive(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $response->getBody()->rewind();
        $this->assertSame(
            '',
            (string) $response->getBody(),
            'It triggers a run; it never serves an archive, not even a count.'
        );
        $this->assertCount(1, $this->archives());
    }

    public function test_a_get_works_too_because_panels_differ(): void
    {
        $response = $this->request('GET', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertCount(1, $this->archives());
    }

    /**
     * The degraded form, for panels whose cron field takes only a URL. The
     * secret ends up in the host's access log, so the handler scrubs what it
     * still can of this process's own view of the request.
     */
    public function test_the_query_string_form_works_and_is_scrubbed_from_the_request_environment(): void
    {
        $_SERVER['QUERY_STRING'] = 'secret=' . self::SECRET;
        $_SERVER['REQUEST_URI'] = '/api/cron/backup?secret=' . self::SECRET;

        $response = $this->request('GET', '/api/cron/backup?secret=' . self::SECRET);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertStringNotContainsString(self::SECRET, $_SERVER['QUERY_STRING']);
        $this->assertStringNotContainsString(self::SECRET, $_SERVER['REQUEST_URI']);
    }

    public function test_a_request_with_no_secret_is_refused_and_starts_nothing(): void
    {
        $response = $this->request('POST', '/api/cron/backup');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->archives());
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => 'not-the-secret',
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->archives());
    }

    public function test_a_wrong_secret_in_the_query_string_is_refused_too(): void
    {
        $response = $this->request('GET', '/api/cron/backup?secret=not-the-secret');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->archives());
    }

    /** A prefix must not pass: the comparison is of whole values, not of a start. */
    public function test_a_prefix_of_the_secret_is_refused(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => substr(self::SECRET, 0, 20),
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->archives());
    }

    /**
     * The minimum interval is what stops this endpoint filling the webspace
     * quota with dumps, and it is keyed on attempts rather than successes,
     * because the quota is spent by an attempt.
     *
     * The caller is told nothing either way, and that is deliberate — a
     * response that distinguished "ran" from "too soon" would leak how often
     * the club backs up to whoever holds the secret, and a scheduler has
     * nothing to do with the answer.
     */
    public function test_repeated_calls_write_one_archive_and_look_identical(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $response = $this->request('POST', '/api/cron/backup', headers: [
                CronController::HEADER => self::SECRET,
            ]);

            $this->assertSame(204, $response->getStatusCode());
            $response->getBody()->rewind();
            $this->assertSame('', (string) $response->getBody());
        }

        $this->assertCount(
            1,
            $this->archives(),
            'The minimum interval is what stops this endpoint filling the quota with dumps.'
        );
    }

    /**
     * A refusal discloses nothing — not whether backups are configured, not
     * when the last one ran, not whether this installation has any.
     */
    public function test_a_refusal_discloses_nothing_about_the_backups(): void
    {
        $response = $this->request('POST', '/api/cron/backup');
        $response->getBody()->rewind();
        $body = (string) $response->getBody();

        $this->assertStringNotContainsStringIgnoringCase('backup', $body);
        $this->assertStringNotContainsStringIgnoringCase('archive', $body);
    }

    /** @return list<string> */
    private function archives(): array
    {
        return glob(
            $this->tempTree . '/' . BackupService::DIRECTORY . '/*' . BackupService::EXTENSION
        ) ?: [];
    }
}
