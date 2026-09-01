<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Documents;

use App\Modules\Registrations\Documents\CurlTemplateFetcher;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalWebServer;
use Tests\Support\TempTree;

/**
 * The one class in this feature that talks to somebody else's webserver (#780).
 *
 * Tested against a **real** server rather than a stub, for the reason
 * `HttpProbeTest` gives about the probe: everything worth asserting here is
 * about what a foreign server actually did — a redirect a club's CMS left
 * behind, a 404 dressed as an HTML page, a response too large to be a mandate —
 * and a fake would agree with whatever this class expected and prove nothing.
 *
 * `LocalWebServer` is the only sanctioned way to start one; a `php -S` started
 * by hand leaks a grandchild that holds phpunit's output pipe open forever (see
 * that class's docblock).
 */
final class CurlTemplateFetcherTest extends TestCase
{
    use TempTree;

    private const PDF = "%PDF-1.4\nnot really, but it is bytes\n%%EOF\n";

    private ?LocalWebServer $server = null;
    private string $documentRoot;
    private string $baseUrl;
    private CurlTemplateFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Assigned before anything can skip, and removed only through
        // TempTree — the destructive-cleanup rule in CLAUDE.md exists because
        // a `glob()` on an unset property once emptied a container's root.
        $this->documentRoot = self::makeTempTree('clubbar-template-fetch');
        file_put_contents($this->documentRoot . '/anmeldung.pdf', self::PDF);
        file_put_contents(
            $this->documentRoot . '/router.php',
            <<<'PHP'
                <?php
                // A club CMS that moved the file and left a redirect behind —
                // Kirby, which the reference installation runs, does this.
                if ($_SERVER['REQUEST_URI'] === '/moved.pdf') {
                    header('Location: /anmeldung.pdf', true, 302);
                    return true;
                }
                // A redirect chain past the cap.
                if (preg_match('~^/loop/(\d+)$~', $_SERVER['REQUEST_URI'], $m)) {
                    header('Location: /loop/' . ((int) $m[1] + 1), true, 302);
                    return true;
                }
                // A webhost answering a missing file with an HTML page and 200:
                // the case that reads as "broken template" and is not one.
                if ($_SERVER['REQUEST_URI'] === '/friendly-404') {
                    echo '<html><body>Seite nicht gefunden</body></html>';
                    return true;
                }
                if ($_SERVER['REQUEST_URI'] === '/enormous') {
                    // Comfortably past the cap, streamed so the cap has to stop it.
                    for ($i = 0; $i < 24; $i++) {
                        echo str_repeat('x', 512 * 1024);
                        flush();
                    }
                    return true;
                }
                if ($_SERVER['REQUEST_URI'] === '/broken') {
                    http_response_code(500);
                    echo 'nope';
                    return true;
                }
                return false;
                PHP
        );

        $server = LocalWebServer::start($this->documentRoot . '/router.php', $this->documentRoot);
        if ($server === null) {
            self::markTestSkipped('Could not start a local webserver.');
        }

        $this->server = $server;
        $this->baseUrl = $server->baseUrl();
        $this->fetcher = new CurlTemplateFetcher(
            new Logger(sys_get_temp_dir() . '/template-fetcher-tests', 'CRITICAL'),
        );
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        self::removeTempTree($this->documentRoot);

        parent::tearDown();
    }

    public function test_it_returns_the_bytes_a_server_served(): void
    {
        self::assertSame(self::PDF, $this->fetcher->fetch($this->baseUrl . '/anmeldung.pdf'));
    }

    /**
     * A club's CMS moves a file and leaves a redirect behind. Not following one
     * would refuse a document that is perfectly reachable in a browser, which is
     * where the admin checked it.
     */
    public function test_it_follows_a_redirect_the_club_cms_left_behind(): void
    {
        self::assertSame(self::PDF, $this->fetcher->fetch($this->baseUrl . '/moved.pdf'));
    }

    /** A chain longer than the cap is a misconfiguration, not a document. */
    public function test_it_gives_up_on_an_endless_redirect_chain(): void
    {
        self::assertNull($this->fetcher->fetch($this->baseUrl . '/loop/1'));
    }

    public function test_a_non_2xx_answer_is_no_document(): void
    {
        self::assertNull($this->fetcher->fetch($this->baseUrl . '/broken'));
        self::assertNull($this->fetcher->fetch($this->baseUrl . '/not-there.pdf'));
    }

    /**
     * A 200 carrying an HTML error page **is** returned — this class's job is
     * bytes, not judgement. Deciding it is not a PDF belongs to the enumerator,
     * which can say so in a sentence the club can act on; a fetcher that
     * silently swallowed it would turn "your URL is wrong" into "no document",
     * which is the least useful of the two answers.
     */
    public function test_it_does_not_second_guess_what_a_server_served(): void
    {
        $body = $this->fetcher->fetch($this->baseUrl . '/friendly-404');

        self::assertNotNull($body);
        self::assertStringContainsString('Seite nicht gefunden', $body);
    }

    /**
     * The cap matters because of *where* this runs: inside an applicant's own
     * submission request, against a URL an administrator typed. A mistyped host
     * serving something enormous would otherwise hold that request open filling
     * memory until the timeout.
     */
    public function test_it_refuses_a_response_too_large_to_be_a_mandate(): void
    {
        self::assertNull($this->fetcher->fetch($this->baseUrl . '/enormous'));
    }

    public function test_an_unreachable_host_is_no_document_rather_than_an_exception(): void
    {
        // Port 1 on the loopback: nothing listens, and the connect fails fast.
        self::assertNull($this->fetcher->fetch('http://127.0.0.1:1/anmeldung.pdf', 2));
    }
}
