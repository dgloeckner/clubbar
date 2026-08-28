<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Controllers;

use App\Modules\Backups\Controllers\BackupsController;
use App\Modules\Backups\Services\BackupsInventory;
use App\Modules\Backups\Services\RemoteInventory;
use App\Modules\Backups\Services\RemoteLookup;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Support\TempTree;

/**
 * The three routes behind the backups page (#693, ADR-0049).
 *
 * The download's guard is the part that matters most here. Its filename arrives
 * **from the URL**, so without a guard an admin session reads arbitrary server
 * files — `config.php` among them, which carries the database password and the
 * key that encrypts every admin's second factor.
 *
 * The e2e suite asserts the same refusals over real HTTP. This asserts them
 * where they are cheap to run and fast to fail, because a security boundary
 * should not be reachable only through a browser and a running stack.
 *
 * Part of #693, epic #686.
 */
class BackupsControllerTest extends TestCase
{
    use TempTree;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = self::makeTempTree('backups-controller');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    public function test_the_local_list_carries_archives_and_keys(): void
    {
        $this->archive('clubbar-20260825-030000-1a2b3c4d.cbb');

        $body = $this->decode($this->controller()->index($this->request(), new Response()));

        $this->assertArrayHasKey('archives', $body);
        $this->assertArrayHasKey('keys', $body);
        $this->assertCount(1, $body['archives']);
    }

    /**
     * Its own route, and its own vocabulary. With no transport and no snapshot
     * the honest answer is that nobody has looked — never that the store is
     * empty.
     */
    public function test_the_remote_route_answers_with_a_source(): void
    {
        $body = $this->decode($this->controller()->remote($this->request(), new Response()));

        $this->assertSame(RemoteLookup::SOURCE_UNAVAILABLE, $body['source']);
        $this->assertSame([], $body['names']);
    }

    public function test_an_archive_downloads_with_its_own_name(): void
    {
        $this->archive('clubbar-20260825-030000-1a2b3c4d.cbb');

        $response = $this->controller()->download(
            $this->request(),
            new Response(),
            ['name' => 'clubbar-20260825-030000-1a2b3c4d.cbb']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'clubbar-20260825-030000-1a2b3c4d.cbb',
            $response->getHeaderLine('Content-Disposition')
        );
        // Sealed or not, it is the club's whole database: no proxy or shared
        // browser cache should keep a copy once the download is done.
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * **The guard, from every direction it can be pushed.** A traversal, an
     * absolute path, and the directory's own non-archive files — the journal and
     * the remote snapshot both sit beside the archives and neither is one.
     */
    public function test_the_download_serves_nothing_but_an_archive(): void
    {
        $this->archive('clubbar-20260825-030000-1a2b3c4d.cbb');
        file_put_contents($this->dir . '/index.jsonl', '{"event":"started"}');
        file_put_contents($this->dir . '/' . RemoteInventory::FILENAME, '{"archives":[]}');
        file_put_contents($this->dir . '/notes.txt', 'the key is in the safe');

        foreach ([
            '../../config.php',
            '../config.php',
            '/etc/passwd',
            'index.jsonl',
            RemoteInventory::FILENAME,
            'notes.txt',
            'clubbar-nope.txt',
            '',
        ] as $name) {
            $response = $this->controller()->download($this->request(), new Response(), ['name' => $name]);

            $this->assertSame(404, $response->getStatusCode(), sprintf('"%s" must not be served', $name));
        }
    }

    /**
     * A name that looks like an archive but is not there is a 404 rather than a
     * 500 — the directory is the truth, and an operator who deleted a file by
     * hand has deleted it.
     */
    public function test_a_missing_archive_is_not_found(): void
    {
        $response = $this->controller()->download(
            $this->request(),
            new Response(),
            ['name' => 'clubbar-19700101-000000-deadbeef.cbb']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------- helpers

    private function controller(): BackupsController
    {
        return new BackupsController(
            new BackupsInventory($this->dir),
            new RemoteLookup(new RemoteInventory($this->dir), $this->createMock(Logger::class)),
            $this->dir,
        );
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/backups');
    }

    /** @return array<string,mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function archive(string $name): void
    {
        // Content does not matter here: the header is unreadable, which the
        // inventory reports rather than hides, and the download streams bytes
        // either way.
        file_put_contents($this->dir . '/' . $name, 'sealed-bytes');
    }
}
