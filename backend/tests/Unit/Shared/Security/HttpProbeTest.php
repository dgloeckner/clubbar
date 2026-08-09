<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\HttpProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The probe is what turns "we ship an `.htaccess` denial" into "the webserver
 * refused it", so it is tested against a real webserver rather than a stub: a
 * mocked fetch would agree with whatever the self-check expected and prove
 * nothing about the one question this class exists to ask.
 *
 * Both transports are exercised, because which one runs is the host's decision
 * — a shared host with cURL disabled falls back to streams, and that is the
 * kind of host the self-check was written for.
 */
class HttpProbeTest extends TestCase
{
    private const DOCUMENT = "clubbar canary\n";

    /** @var resource|null */
    private $server = null;
    private string $documentRoot;
    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentRoot = sys_get_temp_dir() . '/clubbar-probe-' . bin2hex(random_bytes(6));
        mkdir($this->documentRoot, 0700, true);
        file_put_contents($this->documentRoot . '/canary.txt', self::DOCUMENT);
        // The router lets one response carry a header worth reading back: HSTS
        // is only observable on the response itself.
        file_put_contents($this->documentRoot . '/router.php', <<<'PHP'
            <?php
            if ($_SERVER['REQUEST_URI'] === '/secured') {
                header('Strict-Transport-Security: max-age=31536000');
                echo 'ok';
                return true;
            }
            return false;
            PHP);

        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        foreach (glob($this->documentRoot . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->documentRoot);

        parent::tearDown();
    }

    /**
     * @return list<array{callable(string):array{status:int|null,headers:array<string,string>}}>
     */
    public static function transports(): array
    {
        return [
            'curl'    => [static fn(string $url) => HttpProbe::fetchWithCurl($url)],
            'streams' => [static fn(string $url) => HttpProbe::fetchWithStreams($url)],
        ];
    }

    #[DataProvider('transports')]
    public function test_it_reports_the_status_a_served_file_answered_with(callable $fetch): void
    {
        $this->assertSame(200, $fetch($this->baseUrl . '/canary.txt')['status']);
    }

    #[DataProvider('transports')]
    public function test_it_reports_a_refusal_rather_than_treating_it_as_a_failure(callable $fetch): void
    {
        // 404 is one of the two answers the exposure rows accept as a pass, so
        // it has to arrive as a status and not as "the fetch did not work".
        $this->assertSame(404, $fetch($this->baseUrl . '/not-there.pdf')['status']);
    }

    #[DataProvider('transports')]
    public function test_it_reads_back_the_response_headers(callable $fetch): void
    {
        $headers = $fetch($this->baseUrl . '/secured')['headers'];

        $this->assertSame('max-age=31536000', $headers['strict-transport-security'] ?? null);
    }

    #[DataProvider('transports')]
    public function test_a_host_that_does_not_answer_yields_no_status_at_all(callable $fetch): void
    {
        // Port 1: nothing listens there. The self-check turns this into
        // UNKNOWN — never into a pass.
        $this->assertNull($fetch('http://127.0.0.1:1/canary.txt')['status']);
    }

    public function test_fetch_picks_a_transport_this_host_actually_has(): void
    {
        $this->assertSame(200, HttpProbe::fetch($this->baseUrl . '/canary.txt')['status']);
    }

    private function startServer(): void
    {
        // Port 0 is not an option for PHP's built-in server, so a high port is
        // picked and retried: two test processes must not collide.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(20000, 60000);
            $command = sprintf(
                '%s -S 127.0.0.1:%d -t %s %s',
                escapeshellarg(PHP_BINARY),
                $port,
                escapeshellarg($this->documentRoot),
                escapeshellarg($this->documentRoot . '/router.php'),
            );

            $server = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($server)) {
                continue;
            }

            $this->baseUrl = "http://127.0.0.1:{$port}";
            if ($this->waitForServer()) {
                $this->server = $server;

                return;
            }

            proc_terminate($server);
            proc_close($server);
        }

        $this->markTestSkipped('Could not start a local webserver to probe');
    }

    private function waitForServer(): bool
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', (int) parse_url($this->baseUrl, PHP_URL_PORT), $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }
            usleep(100_000);
        }

        return false;
    }
}
