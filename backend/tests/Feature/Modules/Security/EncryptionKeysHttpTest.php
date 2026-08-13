<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Modules\Auth\Services\ChillerlanQrProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\Feature\HttpTestCase;

/**
 * The encryption-key management API through the real stack (ADR-0036):
 * session auth, CSRF, and — on every mutation — the step-up credential.
 */
class EncryptionKeysHttpTest extends HttpTestCase
{
    /** Seeded test credentials (db/seed.sql): password123 + TOTP secret under the dev key. */
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';
    private const TOTP_SECRET_PLAIN = 'JBSWY3DPEHPK3PXP';
    private const TOTP_SECRET_ENCRYPTED = 'AAAAAAAAAAAAAAAAAAAAAA==:HfdK5XMmHZlKgJl97MSpvKrDR62kRrN9FWvhGO62PQM=';

    private string $adminId;
    private string $adminEmail;
    private string $csrfToken;
    private array $createdKeyIds = [];
    private array $parkedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        // CI's phpunit database has migrations but no seed rows — provision an
        // admin of our own and the ACTIVE dev key the write paths require.
        $this->adminId = $this->uuid();
        $this->adminEmail = "keys-http-{$this->adminId}@example.test";
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_secret, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, 1, NOW(), NOW())'
        )->execute([$this->adminId, $this->adminEmail, self::PASSWORD_HASH, 'Keys Http', 'de', self::TOTP_SECRET_ENCRYPTED]);

        $this->db->prepare(
            "INSERT INTO encryption_keys (id, key_identifier, algorithm, public_key, fingerprint_sha256, status, created_at, activated_at, expires_at)
             VALUES ('99999991-9999-9999-9999-999999999991', 'dev-key-2026', 'SODIUM_CRYPTO_BOX_SEAL',
                     UNHEX('7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155'),
                     '82ebd93f662cb26a5293137a00fbb6d0c239579c8df5855df1d00bcd1e092717',
                     'active', NOW(), NOW(), NOW() + INTERVAL 365 DAY)
             ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = NOW() + INTERVAL 365 DAY"
        )->execute();

        // Remember which keys are operational so activation tests can restore
        // the world for whoever runs next.
        $this->parkedKeys = $this->db
            ->query("SELECT id, status FROM encryption_keys WHERE status IN ('active', 'retiring')")
            ->fetchAll();

        // An authenticated admin session, set directly — this suite tests the
        // key API, not the login flow, and the process shares $_SESSION with
        // the booted app.
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
        foreach ($this->parkedKeys as $row) {
            $this->db->prepare('UPDATE encryption_keys SET status = ? WHERE id = ?')
                ->execute([$row['status'], $row['id']]);
        }
        if ($this->createdKeyIds !== []) {
            $in = implode(',', array_fill(0, count($this->createdKeyIds), '?'));
            $this->db->prepare("DELETE FROM encryption_keys WHERE id IN ({$in})")->execute($this->createdKeyIds);
        }
        $this->db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$this->adminEmail]);
        $this->db->prepare('DELETE FROM audit_log WHERE admin_user_id = ?')->execute([$this->adminId]);
        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);

        parent::tearDown();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** @return array<string, string> a fresh, valid step-up credential */
    private function stepUp(): array
    {
        return [
            'current_password' => 'password123',
            'totp_code' => (new TwoFactorAuth(qrcodeprovider: new ChillerlanQrProvider(), issuer: 'test'))
                ->getCode(self::TOTP_SECRET_PLAIN),
        ];
    }

    private function csrf(): array
    {
        return ['X-CSRF-Token' => $this->csrfToken];
    }

    public function test_listing_requires_a_session(): void
    {
        $_SESSION = [];

        $response = $this->request('GET', '/api/admin/encryption-keys');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_listing_returns_key_metadata_with_lifecycle_state(): void
    {
        $response = $this->request('GET', '/api/admin/encryption-keys');

        $this->assertSame(200, $response->getStatusCode());
        $keys = $this->decode($response)['keys'];
        $byIdentifier = array_column($keys, null, 'key_identifier');

        $this->assertArrayHasKey('dev-key-2026', $byIdentifier);
        $dev = $byIdentifier['dev-key-2026'];
        $this->assertSame('active', $dev['status']);
        $this->assertSame('ok', $dev['lifecycle_state']);
        $this->assertGreaterThan(300, $dev['days_until_expiry']);
        $this->assertSame(base64_encode(hex2bin('7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155')), $dev['public_key']);
        // Metadata only — no private material could even exist here.
        $this->assertArrayNotHasKey('private_key', $dev);
    }

    public function test_registering_requires_the_step_up_credential(): void
    {
        $keypair = sodium_crypto_box_keypair();

        $response = $this->request('POST', '/api/admin/encryption-keys', [
            'public_key' => base64_encode(sodium_crypto_box_publickey($keypair)),
            'key_identifier' => 'no-step-up',
            'current_password' => 'wrong-password',
            'totp_code' => '000000',
        ], headers: $this->csrf());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    public function test_register_activate_revoke_lifecycle_over_http(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $publicB64 = base64_encode(sodium_crypto_box_publickey($keypair));

        // Register → PENDING
        $response = $this->request('POST', '/api/admin/encryption-keys', [
            'public_key' => $publicB64,
            'key_identifier' => 'http-test-' . substr($this->adminId, 0, 8),
        ] + $this->stepUp(), headers: $this->csrf());

        $this->assertSame(201, $response->getStatusCode());
        $key = $this->decode($response)['key'];
        $this->createdKeyIds[] = $key['id'];
        $this->assertSame('pending', $key['status']);

        // Activate → ACTIVE with a 365-day cryptoperiod; the dev key retires.
        $response = $this->request('POST', "/api/admin/encryption-keys/{$key['id']}/activate", $this->stepUp(), headers: $this->csrf());

        $this->assertSame(200, $response->getStatusCode());
        $activated = $this->decode($response)['key'];
        $this->assertSame('active', $activated['status']);
        $this->assertNotNull($activated['expires_at']);

        // Revoke as compromised → terminal state, immediately.
        $response = $this->request('POST', "/api/admin/encryption-keys/{$key['id']}/revoke", [
            'compromised' => true,
        ] + $this->stepUp(), headers: $this->csrf());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('compromised', $this->decode($response)['key']['status']);
    }

    public function test_registering_a_malformed_public_key_is_a_422(): void
    {
        $response = $this->request('POST', '/api/admin/encryption-keys', [
            'public_key' => 'not-a-key',
            'key_identifier' => 'malformed',
        ] + $this->stepUp(), headers: $this->csrf());

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_activating_an_unknown_key_is_a_404(): void
    {
        $response = $this->request(
            'POST',
            '/api/admin/encryption-keys/00000000-0000-0000-0000-000000000000/activate',
            $this->stepUp(),
            headers: $this->csrf(),
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_encrypt_existing_runs_a_batch_behind_step_up(): void
    {
        $response = $this->request('POST', '/api/admin/encryption-keys/encrypt-existing', $this->stepUp(), headers: $this->csrf());

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertArrayHasKey('processed', $body);
        $this->assertArrayHasKey('remaining', $body);
    }
}
