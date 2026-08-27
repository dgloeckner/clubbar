<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Monitoring;

use App\Shared\Monitoring\HeartbeatPinger;
use App\Shared\Http\OutboundHttpClient;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The alarm channel (#406, ADR-0038 rule 6).
 *
 * Three properties are asserted here and each one is a decision rather than an
 * implementation detail: the *outcome* decides the path, no personal data
 * leaves the host, and nothing this class does can take the drain down with it.
 *
 * No socket is opened — ADR-0038 says none ever is, and this is the class that
 * would.
 */
class HeartbeatPingerTest extends TestCase
{
    private const CHECK_URL = 'https://monitor.example/8f14e45f';

    private function pinger(OutboundHttpClient $http, ?string $url = self::CHECK_URL): HeartbeatPinger
    {
        return new HeartbeatPinger($http, $this->createMock(Logger::class), $url);
    }

    public function test_a_starting_run_pings_the_start_path(): void
    {
        $http = new SpyHttpClient();
        $this->pinger($http)->start();

        $this->assertSame([self::CHECK_URL . '/start'], $http->urls());
    }

    public function test_a_finished_run_pings_the_base_url(): void
    {
        $http = new SpyHttpClient();
        $this->pinger($http)->success(['pending' => 0, 'failed' => 0]);

        $this->assertSame([self::CHECK_URL], $http->urls());
    }

    public function test_a_failure_pings_the_fail_path(): void
    {
        $http = new SpyHttpClient();
        $this->pinger($http)->fail(HeartbeatPinger::REASON_QUEUE_STALLED);

        $this->assertSame([self::CHECK_URL . '/fail'], $http->urls());
    }

    /**
     * A trailing slash in a pasted check URL must not produce `…//start`.
     * People paste these out of a browser's address bar.
     */
    public function test_a_trailing_slash_in_the_configured_url_is_tolerated(): void
    {
        $http = new SpyHttpClient();
        $this->pinger($http, self::CHECK_URL . '/')->start();

        $this->assertSame([self::CHECK_URL . '/start'], $http->urls());
    }

    public function test_an_unconfigured_monitor_pings_nothing(): void
    {
        $http = new SpyHttpClient();
        $pinger = $this->pinger($http, null);

        $pinger->start();
        $pinger->success(['pending' => 1]);
        $pinger->fail(HeartbeatPinger::REASON_RUN_ABORTED);

        $this->assertSame([], $http->urls(), 'CI and development have no monitor and must stay silent');
        $this->assertFalse($pinger->isConfigured());
    }

    public function test_the_body_carries_the_counts(): void
    {
        $http = new SpyHttpClient();
        $this->pinger($http)->success(['pending' => 3, 'failed' => 1, 'sent' => 47]);

        $body = $http->calls[0]['body'];
        $this->assertStringContainsString('pending=3', $body);
        $this->assertStringContainsString('failed=1', $body);
        $this->assertStringContainsString('sent=47', $body);
    }

    public function test_a_failure_names_its_reason_from_the_fixed_vocabulary(): void
    {
        $http = new SpyHttpClient();
        $this->pinger($http)->fail(HeartbeatPinger::REASON_TRANSPORT_UNAVAILABLE);

        $this->assertStringContainsString('reason=' . HeartbeatPinger::REASON_TRANSPORT_UNAVAILABLE, $http->calls[0]['body']);
        $this->assertStringContainsString(
            HeartbeatPinger::REASONS[HeartbeatPinger::REASON_TRANSPORT_UNAVAILABLE],
            $http->calls[0]['body']
        );
    }

    /**
     * The rule that matters most here, and the one a debugging session would
     * otherwise break: an SMTP rejection quotes the recipient back at you
     * (`550 5.1.1 <someone@example.org> unknown`), and none of it may reach a
     * third-party monitor. The reason is looked up, never interpolated.
     */
    public function test_no_address_or_name_can_travel_in_a_ping(): void
    {
        $http = new SpyHttpClient();

        $this->pinger($http)->fail(
            '550 5.1.1 <mitglied@example.org> unknown',
            ['pending' => 2, 'last_error' => 'mitglied@example.org', 'recipient' => 'Erika Mustermann'],
        );

        $body = $http->calls[0]['body'];
        $this->assertStringNotContainsString('@', $body);
        $this->assertStringNotContainsString('Mustermann', $body);
        $this->assertStringContainsString('pending=2', $body);
        $this->assertStringContainsString('detail=unspecified', $body, 'an unknown reason is not passed through');
    }

    /**
     * A monitor that can kill the job it watches is a net loss. This one runs
     * at both ends of the only sending path.
     */
    public function test_a_throwing_client_never_reaches_the_caller(): void
    {
        $http = $this->createMock(OutboundHttpClient::class);
        $http->method('post')->willThrowException(new \RuntimeException('DNS is down'));

        $this->pinger($http)->success(['pending' => 0]);
        $this->pinger($http)->fail(HeartbeatPinger::REASON_QUEUE_STALLED);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_rejected_ping_is_not_retried(): void
    {
        $http = new SpyHttpClient(accept: false);
        $this->pinger($http)->start();

        $this->assertCount(1, $http->calls, 'the next tick pings again; retrying here holds the drain open');
    }
}

/** Records the requests instead of making them. */
final class SpyHttpClient implements OutboundHttpClient
{
    /** @var list<array{url:string,body:string,timeout:int}> */
    public array $calls = [];

    public function __construct(private bool $accept = true) {}

    public function post(string $url, string $body = '', int $timeoutSeconds = 3): bool
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'timeout' => $timeoutSeconds];

        return $this->accept;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (array $call): string => $call['url'], $this->calls);
    }
}
