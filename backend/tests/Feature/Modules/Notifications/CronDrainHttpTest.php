<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\Controllers\CronController;
use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use Tests\Feature\HttpTestCase;

/**
 * The URL drain trigger, through the real middleware stack (ADR-0038 rule 3).
 *
 * This route is the one public, unauthenticated write in the application, so
 * the tests that matter are about what it refuses and what it discloses. It is
 * dispatched here rather than only in the E2E suite because a rejection has to
 * be checked against a *wrong* secret as well as a missing one, and the E2E
 * environment knows only the configured one.
 *
 * No mail leaves: the test environment sets no `MAIL_DSN`, so the drain stops
 * before claiming. That is deliberate — the assertions here are about the
 * gate, not about sending, and no test in this repository opens a socket.
 */
class CronDrainHttpTest extends HttpTestCase
{
    private const SECRET = 'test-cron-secret-0123456789abcdef';

    private CronHeartbeatRepository $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->heartbeat = new CronHeartbeatRepository($this->db);
    }

    protected function tearDown(): void
    {
        // Shared singleton — leave it in the seeded "a scheduler has run" state.
        $this->db->prepare(
            'UPDATE cron_heartbeat SET last_run_at = NOW(), source = ?, sent = 0, failed = 0 WHERE id = 1'
        )->execute([DrainSource::CLI->value]);

        parent::tearDown();
    }

    protected function environment(): array
    {
        return parent::environment() + ['CRON_SECRET' => self::SECRET];
    }

    public function test_the_header_form_drains_and_returns_no_data(): void
    {
        $this->db->prepare('UPDATE cron_heartbeat SET last_run_at = NULL, source = NULL WHERE id = 1')->execute();

        $response = $this->request('POST', '/api/cron/drain', headers: [CronController::HEADER => self::SECRET]);

        $this->assertSame(204, $response->getStatusCode());
        $response->getBody()->rewind();
        $this->assertSame('', (string) $response->getBody(), 'a scheduler cannot act on counts, and this route is public');

        $row = $this->heartbeat->get();
        $this->assertNotNull($row['last_run_at']);
        $this->assertSame(DrainSource::URL->value, $row['source'], 'the run must record how it was triggered');
    }

    public function test_a_get_works_too_because_panels_differ(): void
    {
        $response = $this->request('GET', '/api/cron/drain', headers: [CronController::HEADER => self::SECRET]);

        $this->assertSame(204, $response->getStatusCode());
    }

    /**
     * The degraded form. Supported because some panels can only fetch a bare
     * URL; documented as degraded because the request line, secret and all, is
     * written to the webserver's access log — the same finding `install.php`
     * already had once with `?key=`.
     */
    public function test_the_query_string_form_works_and_is_scrubbed_from_the_request_environment(): void
    {
        $_SERVER['QUERY_STRING'] = 'secret=' . self::SECRET;
        $_SERVER['REQUEST_URI'] = '/api/cron/drain?secret=' . self::SECRET;

        $response = $this->request('GET', '/api/cron/drain?secret=' . self::SECRET);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('secret=***', $_SERVER['QUERY_STRING']);
        $this->assertStringNotContainsString(self::SECRET, $_SERVER['REQUEST_URI']);
    }

    public function test_a_request_with_no_secret_is_refused(): void
    {
        $this->db->prepare('UPDATE cron_heartbeat SET last_run_at = NULL WHERE id = 1')->execute();

        $response = $this->request('POST', '/api/cron/drain');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertNull(
            $this->heartbeat->get()['last_run_at'],
            'a refused request must not look like a scheduler run'
        );
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $response = $this->request('POST', '/api/cron/drain', headers: [
            CronController::HEADER => self::SECRET . 'x',
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_a_wrong_secret_in_the_query_string_is_refused_too(): void
    {
        $response = $this->request('GET', '/api/cron/drain?secret=not-the-secret');

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * A prefix of the real secret must not pass. `hash_equals` rather than
     * `==`, and a length check that is not a substring check.
     */
    public function test_a_prefix_of_the_secret_is_refused(): void
    {
        $response = $this->request('POST', '/api/cron/drain', headers: [
            CronController::HEADER => substr(self::SECRET, 0, 10),
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_a_refusal_discloses_nothing_about_the_queue(): void
    {
        $response = $this->request('POST', '/api/cron/drain');
        $response->getBody()->rewind();
        $body = (string) $response->getBody();

        $this->assertStringNotContainsString('claimed', $body);
        $this->assertStringNotContainsString(self::SECRET, $body);
    }
}
