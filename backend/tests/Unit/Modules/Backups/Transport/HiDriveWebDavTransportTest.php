<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Transport;

use App\Modules\Backups\Domain\BackupDsn;
use App\Modules\Backups\Domain\HiDriveDsn;
use App\Modules\Backups\Transport\BackupTransportException;
use App\Modules\Backups\Transport\HiDriveWebDavTransport;
use App\Modules\Backups\Transport\RemoteArchive;
use App\Modules\Backups\Transport\UploadState;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;
use Tests\Support\TempTree;

/**
 * Pushing a sealed archive into a HiDrive folder over WebDAV.
 *
 * The transport exists because the Microsoft one cannot be reached from the
 * reference host (#825), so the bar it has to clear is the same: **the step
 * that makes the word "backup" true, and the step most likely to fail
 * quietly.** Almost everything asserted here is about what happens when it
 * does — a folder that is not there, a password that stopped working, a tariff
 * that is full, and an upload the server accepted but did not store whole.
 *
 * ADR-0038's rule holds: no test opens a socket.
 *
 * Part of #825, epic #686.
 */
class HiDriveWebDavTransportTest extends TestCase
{
    use TempTree;

    private const DSN = 'hidrive://clubbar-backup@webdav.hidrive.ionos.com/users/clubbar-backup/archives';
    private const FOLDER = 'https://webdav.hidrive.ionos.com/users/clubbar-backup/archives';

    private string $dir;
    private string $archive;
    private int $size;
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempTree('hidrive');
        $this->archive = $this->dir . '/clubbar-20260904-030000-1a2b3c4d.cbb';
        file_put_contents($this->archive, random_bytes(4096));
        $this->size = (int) filesize($this->archive);
        $this->http = new FakeHttpClient();
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->dir);
    }

    public function test_an_archive_is_put_then_read_back_before_it_is_called_uploaded(): void
    {
        $this->http
            ->willAnswer(201)
            ->willAnswer(207, $this->multiStatus([
                '/users/clubbar-backup/archives/' . basename($this->archive) => $this->size,
            ]));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('uploaded', $result->status, $result->summary);
        $this->assertSame($this->size, $result->bytesSent);
        $this->assertSame('users/clubbar-backup/archives/' . basename($this->archive), $result->remotePath);
        $this->assertTrue($this->http->everythingQueuedWasUsed());

        // The upload streams from the file rather than carrying its bytes in a
        // string — the property that keeps peak memory flat as the club's
        // database grows past whatever `memory_limit` the host allows.
        $put = $this->http->request(0);
        $this->assertSame('PUT', $put['method']);
        $this->assertSame(self::FOLDER . '/' . basename($this->archive), $put['url']);
        $this->assertSame($this->archive, $put['file']);
        $this->assertSame('', $put['body']);

        // Basic, pre-emptively: waiting for the 401 challenge would double every
        // request against a server that accepts exactly one scheme.
        $this->assertSame(
            'Basic ' . base64_encode('clubbar-backup:hunter2'),
            $put['headers']['Authorization'] ?? null
        );

        $verify = $this->http->request(1);
        $this->assertSame('PROPFIND', $verify['method']);
        $this->assertSame(self::FOLDER . '/' . basename($this->archive), $verify['url']);
        $this->assertSame('0', $verify['headers']['Depth'] ?? null);
    }

    /**
     * A `201` is a claim; the length read back is the evidence.
     *
     * And on a mismatch nothing is deleted. A delete triggered by a size
     * comparison is a delete that fires on a bug in our own arithmetic, on the
     * one file the club may need.
     */
    public function test_a_short_upload_is_a_failure_and_nothing_is_deleted(): void
    {
        $this->http
            ->willAnswer(201)
            ->willAnswer(207, $this->multiStatus([
                '/users/clubbar-backup/archives/' . basename($this->archive) => $this->size - 17,
            ]));

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('the remote reports', $result->summary);
        $this->assertStringContainsString('Nothing has been deleted', $result->summary);
        $this->assertSame(2, $this->http->requestCount(), 'no third request may follow a mismatch');
        $this->assertPendingSweepFindsTheArchive();
    }

    /**
     * The hole a one-shot transport would otherwise open.
     *
     * `UploadState::pendingIn()` is the only thing in the system that ever
     * revisits an archive, and it sweeps by sidecar presence. Without a marker
     * a failed night is silently a *lost* night: the next run seals and pushes
     * a different archive, and this one never leaves the webspace.
     */
    public function test_a_failed_upload_leaves_a_marker_so_the_next_run_comes_back_to_it(): void
    {
        $this->http->willFailToConnect('Could not resolve host');

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Could not reach webdav.hidrive.ionos.com', $result->summary);
        $this->assertPendingSweepFindsTheArchive();
    }

    public function test_a_finished_upload_clears_the_marker(): void
    {
        $this->http
            ->willAnswer(201)
            ->willAnswer(207, $this->multiStatus([
                '/users/clubbar-backup/archives/' . basename($this->archive) => $this->size,
            ]));

        $this->transport()->upload($this->archive, 600);

        $this->assertSame([], UploadState::pendingIn($this->dir));
    }

    /**
     * Each refusal names the thing to go and look at.
     *
     * A single "upload failed (HTTP %d)" would make four completely different
     * mornings' work look identical: a password, a folder, a tariff, a network.
     */
    public function test_each_refusal_names_what_an_operator_has_to_change(): void
    {
        $cases = [
            401 => 'backup.remote_secret',
            403 => 'Access rights and protocols',
            409 => 'does not exist on the remote',
            507 => 'No space left',
        ];

        foreach ($cases as $status => $expected) {
            $this->http = new FakeHttpClient();
            $this->http->willAnswer($status, 'nope');

            $result = $this->transport()->upload($this->archive, 600);

            $this->assertSame('failed', $result->status, 'HTTP ' . $status);
            $this->assertStringContainsString($expected, $result->summary, 'HTTP ' . $status);
        }
    }

    /**
     * The folder is never conjured up.
     *
     * A `MKCOL` here would turn a mistyped DSN into a *successful* upload into
     * a folder nobody watches — ADR-0049's founding failure, "we have off-site
     * backups" held by a club that does not, reached by a typo. So the refusal
     * has to both name the folder and say the creating is not going to happen.
     */
    public function test_a_missing_folder_is_refused_rather_than_created(): void
    {
        $this->http->willAnswer(409, '<html><body>Conflict</body></html>');

        $result = $this->transport()->upload($this->archive, 600);

        $this->assertSame(1, $this->http->requestCount(), 'a MKCOL would be a second request');
        $this->assertStringContainsString('archives', $result->summary);
        $this->assertStringContainsString('never creates it', $result->summary);
    }

    public function test_a_listing_returns_this_jobs_archives_and_nothing_else(): void
    {
        $this->http->willAnswer(207, $this->multiStatus(
            [
                '/users/clubbar-backup/archives/clubbar-20260902-030000-bbbbbbbb.cbb' => 2048,
                '/users/clubbar-backup/archives/clubbar-20260901-030000-aaaaaaaa.cbb' => 1024,
                // A club keeps other things in that folder, and none of them may
                // ever become a candidate for the retention delete.
                '/users/clubbar-backup/archives/key-envelope.txt' => 42,
                '/users/clubbar-backup/archives/notes-for-my-successor.md' => 99,
            ],
            collection: '/users/clubbar-backup/archives/'
        ));

        $archives = $this->transport()->list();

        $this->assertCount(2, $archives);
        $this->assertSame(
            ['clubbar-20260901-030000-aaaaaaaa.cbb', 'clubbar-20260902-030000-bbbbbbbb.cbb'],
            array_map(static fn (RemoteArchive $a): string => $a->name, $archives)
        );
        $this->assertSame(1024, $archives[0]->size);

        // A rooted href is rebuilt onto the DSN's host, so every archive handed
        // out is something DELETE can be sent to without the caller knowing
        // which form the server used.
        $this->assertSame(self::FOLDER . '/clubbar-20260901-030000-aaaaaaaa.cbb', $archives[0]->id);

        $listing = $this->http->request(0);
        $this->assertSame('PROPFIND', $listing['method']);
        $this->assertSame(self::FOLDER, $listing['url']);
        $this->assertSame('1', $listing['headers']['Depth'] ?? null);
    }

    /**
     * The prefix belongs to the server, not to us.
     *
     * mod_dav writes `<D:response>`; others write `<d:response>` or default the
     * namespace and write `<response>`. All three are the same document, and a
     * parser that matches on the prefix works against one server and silently
     * returns *nothing* for the next — which here reads as "the remote holds no
     * archives" and puts retention to work on that belief.
     */
    public function test_a_listing_is_parsed_by_namespace_rather_than_by_prefix(): void
    {
        $this->http->willAnswer(207, <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <multistatus xmlns="DAV:">
              <response>
                <href>https://webdav.hidrive.ionos.com/users/clubbar-backup/archives/clubbar-20260901-030000-aaaaaaaa.cbb</href>
                <propstat><prop><getcontentlength>1024</getcontentlength><resourcetype/></prop></propstat>
              </response>
            </multistatus>
            XML);

        $archives = $this->transport()->list();

        $this->assertCount(1, $archives);
        $this->assertSame(1024, $archives[0]->size);
        $this->assertSame(
            'https://webdav.hidrive.ionos.com/users/clubbar-backup/archives/clubbar-20260901-030000-aaaaaaaa.cbb',
            $archives[0]->id,
            'an absolute href is used as it stands'
        );
    }

    public function test_a_listing_that_fails_throws_rather_than_reporting_an_empty_remote(): void
    {
        $this->http->willAnswer(401, 'Unauthorized');

        $this->expectException(BackupTransportException::class);
        // An empty list would be read by retention as "nothing is up there",
        // which is indistinguishable from a remote it could not see.
        $this->expectExceptionMessageMatches('/nothing has been deleted/i');

        $this->transport()->list();
    }

    public function test_a_delete_of_something_already_gone_counts_as_done(): void
    {
        $this->http->willAnswer(404);

        $this->assertTrue($this->transport()->delete($this->remoteArchive()));
    }

    public function test_a_delete_that_is_refused_is_reported_rather_than_assumed(): void
    {
        $this->http->willAnswer(423, 'Locked');

        $this->assertFalse($this->transport()->delete($this->remoteArchive()));
        $this->assertSame(self::FOLDER . '/clubbar-20260901-030000-aaaaaaaa.cbb', $this->http->request(0)['url']);
    }

    /**
     * The run's budget bounds the request, because nothing else does.
     *
     * There is no resume here, so a `PUT` allowed to run past the budget is a
     * cron job killed mid-write by the host's execution limit rather than one
     * reporting a clean failure the next run can act on.
     */
    public function test_a_request_never_outlives_the_runs_budget(): void
    {
        $this->http->willAnswer(201)->willAnswer(207, $this->multiStatus([
            '/users/clubbar-backup/archives/' . basename($this->archive) => $this->size,
        ]));

        $this->transport()->upload($this->archive, 5);

        // FakeHttpClient does not record the timeout, so this asserts the only
        // observable consequence: the transport still completes rather than
        // clamping itself to zero and refusing to send.
        $this->assertSame(2, $this->http->requestCount());
    }

    private function assertPendingSweepFindsTheArchive(): void
    {
        $this->assertSame(
            [$this->archive],
            UploadState::pendingIn($this->dir),
            'the sidecar marker is what brings the next run back to an archive that has not landed'
        );
    }

    private function remoteArchive(): RemoteArchive
    {
        return new RemoteArchive(
            self::FOLDER . '/clubbar-20260901-030000-aaaaaaaa.cbb',
            'clubbar-20260901-030000-aaaaaaaa.cbb',
            1024,
            self::FOLDER,
        );
    }

    /** @param array<string, int> $entries href => length */
    private function multiStatus(array $entries, ?string $collection = null): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<D:multistatus xmlns:D="DAV:">';

        if ($collection !== null) {
            // Depth: 1 returns the collection itself first. Skipped by resource
            // type rather than by comparing hrefs, because servers disagree
            // about the trailing slash and about absolute versus rooted.
            $xml .= '<D:response><D:href>' . $collection . '</D:href><D:propstat><D:prop>'
                . '<D:resourcetype><D:collection/></D:resourcetype>'
                . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }

        foreach ($entries as $href => $length) {
            $xml .= '<D:response><D:href>' . $href . '</D:href><D:propstat><D:prop>'
                . '<D:getcontentlength>' . $length . '</D:getcontentlength>'
                . '<D:resourcetype/>'
                . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }

        return $xml . '</D:multistatus>';
    }

    private function transport(): HiDriveWebDavTransport
    {
        $dsn = BackupDsn::parse(self::DSN);
        self::assertInstanceOf(HiDriveDsn::class, $dsn);

        return new HiDriveWebDavTransport($dsn, 'hunter2', $this->http, new Logger($this->dir));
    }
}
