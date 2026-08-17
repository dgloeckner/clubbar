<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Shared\Mail\MailLayout;
use Tests\Feature\HttpTestCase;

/**
 * The mail settings API through the real stack (ADR-0038): routes, session
 * auth, validation, the singleton repository and the audit entry.
 *
 * `mail_config` is one row, so this suite snapshots it in setUp and restores it
 * in tearDown rather than creating its own — the singleton equivalent of
 * Pattern 001's isolation rule.
 */
class MailConfigHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    private string $adminId;
    private array $originalConfig = [];
    private string $csrfToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->uuid();
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$this->adminId, "mail-config-{$this->adminId}@example.test", self::PASSWORD_HASH, 'Mail Config', 'de']);
        $this->grantRoles($this->adminId);

        $this->originalConfig = $this->db->query('SELECT * FROM mail_config WHERE id = 1')->fetch() ?: [];

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $this->adminId;
        \App\Modules\Auth\Domain\SessionTimeout::begin($_SESSION);
        $this->csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $this->csrfToken;
    }

    protected function tearDown(): void
    {
        if ($this->originalConfig !== []) {
            $this->db->prepare(
                'UPDATE mail_config SET sender_name = ?, sender_address = ?, reply_to_address = ?, header_style = ?,
                        footer_org_name = ?, footer_address_line = ?, website_url = ?, logo_url = ?,
                        cron_interval = ?, drain_batch_size = ?, drain_budget_seconds = ?,
                        cron_secret_hash = ?, cron_secret_rotated_at = ?,
                        updated_by_admin_id = NULL
                 WHERE id = 1'
            )->execute([
                $this->originalConfig['sender_name'],
                $this->originalConfig['sender_address'],
                $this->originalConfig['reply_to_address'],
                $this->originalConfig['header_style'],
                $this->originalConfig['footer_org_name'],
                $this->originalConfig['footer_address_line'],
                $this->originalConfig['website_url'],
                $this->originalConfig['logo_url'],
                $this->originalConfig['cron_interval'],
                $this->originalConfig['drain_batch_size'],
                $this->originalConfig['drain_budget_seconds'],
                $this->originalConfig['cron_secret_hash'],
                $this->originalConfig['cron_secret_rotated_at'],
            ]);
        }

        $this->db->prepare('DELETE FROM audit_log WHERE admin_user_id = ?')->execute([$this->adminId]);
        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);

        parent::tearDown();
    }

    public function test_get_returns_the_club_fields_and_the_transport_state(): void
    {
        $response = $this->request('GET', '/api/admin/mail-config');
        $this->assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertArrayHasKey('sender_address', $body);
        $this->assertArrayHasKey('header_style', $body);
        $this->assertArrayHasKey('is_complete', $body);
        $this->assertArrayHasKey('can_send', $body);
        $this->assertArrayHasKey('transport', $body);
        $this->assertIsBool($body['transport']['configured']);
    }

    public function test_get_never_exposes_the_dsn(): void
    {
        $response = $this->request('GET', '/api/admin/mail-config');
        $response->getBody()->rewind();
        $raw = (string) $response->getBody();

        // Naming the setting is fine — "mail.dsn unset" is how the status tells
        // an admin what to configure. A DSN *value* must never appear.
        $this->assertDoesNotMatchRegularExpression('#(smtps?|sendmail|native)://#', $raw);
        $this->assertArrayNotHasKey('dsn', $this->decode($response));
    }

    public function test_an_unconfigured_install_reports_itself_as_such(): void
    {
        $this->db->exec("UPDATE mail_config SET sender_address = '' WHERE id = 1");

        $body = $this->decode($this->request('GET', '/api/admin/mail-config'));

        // A fresh install must be *reportably* unconfigured rather than
        // sending from an address nobody owns.
        $this->assertFalse($body['is_complete']);
        $this->assertFalse($body['can_send']);
    }

    public function test_the_club_name_fills_in_for_an_unset_footer_and_sender_name(): void
    {
        $this->db->exec("UPDATE mail_config SET sender_name = '', footer_org_name = '' WHERE id = 1");

        $body = $this->decode($this->request('GET', '/api/admin/mail-config'));

        // Configured once, in instance branding (ADR-0034) — a fresh install
        // should not have to say the same thing twice.
        $instanceName = (string) $this->db->query('SELECT instance_name FROM instance_config WHERE id = 1')->fetchColumn();
        $this->assertSame($instanceName, $body['footer_org_name']);
        $this->assertSame($instanceName, $body['sender_name']);
    }

    public function test_patch_persists_and_audits(): void
    {
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'sender_name' => 'Testverein Bar',
            'sender_address' => 'bar@test.example.org',
            'reply_to_address' => 'kassenwart@test.example.org',
            'header_style' => MailLayout::HEADER_PETROL,
            'footer_org_name' => 'Testverein e. V.',
            'footer_address_line' => 'Musterweg 35',
            'website_url' => 'https://www.test.example.org',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('bar@test.example.org', $body['sender_address']);
        $this->assertTrue($body['is_complete']);

        $row = $this->db->query('SELECT * FROM mail_config WHERE id = 1')->fetch();
        $this->assertSame('Testverein Bar', $row['sender_name']);
        $this->assertSame(MailLayout::HEADER_PETROL, $row['header_style']);
        $this->assertSame($this->adminId, $row['updated_by_admin_id']);

        $audit = $this->db->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE admin_user_id = ? AND entity_type = 'mail_config'"
        );
        $audit->execute([$this->adminId]);
        $this->assertSame(1, (int) $audit->fetchColumn());
    }

    public function test_patch_ignores_columns_it_does_not_own(): void
    {
        // SafeQuery's whitelist is the guard; this pins that the route cannot
        // be talked into writing an arbitrary column.
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'sender_address' => 'bar@test.example.org',
            'id' => 99,
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, (int) $this->db->query('SELECT id FROM mail_config')->fetchColumn());
    }

    public function test_patch_rejects_a_malformed_sender_address(): void
    {
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'sender_address' => 'not-an-address',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('sender_address', $this->decode($response)['messages']);
    }

    public function test_patch_rejects_an_unknown_header_variant(): void
    {
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'header_style' => 'neon',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('header_style', $this->decode($response)['messages']);
    }

    /* ──────────────────── The scheduler's declared interval ──────────────────── */

    public function test_patch_stores_the_declared_cron_interval_and_batch_size(): void
    {
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'cron_interval' => 'daily',
            'drain_batch_size' => 40,
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('daily', $body['cron_interval']);
        $this->assertSame(40, $body['drain_batch_size']);

        $row = $this->db->query('SELECT * FROM mail_config WHERE id = 1')->fetch();
        $this->assertSame('daily', $row['cron_interval']);
        $this->assertSame(40, (int) $row['drain_batch_size']);
    }

    /**
     * The one rejected value somebody will deliberately try, because it is what
     * their tariff offers. A generic "must be one of" would read as an
     * arbitrary limitation of this application rather than as what it is: at a
     * weekly drain the club's own announcement promise stops holding, and the
     * remedy is a different tariff or an external scheduler (ADR-0039
     * decision 5).
     */
    public function test_patch_refuses_a_weekly_scheduler_with_its_reason(): void
    {
        $before = $this->db->query('SELECT cron_interval FROM mail_config WHERE id = 1')->fetchColumn();

        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'cron_interval' => 'weekly',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(422, $response->getStatusCode());
        $message = $this->decode($response)['messages']['cron_interval'];
        $this->assertStringContainsString('§ 7 Abs. 3', $message);
        $this->assertStringContainsString('seven-day', $message);

        $this->assertSame(
            $before,
            $this->db->query('SELECT cron_interval FROM mail_config WHERE id = 1')->fetchColumn(),
            'a refused value must not have been written'
        );
    }

    public function test_patch_rejects_a_batch_size_outside_its_bounds(): void
    {
        foreach ([0, 5000] as $size) {
            $response = $this->request('PATCH', '/api/admin/mail-config', [
                'drain_batch_size' => $size,
            ], headers: ['X-CSRF-Token' => $this->csrfToken]);

            $this->assertSame(422, $response->getStatusCode(), "batch size {$size} should have been refused");
            $this->assertArrayHasKey('drain_batch_size', $this->decode($response)['messages']);
        }
    }

    /**
     * `max:` measures a string's length rather than its value, so a numeric
     * string would otherwise walk straight past the ceiling.
     */
    public function test_a_batch_size_sent_as_a_string_is_still_bounded(): void
    {
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'drain_batch_size' => '5000',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * The cadence `bin/cron.php` has recommended since #403, declarable at last
     * (#473). Until now such an installation had to say `hourly` — safe, since
     * every threshold only became conservative, but a declaration four times
     * slower than the machine it describes.
     */
    public function test_patch_accepts_the_fifteen_minute_cadence(): void
    {
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'cron_interval' => 'fifteen_minutes',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fifteen_minutes', $this->decode($response)['cron_interval']);

        $this->assertSame(
            'fifteen_minutes',
            $this->db->query('SELECT cron_interval FROM mail_config WHERE id = 1')->fetchColumn()
        );
    }

    public function test_patch_stores_the_run_budget(): void
    {
        $response = $this->request('PATCH', '/api/admin/mail-config', [
            'drain_budget_seconds' => 20,
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(20, $this->decode($response)['drain_budget_seconds']);
        $this->assertSame(
            20,
            (int) $this->db->query('SELECT drain_budget_seconds FROM mail_config WHERE id = 1')->fetchColumn()
        );
    }

    /**
     * Bounded exactly the way the batch size beside it is, and for a sharper
     * reason: a budget above the trigger's own timeout is a run that gets
     * *killed* rather than one that stops, and a killed run's rows come back
     * after the stale window to be sent a second time.
     */
    public function test_patch_rejects_a_run_budget_outside_its_bounds(): void
    {
        foreach ([0, 5, 600, '600'] as $seconds) {
            $response = $this->request('PATCH', '/api/admin/mail-config', [
                'drain_budget_seconds' => $seconds,
            ], headers: ['X-CSRF-Token' => $this->csrfToken]);

            $this->assertSame(422, $response->getStatusCode(), "budget {$seconds} should have been refused");
            $this->assertArrayHasKey('drain_budget_seconds', $this->decode($response)['messages']);
        }
    }

    public function test_requires_a_session(): void
    {
        $_SESSION = [];

        $this->assertSame(401, $this->request('GET', '/api/admin/mail-config')->getStatusCode());
    }

    /* ──────────────────── Rotating the URL-trigger secret (#473) ──────────────────── */

    public function test_rotate_mints_a_secret_shown_once_and_never_stores_it_in_the_clear(): void
    {
        $response = $this->request('POST', '/api/admin/mail-config/cron-secret/rotate', [
            'current_password' => 'password123',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['cron_secret']);
        $this->assertTrue($body['cron_secret_configured']);
        $this->assertNotNull($body['cron_secret_rotated_at']);

        $row = $this->db->query('SELECT * FROM mail_config WHERE id = 1')->fetch();
        $this->assertNotSame($body['cron_secret'], $row['cron_secret_hash'], 'only a hash may reach the column');
        $this->assertSame(hash('sha256', $body['cron_secret']), $row['cron_secret_hash']);
        $this->assertNotNull($row['cron_secret_rotated_at']);
    }

    public function test_rotate_is_audited_without_any_secret_material(): void
    {
        $response = $this->request('POST', '/api/admin/mail-config/cron-secret/rotate', [
            'current_password' => 'password123',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);
        $secret = $this->decode($response)['cron_secret'];

        $entry = $this->db->prepare(
            "SELECT * FROM audit_log WHERE admin_user_id = ? AND action = 'cron_secret_rotated'"
        );
        $entry->execute([$this->adminId]);
        $row = $entry->fetch();

        $this->assertNotFalse($row, 'the rotation must be audited');
        $this->assertStringNotContainsString($secret, (string) $row['old_values']);
        $this->assertStringNotContainsString($secret, (string) $row['new_values']);
    }

    public function test_rotate_refuses_a_wrong_password_and_writes_nothing(): void
    {
        $before = $this->db->query('SELECT cron_secret_hash FROM mail_config WHERE id = 1')->fetchColumn();

        $response = $this->request('POST', '/api/admin/mail-config/cron-secret/rotate', [
            'current_password' => 'wrong-password',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            $before,
            $this->db->query('SELECT cron_secret_hash FROM mail_config WHERE id = 1')->fetchColumn(),
        );
    }

    public function test_rotate_requires_a_password_in_the_body(): void
    {
        $response = $this->request(
            'POST',
            '/api/admin/mail-config/cron-secret/rotate',
            [],
            headers: ['X-CSRF-Token' => $this->csrfToken],
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * A rotated secret supersedes `config.php`'s rather than living beside it —
     * the same "one live credential" rule terminal token rotation follows.
     */
    public function test_rotate_makes_the_url_trigger_accept_only_the_new_secret(): void
    {
        $secret = $this->decode($this->request('POST', '/api/admin/mail-config/cron-secret/rotate', [
            'current_password' => 'password123',
        ], headers: ['X-CSRF-Token' => $this->csrfToken]))['cron_secret'];

        $this->assertSame(
            204,
            $this->request('POST', '/api/cron/drain', headers: [\App\Modules\Notifications\Controllers\CronController::HEADER => $secret])->getStatusCode(),
        );
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
