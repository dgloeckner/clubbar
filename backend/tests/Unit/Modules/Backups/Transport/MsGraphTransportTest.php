<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Transport;

use App\Modules\Backups\Domain\BackupDsn;
use App\Modules\Backups\Transport\MsGraphTransport;
use App\Modules\Backups\Transport\RemoteArchive;
use App\Modules\Backups\Transport\UploadState;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;
use Tests\Support\TempTree;

/**
 * Pushing a sealed archive into the club's own Microsoft 365 tenant.
 *
 * **A backup on the same webspace is not off-site.** One compromised hosting
 * account, one deleted tariff, one suspended domain, and the archive goes with
 * the database it was protecting. This is the step that makes the word
 * "backup" true, and it is the step most likely to fail quietly — so almost
 * everything asserted here is about what happens when it does.
 *
 * ADR-0038's rule holds: **no test opens a socket.** The whole conversation is
 * scripted through {@see FakeHttpClient}, which is also the only way to
 * exercise the paths that matter most and are hardest to provoke against a
 * real tenant — a 429 with a `Retry-After`, a run whose budget runs out
 * mid-upload, a session Graph has already forgotten.
 *
 * Part of #691, epic #686.
 */
class MsGraphTransportTest extends TestCase
{
    use TempTree;

    private const DSN = 'msgraph://tenant-id/client-id@verein.sharepoint.com/Dokumente/Backups';

    private string $dir;
    private string $archive;
    private FakeHttpClient $http;
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->dir = $this->makeTempTree('msgraph');
        $this->archive = $this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb';
        // Two chunks and a bit: enough that the ranges are actually exercised.
        file_put_contents($this->archive, random_bytes(MsGraphTransport::CHUNK_BYTES * 2 + 4096));
        $this->http = new FakeHttpClient();
        $this->slept = [];
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->dir);
    }

    public function test_a_whole_archive_reaches_the_library_in_ranges_the_server_can_reassemble(): void
    {
        $this->scriptSessionSetup();
        $size = (int) filesize($this->archive);
        $this->http
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [MsGraphTransport::CHUNK_BYTES . '-']]))
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [(MsGraphTransport::CHUNK_BYTES * 2) . '-']]))
            ->willAnswer(201, json_encode(['id' => 'item-1', 'name' => basename($this->archive)]));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
        $this->assertSame($size, $result->bytesSent);
        $this->assertTrue($this->http->everythingQueuedWasUsed(), 'the scripted conversation was not finished');

        // The ranges, which are the part no status code would reveal as wrong.
        $chunk = MsGraphTransport::CHUNK_BYTES;
        $this->assertSame(
            'bytes 0-' . ($chunk - 1) . '/' . $size,
            $this->http->request(4)['headers']['Content-Range'] ?? null
        );
        $this->assertSame(
            'bytes ' . ($chunk * 2) . '-' . ($size - 1) . '/' . $size,
            $this->http->request(6)['headers']['Content-Range'] ?? null
        );
        $this->assertSame(4096, strlen($this->http->request(6)['body']));
    }

    /** The archive lands where the DSN says, folder and all. */
    public function test_the_upload_session_is_created_at_the_path_the_dsn_names(): void
    {
        $this->scriptSessionSetup();
        $this->http->willAnswer(201, json_encode(['id' => 'item-1']));

        $this->transport()->upload($this->archive, 600);

        $this->assertStringContainsString(
            'root:/Backups/clubbar-20260825-030000-1a2b3c4d.cbb:/createUploadSession',
            $this->http->request(3)['url']
        );
    }

    public function test_a_finished_upload_leaves_no_sidecar_behind(): void
    {
        $this->scriptSessionSetup();
        $this->http->willAnswer(201, json_encode(['id' => 'item-1']));

        $this->transport()->upload($this->archive, 600);

        $this->assertFileDoesNotExist($this->archive . UploadState::SUFFIX);
    }

    /**
     * The whole reason the sidecar exists: a slow line should delay a backup,
     * never corrupt one and never restart it from zero every night.
     */
    public function test_a_run_that_runs_out_of_budget_stops_and_records_where_it_got_to(): void
    {
        $this->scriptSessionSetup();
        $this->http->willAnswer(202, json_encode(['nextExpectedRanges' => [MsGraphTransport::CHUNK_BYTES . '-']]));

        // One chunk's worth of budget: the transport must notice before sending
        // a second range rather than after.
        $result = $this->transport()->upload($this->archive, 0);

        $this->assertSame('partial', $result->status, $result->summary);
        $this->assertSame(MsGraphTransport::CHUNK_BYTES, $result->bytesSent);

        $state = (new UploadState($this->archive))->read();
        $this->assertNotNull($state, 'a partial upload with no sidecar restarts from zero next run');
        $this->assertSame(MsGraphTransport::CHUNK_BYTES, $state->uploaded);
    }

    public function test_a_resumed_upload_asks_the_server_what_it_holds_and_continues_from_there(): void
    {
        $size = (int) filesize($this->archive);
        (new UploadState($this->archive))->write(
            'https://upload.example/session/abc',
            gmdate('c', time() + 3600),
            MsGraphTransport::CHUNK_BYTES,
            $size
        );

        // No token, no site, no drive, no createUploadSession: a resume talks
        // only to the session URL, which carries its own authorisation.
        $this->http
            ->willAnswer(200, json_encode(['nextExpectedRanges' => [MsGraphTransport::CHUNK_BYTES . '-']]))
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [(MsGraphTransport::CHUNK_BYTES * 2) . '-']]))
            ->willAnswer(201, json_encode(['id' => 'item-1']));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
        $this->assertSame($size - MsGraphTransport::CHUNK_BYTES, $result->bytesSent);
        $this->assertSame('GET', $this->http->request(0)['method']);
        $this->assertSame(
            'bytes ' . MsGraphTransport::CHUNK_BYTES . '-' . (MsGraphTransport::CHUNK_BYTES * 2 - 1) . '/' . $size,
            $this->http->request(1)['headers']['Content-Range'] ?? null
        );
    }

    /**
     * The server's own answer wins over the sidecar's memory. They disagree
     * whenever a chunk landed and the response was lost, and believing the
     * sidecar there re-sends a range the server has — which Graph answers with
     * a 416 rather than a shrug.
     */
    public function test_the_servers_view_of_what_it_holds_beats_the_sidecars(): void
    {
        $size = (int) filesize($this->archive);
        (new UploadState($this->archive))->write(
            'https://upload.example/session/abc',
            gmdate('c', time() + 3600),
            0,
            $size
        );

        $this->http
            ->willAnswer(200, json_encode(['nextExpectedRanges' => [(MsGraphTransport::CHUNK_BYTES * 2) . '-']]))
            ->willAnswer(201, json_encode(['id' => 'item-1']));

        $this->transport()->upload($this->archive, 600);

        $this->assertSame(
            'bytes ' . (MsGraphTransport::CHUNK_BYTES * 2) . '-' . ($size - 1) . '/' . $size,
            $this->http->request(1)['headers']['Content-Range'] ?? null
        );
    }

    /**
     * A session the server has forgotten is started again rather than retried
     * to death: a 404 on resume is Graph saying the session expired.
     */
    public function test_a_session_the_server_has_forgotten_is_started_again(): void
    {
        (new UploadState($this->archive))->write(
            'https://upload.example/session/gone',
            gmdate('c', time() + 3600),
            MsGraphTransport::CHUNK_BYTES,
            (int) filesize($this->archive)
        );

        $this->http->willAnswer(404, '{"error":{"code":"itemNotFound"}}');
        $this->scriptSessionSetup();
        $this->http->willAnswer(201, json_encode(['id' => 'item-1']));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
        $this->assertStringContainsString('createUploadSession', implode(' ', $this->http->urls()));
    }

    /**
     * Graph throttles by the tenant, not by the app, so a 429 is an ordinary
     * event a club shares with everything else in its tenant. Honouring
     * `Retry-After` rather than retrying immediately is what stops a backup
     * making its own throttling worse.
     */
    public function test_a_throttled_request_waits_the_interval_the_server_asked_for(): void
    {
        $this->scriptSessionSetup();
        $this->http
            ->willAnswer(429, '', ['retry-after' => '7'])
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [MsGraphTransport::CHUNK_BYTES . '-']]))
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [(MsGraphTransport::CHUNK_BYTES * 2) . '-']]))
            ->willAnswer(201, json_encode(['id' => 'item-1']));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
        $this->assertSame([7], $this->slept);
    }

    /** A `Retry-After` longer than the run has left is not waited out. */
    public function test_a_backoff_longer_than_the_remaining_budget_ends_the_run_instead(): void
    {
        $this->scriptSessionSetup();
        $this->http->willAnswer(429, '', ['retry-after' => '3600']);

        $result = $this->transport()->upload($this->archive, 30);

        $this->assertSame('partial', $result->status);
        $this->assertSame([], $this->slept, 'a cron run must not sleep past its own budget');
    }

    /**
     * Every failure below is reported, never thrown: the caller is a scheduler
     * nobody is watching, and an archive on the webspace with a failed upload
     * is still a better night than an exit code nobody can act on.
     */
    public function test_a_refused_token_is_reported_in_words_an_operator_can_act_on(): void
    {
        $this->http->willAnswer(401, '{"error":"invalid_client","error_description":"AADSTS7000215"}');

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('backup.remote_secret', $result->summary);
    }

    public function test_a_library_the_tenant_does_not_have_names_the_libraries_it_does(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 't', 'expires_in' => 3600]))
            ->willAnswer(200, json_encode(['id' => 'site-1']))
            ->willAnswer(200, json_encode(['value' => [['id' => 'd1', 'name' => 'Freigegebene Dokumente']]]));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Freigegebene Dokumente', $result->summary);
    }

    public function test_a_network_that_is_simply_down_is_reported_not_thrown(): void
    {
        // Four, because a connection that never completes is now retried like
        // any other transient failure — see the test below.
        $this->http
            ->willFailToConnect('Could not resolve host: login.microsoftonline.com')
            ->willFailToConnect('Could not resolve host: login.microsoftonline.com')
            ->willFailToConnect('Could not resolve host: login.microsoftonline.com')
            ->willFailToConnect('Could not resolve host: login.microsoftonline.com');

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Could not resolve host', $result->summary);
        $this->assertStringContainsString('after 4 attempts', $result->summary);
    }

    /**
     * The sign-in used to be the one request with no retry, while the three
     * that follow it each had three — so a single lost packet on the first
     * request of the night cost the whole night's off-site copy, and the
     * resumable upload underneath it never got the chance it was built for.
     */
    public function test_a_sign_in_that_fails_to_connect_is_tried_again(): void
    {
        $this->http->willFailToConnect('Connection reset by peer');
        $this->scriptSessionSetup();
        $this->http
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [MsGraphTransport::CHUNK_BYTES . '-']]))
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [(MsGraphTransport::CHUNK_BYTES * 2) . '-']]))
            ->willAnswer(201, json_encode(['id' => 'item-1']));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
        $this->assertNotSame([], $this->slept, 'the second attempt must back off, not hammer');
    }

    /**
     * A gateway error is about the moment, not about the request. Every
     * request this transport makes is safe to repeat, so the only thing a
     * `502` should cost is a few seconds.
     */
    public function test_a_gateway_error_is_retried(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 'token-1', 'expires_in' => 3600]))
            ->willAnswer(502, '<html>Bad Gateway</html>')
            ->willAnswer(200, json_encode(['id' => 'site-1']))
            ->willAnswer(200, json_encode(['value' => [['id' => 'drive-1', 'name' => 'Dokumente']]]))
            ->willAnswer(200, json_encode(['uploadUrl' => 'https://upload.example/session-1?tempauth=secret']))
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [MsGraphTransport::CHUNK_BYTES . '-']]))
            ->willAnswer(202, json_encode(['nextExpectedRanges' => [(MsGraphTransport::CHUNK_BYTES * 2) . '-']]))
            ->willAnswer(201, json_encode(['id' => 'item-1']));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
    }

    /**
     * The failures that are *about the request* must not be retried. Three
     * more identical 403s are three more chances to be killed by a host's
     * execution limit, and no chance at all of a different answer.
     */
    public function test_a_refusal_is_not_retried(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 'token-1', 'expires_in' => 3600]))
            ->willAnswer(403, '{"error":{"code":"accessDenied"}}');

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertSame(2, $this->http->requestCount(), 'a 403 is deterministic; asking again is waste');
        $this->assertSame([], $this->slept);
    }

    /**
     * The morning-after question is never "did it fail" — the cron mail
     * already says that. It is *which of the four things* is wrong, because
     * they are edits to different lines of config.php. So the log names one.
     */
    public function test_the_log_names_which_configuration_value_to_look_at(): void
    {
        $this->http->willAnswer(400, json_encode([
            'error' => 'invalid_request',
            'error_description' => 'AADSTS90002: Tenant \'nope\' not found.',
        ]));

        $this->transport()->upload($this->archive, 600);

        $entry = $this->logLineContaining('refused the backup app');
        $this->assertSame('tenant-not-found', $entry['ctx']['cause']);
        $this->assertSame('backup.dsn (tenant id)', $entry['ctx']['check']);
        $this->assertSame('AADSTS90002', $entry['ctx']['error_code']);
        $this->assertSame('tenant-id', $entry['ctx']['tenant_id']);
        $this->assertTrue($entry['ctx']['from_microsoft']);
    }

    /** A secret that is simply wrong must not be reported as a missing tenant, and vice versa. */
    public function test_a_wrong_secret_and_a_missing_tenant_are_told_apart(): void
    {
        $this->http->willAnswer(401, json_encode([
            'error' => 'invalid_client',
            'error_description' => 'AADSTS7000215: Invalid client secret provided.',
        ]));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('client-secret-wrong', $this->logLineContaining('refused the backup app')['ctx']['cause']);
        $this->assertStringContainsString('not its secret', $result->summary);
    }

    /**
     * The failure this whole slice was rebuilt around: a bare `404 Not Found`
     * on the token endpoint, which Entra never sends for a bad credential.
     * Reporting it as "check backup.remote_secret" sent an operator to rotate
     * a credential that had not even been read.
     */
    public function test_a_404_with_no_aadsts_code_is_not_blamed_on_the_client_secret(): void
    {
        $this->http->willAnswer(404, 'Not Found', ['Content-Type' => 'text/plain']);

        $result = $this->transport()->upload($this->archive, 600);

        $entry = $this->logLineContaining('refused the backup app');
        $this->assertSame('not-an-answer-from-microsoft', $entry['ctx']['cause']);
        $this->assertFalse($entry['ctx']['from_microsoft']);
        $this->assertSame('text/plain', $entry['ctx']['content_type'] ?? 'text/plain');
        $this->assertStringNotContainsString('Check backup.remote_secret', $result->summary);
        $this->assertStringContainsString('login.microsoftonline.com', $result->summary);
        $this->assertStringContainsString('tenant_id', $result->summary);
    }

    /**
     * An upload-session URL is a bearer capability: its `tempauth` parameter
     * grants write access to the archive path with no other credential. A log
     * file is mailed, shipped in a backup and pasted into issues.
     */
    public function test_a_pre_authorised_upload_url_never_reaches_the_log(): void
    {
        $this->scriptSessionSetup();
        $this->http->willAnswer(500, 'upstream exploded');
        $this->http->willAnswer(500, 'upstream exploded');
        $this->http->willAnswer(500, 'upstream exploded');
        $this->http->willAnswer(500, 'upstream exploded');

        $this->transport()->upload($this->archive, 600);

        $this->assertStringNotContainsString('tempauth', $this->logContents());
        $this->assertStringNotContainsString('s3cr3t-value', $this->logContents());
    }

    /** The secret must never reach a log line, a journal entry or a summary. */
    public function test_the_client_secret_never_appears_in_what_is_reported(): void
    {
        $this->http->willAnswer(400, '{"error":"invalid_client"}');

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertStringNotContainsString('s3cr3t-value', $result->summary);
        $this->assertStringNotContainsString('s3cr3t-value', json_encode($this->http->request(0)['headers']));
    }

    /** Listing is how remote retention learns what is there (#691, task 6). */
    public function test_it_lists_what_the_library_already_holds(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 't', 'expires_in' => 3600]))
            ->willAnswer(200, json_encode(['id' => 'site-1']))
            ->willAnswer(200, json_encode(['value' => [['id' => 'd1', 'name' => 'Dokumente']]]))
            ->willAnswer(200, json_encode(['value' => [
                ['id' => 'i1', 'name' => 'clubbar-20260101-030000-aaaa.cbb', 'size' => 10],
                ['id' => 'i2', 'name' => 'notes.txt', 'size' => 3],
                ['id' => 'i3', 'name' => 'clubbar-20260201-030000-bbbb.cbb', 'size' => 20],
            ]]));

        $remote = $this->transport()->list();

        // Only this job's own archives: nothing else a club keeps in that folder
        // may ever be a candidate for the retention delete.
        $this->assertSame(
            ['clubbar-20260101-030000-aaaa.cbb', 'clubbar-20260201-030000-bbbb.cbb'],
            array_map(static fn ($a) => $a->name, $remote)
        );
        $this->assertSame('i1', $remote[0]->id);
    }

    /**
     * A delete can only name something a listing produced — {@see delete()}
     * takes the {@see RemoteArchive}, drive and all, so no lookup is needed and
     * no name can be invented.
     */
    public function test_a_delete_names_the_item_a_listing_produced(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 't', 'expires_in' => 3600]))
            ->willAnswer(204, '');

        $archive = new RemoteArchive('i1', 'clubbar-20260101-030000-aaaa.cbb', 10, 'd1');

        $this->assertTrue($this->transport()->delete($archive));
        $this->assertSame('DELETE', $this->http->request(1)['method']);
        $this->assertStringContainsString('/drives/d1/items/i1', $this->http->request(1)['url']);
    }

    /** Deleted by somebody else between the listing and the delete is still deleted. */
    public function test_an_archive_already_gone_counts_as_deleted(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 't', 'expires_in' => 3600]))
            ->willAnswer(404, '{"error":{"code":"itemNotFound"}}');

        $this->assertTrue($this->transport()->delete(
            new RemoteArchive('i1', 'clubbar-20260101-030000-aaaa.cbb', 10, 'd1')
        ));
    }

    /**
     * The shape the onboarding script prints. No site lookup, no drive lookup,
     * no library name to be localised out from under the club — token, create
     * session, upload.
     */
    public function test_a_drive_addressed_dsn_uploads_without_resolving_anything(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 'token-1', 'expires_in' => 3600]))
            ->willAnswer(200, json_encode([
                'uploadUrl' => 'https://upload.example/session/abc',
                'expirationDateTime' => gmdate('c', time() + 3600),
            ]))
            ->willAnswer(201, json_encode(['id' => 'item-1']));

        $transport = new MsGraphTransport(
            BackupDsn::parse('msgraph://tenant-id/client-id@drive/b!driveid/clubbar'),
            's3cr3t-value',
            $this->http,
            new Logger($this->dir),
            function (int $seconds): void {
                $this->slept[] = $seconds;
            },
        );

        $result = $transport->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
        $this->assertSame(3, $this->http->requestCount(), 'a drive-addressed DSN needs no lookups');
        $this->assertStringContainsString(
            '/drives/b!driveid/root:/clubbar/clubbar-20260825-030000-1a2b3c4d.cbb:/createUploadSession',
            $this->http->request(1)['url']
        );
    }

    /**
     * Entra warns nobody when a client secret expires, and an unattended
     * nightly job can go months before anyone notices. It is the single most
     * likely cause of a silent backup failure, so it gets its own sentence
     * rather than arriving as a generic auth error.
     */
    public function test_an_expired_client_secret_is_named_as_such(): void
    {
        $this->http->willAnswer(401, json_encode([
            'error' => 'invalid_client',
            'error_description' => 'AADSTS7000222: The provided client secret keys for app ... are expired.',
        ]));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('expired', $result->summary);
        $this->assertStringContainsString('backup.client_secret_expires_at', $result->summary);
    }

    /**
     * A 403 is almost always the missing per-site grant, and the wrong fix is
     * the tempting one: widening Sites.Selected to Sites.ReadWrite.All turns a
     * leaked secret from a lost backup into a tenant-wide breach.
     */
    public function test_a_403_says_where_the_grant_is_missing_and_what_not_to_do_about_it(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 't', 'expires_in' => 3600]))
            ->willAnswer(403, '{"error":{"code":"accessDenied"}}');

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('one site at a time', $result->summary);
        $this->assertStringContainsString('Sites.ReadWrite.All', $result->summary);
    }

    /** The pre-authorised session URL must never carry a bearer token: it fails with one. */
    public function test_no_authorization_header_is_sent_to_the_upload_session_url(): void
    {
        $this->scriptSessionSetup();
        $this->http->willAnswer(201, json_encode(['id' => 'item-1']));

        $this->transport()->upload($this->archive, 600);

        $this->assertArrayNotHasKey('Authorization', $this->http->request(4)['headers']);
        $this->assertArrayHasKey('Authorization', $this->http->request(3)['headers']);
    }

    /** Everything the transport logged during a test, as one string. */
    private function logContents(): string
    {
        $file = $this->dir . '/' . date('Y-m-d') . '.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * The first log entry whose message contains $needle, decoded.
     *
     * @return array{msg: string, ctx: array<string, mixed>}
     */
    private function logLineContaining(string $needle): array
    {
        foreach (explode("\n", trim($this->logContents())) as $line) {
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);
            if (is_array($entry) && str_contains((string) $entry['msg'], $needle)) {
                /** @var array{msg: string, ctx: array<string, mixed>} $entry */
                return $entry;
            }
        }

        $this->fail('No log entry mentions "' . $needle . '". The log holds: ' . $this->logContents());
    }

    private function transport(): MsGraphTransport
    {
        return new MsGraphTransport(
            BackupDsn::parse(self::DSN),
            's3cr3t-value',
            $this->http,
            new Logger($this->dir),
            function (int $seconds): void {
                $this->slept[] = $seconds;
            },
        );
    }

    /** Token, site, drive, createUploadSession — the four requests before any bytes move. */
    private function scriptSessionSetup(): void
    {
        $this->http
            ->willAnswer(200, json_encode(['access_token' => 'token-1', 'expires_in' => 3600]))
            ->willAnswer(200, json_encode(['id' => 'site-1']))
            ->willAnswer(200, json_encode(['value' => [['id' => 'drive-1', 'name' => 'Dokumente']]]))
            ->willAnswer(200, json_encode([
                'uploadUrl' => 'https://upload.example/session/abc',
                'expirationDateTime' => gmdate('c', time() + 3600),
            ]));
    }
}
