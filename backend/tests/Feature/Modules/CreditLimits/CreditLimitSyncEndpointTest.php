<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditLimits;

use App\Modules\Auth\Services\TokenService;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * `GET /api/sync/config` — how the club's ceiling reaches a terminal that has
 * to enforce it with nothing reachable (ADR-0047, #560).
 *
 * The endpoint exists because `/sync/members` is a delta on `updated_at` and a
 * club setting touches no member row: a pre-resolved per-member figure would
 * go stale on every terminal until each member changed for some unrelated
 * reason. So the club policy travels on its own channel and the terminal
 * coalesces locally.
 *
 * It sits inside the existing `/api/sync` group, which is what gives it
 * `TerminalTokenAuth` and the terminal rate limit without new wiring — and
 * what these tests check first, because an unauthenticated club-policy
 * endpoint would be exactly the accretion on `/health` this design refused.
 */
class CreditLimitSyncEndpointTest extends HttpTestCase
{
    private const PATH = '/api/sync/config';

    private string $terminalId = '';
    private string $token = '';

    /**
     * An address of this file's own.
     *
     * `TerminalTokenAuth` rate-limits failed authentication **by IP**, and two
     * tests here fail on purpose. Sharing `127.0.0.1` with every other suite
     * meant those refusals counted against the bucket the successful calls draw
     * from, so the file passed alone and 429'd inside a full run — E2E pattern
     * 002, one file's authentication state kept out of everyone else's.
     */
    private string $clientIp = '';

    /** @var array<string, mixed>|null */
    private ?array $originalConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConfig = $this->db->query('SELECT * FROM credit_limit_config WHERE id = 1')->fetch() ?: null;
        $this->clientIp = '10.60.' . random_int(0, 255) . '.' . random_int(1, 254);

        $this->terminalId = Uuid::v4();
        $this->token = 'sync-config-' . bin2hex(random_bytes(16));
        $this->db->prepare(
            // token_expires_at is not optional: a token with no expiry never
            // authenticates (#106), which is the same rule a real rotation
            // follows.
            'INSERT INTO terminals (id, name, device_id, api_token_hash, token_issued_at, token_expires_at, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 1, NOW(), NOW())'
        )->execute([
            $this->terminalId,
            'Sync Config Terminal',
            "sync-config-{$this->terminalId}",
            TokenService::hashToken($this->token),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->originalConfig !== null) {
            $this->db->prepare(
                'UPDATE credit_limit_config SET default_limit_cents = ?, warn_threshold_percent = ? WHERE id = 1'
            )->execute([
                $this->originalConfig['default_limit_cents'],
                $this->originalConfig['warn_threshold_percent'],
            ]);
        }

        $this->db->prepare('DELETE FROM terminal_auth_attempts WHERE ip_address = ?')->execute([$this->clientIp]);
        $this->db->prepare('DELETE FROM terminal_ip_sightings WHERE terminal_id = ?')->execute([$this->terminalId]);
        $this->db->prepare('DELETE FROM terminal_sync_cursors WHERE terminal_id = ?')->execute([$this->terminalId]);
        $this->db->prepare('DELETE FROM terminals WHERE id = ?')->execute([$this->terminalId]);

        parent::tearDown();
    }

    public function test_a_terminal_reads_the_clubs_policy(): void
    {
        $this->setConfig(15_000, 70);

        $response = $this->authenticated();
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(15_000, $body['credit_limit']['default_limit_cents']);
        $this->assertSame(70, $body['credit_limit']['warn_threshold_percent']);
    }

    /**
     * The derived figure travels too, so the terminal never has to reproduce
     * the rounding rule: 70 % of 15,000 is 10,500 by integer division on both
     * sides, and a band boundary the two sides compute differently is a member
     * warned by one and not the other.
     */
    public function test_the_band_boundary_is_computed_for_the_terminal(): void
    {
        $this->setConfig(3_333, 70);

        $this->assertSame(2_333, $this->decode($this->authenticated())['credit_limit']['warn_at_cents']);
    }

    public function test_a_change_to_the_club_setting_is_visible_on_the_next_call(): void
    {
        $this->setConfig(10_000, 80);
        $this->assertSame(10_000, $this->decode($this->authenticated())['credit_limit']['default_limit_cents']);

        $this->setConfig(25_000, 50);

        $body = $this->decode($this->authenticated())['credit_limit'];
        $this->assertSame(25_000, $body['default_limit_cents']);
        $this->assertSame(12_500, $body['warn_at_cents']);
    }

    public function test_a_club_that_caps_nobody_says_so_rather_than_going_quiet(): void
    {
        $this->setConfig(0, 80);

        $body = $this->decode($this->authenticated())['credit_limit'];

        $this->assertSame(0, $body['default_limit_cents']);
        $this->assertSame(0, $body['warn_at_cents'], 'an unenforced ceiling has no band below it');
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->assertSame(401, $this->get()->getStatusCode());
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->assertSame(401, $this->get('Bearer not-a-real-token')->getStatusCode());
    }

    /**
     * The boundary this endpoint exists to hold: `/health` carries only what a
     * terminal needs *before* it can authenticate, and club policy is not that
     * (ADR-0047 decision 3).
     */
    public function test_the_health_check_gains_no_club_policy(): void
    {
        $health = $this->decode($this->request('GET', '/api/health', [], ['REMOTE_ADDR' => $this->clientIp]));

        $this->assertArrayNotHasKey('credit_limit', $health);
        foreach (array_keys($health) as $key) {
            $this->assertStringNotContainsString('limit', (string) $key);
        }
    }

    private function authenticated(): \Psr\Http\Message\ResponseInterface
    {
        return $this->get("Bearer {$this->token}");
    }

    private function get(?string $authorization = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'GET',
            self::PATH,
            [],
            ['REMOTE_ADDR' => $this->clientIp],
            $authorization === null ? [] : ['Authorization' => $authorization],
        );
    }

    private function setConfig(int $limitCents, int $warnPercent): void
    {
        $this->db->prepare(
            'UPDATE credit_limit_config SET default_limit_cents = ?, warn_threshold_percent = ? WHERE id = 1'
        )->execute([$limitCents, $warnPercent]);
    }
}
