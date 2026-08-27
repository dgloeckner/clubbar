<?php

declare(strict_types=1);

namespace App\Shared\Monitoring;

use App\Shared\Http\OutboundHttpClient;
use App\Shared\Logging\Logger;

/**
 * The alarm channel, and its defining property: **it must not depend on what it
 * monitors** (ADR-0038 rule 6, #406).
 *
 * An application that reported "SMTP is dead" over its own SMTP would never
 * deliver that report. So the drain pings an external push monitor instead —
 * healthchecks.io is the reference, Uptime Kuma, Cronitor and Better Stack take
 * the same shape, and the code names none of them: clubs self-host, and a
 * vendor in the source would be a vendor in every installation.
 *
 * ### Why liveness alone is not enough
 *
 * The naive contract — *the script ran, so ping* — is worthless here. A cron
 * that starts reliably every hour and fails at the SMTP handshake every time
 * would hold the check green for ever while nothing is announced. What is
 * pinged is therefore the *outcome*:
 *
 * | Event | Path | Means |
 * |---|---|---|
 * | Run starts | `/start` | Catches a hung run — which matters because `flock` blocks every following run behind it |
 * | Run finished, transport usable | *(base URL)* | The delivery chain is intact; counts travel in the body |
 * | Transport unusable, or the run aborted | `/fail` | The real alarm |
 * | A due message ignored for three ticks | `/fail` | The case a pure liveness check cannot see: cron alive, queue not moving |
 *
 * A single hard bounce is **not** a `/fail`. It stays a `failed` row for the
 * Kassenwart, because a switch that cries wolf at every typo'd address is a
 * switch somebody turns off within weeks — and then the real outage is silent.
 *
 * ### No personal data leaves through here
 *
 * The ping body carries counts and a fixed reason vocabulary. Never an address,
 * never a name, and in particular **never the transport's error text**, which
 * routinely quotes the rejected recipient back at you (`550 5.1.1
 * <someone@example.org> unknown`). This is not tidiness: keeping the ping a
 * pure availability signal is what keeps a third-party monitor out of Art. 28
 * processing territory, and the next debugging session is exactly the moment
 * somebody would otherwise paste a failing address in to "see which one it is".
 * The reason is looked up from {@see REASONS} rather than passed through.
 *
 * Every method swallows everything. A monitor that can kill the job it watches
 * is a net loss, and this one runs at both ends of the only sending path.
 */
final class HeartbeatPinger
{
    /** Seconds a ping may take. Short: nothing waits on it, and the drain does. */
    public const TIMEOUT_SECONDS = 3;

    /**
     * The complete vocabulary of failure reasons that may leave this host.
     *
     * A closed set on purpose — see the class docblock. Adding a reason here is
     * a deliberate act; interpolating one is not possible.
     */
    public const REASONS = [
        self::REASON_TRANSPORT_UNAVAILABLE => 'no usable mail transport is configured',
        self::REASON_QUEUE_STALLED => 'a message has been due for three scheduler ticks and nothing took it',
        self::REASON_RUN_ABORTED => 'the drain run ended abnormally',
    ];

    public const REASON_TRANSPORT_UNAVAILABLE = 'transport_unavailable';
    public const REASON_QUEUE_STALLED = 'queue_stalled';
    public const REASON_RUN_ABORTED = 'run_aborted';

    /**
     * @param string|null $checkUrl The monitor's check URL; null switches the alarm off
     */
    public function __construct(
        private OutboundHttpClient $http,
        private Logger $logger,
        private ?string $checkUrl = null,
    ) {}

    /** Is anything listening? Cheap enough to ask, and it keeps callers honest. */
    public function isConfigured(): bool
    {
        return $this->checkUrl !== null && trim($this->checkUrl) !== '';
    }

    /**
     * A run has begun.
     *
     * Sent before any work, so a run that hangs shows up as a start with no
     * finish. Without it a hung drain looks identical to a cron that never
     * fired — and the two have opposite remedies.
     */
    public function start(): void
    {
        $this->ping('/start');
    }

    /**
     * The run finished and the delivery chain works.
     *
     * The counts ride along in the body, which every push monitor stores and
     * shows next to the ping. A separate `/log` request would carry the same
     * numbers at twice the cost, on a path some providers do not implement.
     *
     * @param array<string,int> $counts Message counts — never anything else
     */
    public function success(array $counts): void
    {
        $this->ping('', self::body(['result' => 'ok'] + self::intsOnly($counts)));
    }

    /**
     * The alarm.
     *
     * @param string             $reason A key of {@see REASONS}; anything else is reported as unspecified
     * @param array<string,int>  $counts Message counts — never anything else
     */
    public function fail(string $reason, array $counts = []): void
    {
        // A reason outside the vocabulary is replaced, not passed through. It
        // is the obvious place for an SMTP rejection to end up — and those
        // quote the recipient address back at you.
        $known = isset(self::REASONS[$reason]);

        $this->ping('/fail', self::body([
            'result' => 'fail',
            'reason' => $known ? $reason : 'unspecified',
            'detail' => $known ? self::REASONS[$reason] : 'unspecified',
        ] + self::intsOnly($counts)));
    }

    /**
     * Fire one request and forget it.
     *
     * No retry, by decision: the next tick pings again in an hour, and a
     * retrying monitor client is a monitor that can hold the drain open.
     */
    private function ping(string $suffix, string $body = ''): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $url = rtrim((string) $this->checkUrl, '/') . $suffix;

        try {
            if (!$this->http->post($url, $body, self::TIMEOUT_SECONDS)) {
                // Logged at info, not warning: a monitor that is briefly
                // unreachable is not an application problem, and this line
                // would otherwise appear in every self-check's error count.
                $this->logger->info('Heartbeat ping was not accepted', ['path' => $suffix ?: '/']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Heartbeat ping failed', [
                'path' => $suffix ?: '/',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,int|string> $fields
     */
    private static function body(array $fields): string
    {
        $lines = [];
        foreach ($fields as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The second line of defence for the no-personal-data rule: whatever a
     * caller passes as counts, only integers survive.
     *
     * @param array<string,mixed> $counts
     * @return array<string,int>
     */
    private static function intsOnly(array $counts): array
    {
        $clean = [];
        foreach ($counts as $key => $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $clean[preg_replace('/[^a-z_]/', '', strtolower((string) $key)) ?: 'count'] = (int) $value;
            }
        }

        return $clean;
    }
}
