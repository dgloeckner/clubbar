<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Repositories\BackupRunsRepository;
use App\Modules\Notifications\Controllers\CronController;
use PDO;
use Tests\Feature\HttpTestCase;

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
 * Nothing is sealed here. No recipient key is configured in the test
 * environment, which is the shipped default and means the run refuses before
 * writing anything — deliberately, because these assertions are about the gate
 * and the guard, not about the archive. What a run actually does is
 * {@see BackupServiceTest}, against a real filesystem.
 */
class BackupCronHttpTest extends HttpTestCase
{
    private const SECRET = 'test-cron-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec('DELETE FROM backup_runs');
        $this->db->exec('UPDATE backup_config SET enabled = 1 WHERE id = 1');
    }

    protected function tearDown(): void
    {
        $this->db->exec('DELETE FROM backup_runs');
        $this->db->exec('UPDATE backup_config SET enabled = 0 WHERE id = 1');

        parent::tearDown();
    }

    protected function environment(): array
    {
        return parent::environment() + ['CRON_SECRET' => self::SECRET];
    }

    public function test_the_header_form_is_accepted_and_returns_no_data(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $response->getBody()->rewind();
        $this->assertSame(
            '',
            (string) $response->getBody(),
            'It triggers a run; it never serves an archive. Not even a count — that would put '
            . 'the state of the club\'s backups behind one static credential.'
        );
    }

    public function test_a_get_works_too_because_panels_differ(): void
    {
        $response = $this->request('GET', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertSame(204, $response->getStatusCode());
    }

    /**
     * The degraded form, supported because some panels can only fetch a bare
     * URL. The scrub covers what still reads `$_SERVER` after the handler; it
     * cannot reach a webserver that already wrote the request line from its own
     * memory, which is why the header form is the one the installer prints.
     */
    public function test_the_query_string_form_works_and_is_scrubbed_from_the_request_environment(): void
    {
        $_SERVER['QUERY_STRING'] = 'secret=' . self::SECRET;
        $_SERVER['REQUEST_URI'] = '/api/cron/backup?secret=' . self::SECRET;

        $response = $this->request('GET', '/api/cron/backup?secret=' . self::SECRET);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('secret=***', $_SERVER['QUERY_STRING']);
        $this->assertStringNotContainsString(self::SECRET, $_SERVER['REQUEST_URI']);
    }

    public function test_a_request_with_no_secret_is_refused_and_starts_nothing(): void
    {
        $response = $this->request('POST', '/api/cron/backup');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn(),
            'A refused request must not look like a backup run.'
        );
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET . 'x',
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_a_wrong_secret_in_the_query_string_is_refused_too(): void
    {
        $response = $this->request('GET', '/api/cron/backup?secret=not-the-secret');

        $this->assertSame(401, $response->getStatusCode());
    }

    /** `hash_equals`, and a length check that is not a substring check. */
    public function test_a_prefix_of_the_secret_is_refused(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => substr(self::SECRET, 0, 10),
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * The guard that makes this endpoint safe to expose at all: a caller in a
     * loop cannot turn it into a webspace-quota exhaustion.
     *
     * The caller is told nothing either way, and that is deliberate — a
     * response that distinguished "ran" from "too soon" would leak how often
     * the club backs up to whoever holds the secret, and a scheduler has
     * nothing to do with the answer.
     */
    public function test_repeated_calls_start_no_second_run_and_look_identical(): void
    {
        $runs = new BackupRunsRepository($this->db);
        $runs->start('44444444-4444-4444-8444-444444444444', 'cli', gmdate('Y-m-d H:i:s'));

        for ($i = 0; $i < 3; $i++) {
            $response = $this->request('POST', '/api/cron/backup', headers: [
                CronController::HEADER => self::SECRET,
            ]);

            $this->assertSame(204, $response->getStatusCode());
            $response->getBody()->rewind();
            $this->assertSame('', (string) $response->getBody());
        }

        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn(),
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

    /**
     * Backups switched off is not an error for the caller: the scheduler did
     * its job, and whether the club has enabled the feature is the club's
     * business rather than the credential holder's.
     */
    public function test_a_disabled_installation_still_answers_204_and_writes_nothing(): void
    {
        $this->db->exec('UPDATE backup_config SET enabled = 0 WHERE id = 1');

        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn());
    }

    /**
     * Fail closed, over HTTP as well as on the CLI: with no recipient key the
     * run refuses rather than writing a plaintext archive (ADR-0031 rule 3),
     * and the caller still gets a 204 because a scheduler cannot act on the
     * difference. The evidence goes to the log and to #693's mail.
     */
    public function test_no_recipient_key_writes_nothing_and_still_answers_204(): void
    {
        $response = $this->request('POST', '/api/cron/backup', headers: [
            CronController::HEADER => self::SECRET,
        ]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->db->query("SELECT COUNT(*) FROM backup_runs WHERE status = 'local'")->fetchColumn(),
            'No key configured means no archive at all — never a plaintext one.'
        );
    }
}
