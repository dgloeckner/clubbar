<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use App\Shared\Database\ConnectionFactory;
use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Utils\Uuid;

/**
 * Base test case for integration tests that need database access.
 *
 * Sets up PDO connection to the test database running in Docker.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;
    protected Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Connect to test database (Docker container)
        $host = getenv('DB_HOST') ?: 'database';
        $dbname = getenv('DB_NAME') ?: 'clubbar';
        $user = getenv('DB_USER') ?: 'clubbar';
        $password = getenv('DB_PASS') ?: 'clubbar';

        // The same factory bootstrap.php uses, so tests inherit the production
        // connection settings — native prepares and a UTC session time zone —
        // instead of a copy of them that can drift.
        $this->db = ConnectionFactory::create($host, $dbname, $user, $password);

        // Create mock logger (logs nothing during tests)
        $this->logger = $this->createMock(Logger::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Generate a valid UUID v4
     */
    protected function generateUuid(): string
    {
        return Uuid::v4();
    }

    /**
     * The published development keypair (ADR-0036).
     *
     * `ensureActiveEncryptionKey()` registers the public half; the private half
     * is here because since migration 020 there is no plaintext IBAN column,
     * so a fixture that needs a readable IBAN has to seal it and a test that
     * exports one has to open it. `IbanSealedBox` refuses this keypair outside
     * development environments, so it can never seal production data.
     */
    protected const DEV_PUBLIC_KEY_HEX = '7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155';
    protected const DEV_SECRET_KEY_HEX = 'f678fb17b592c29db54e43f808ee74fd67f7dd5c6c405b24e3e31ead38f3058a';
    protected const DEV_KEY_ID = '99999991-9999-9999-9999-999999999991';

    /** Seal an IBAN under the dev key, the way a real write does. */
    protected function sealIban(string $iban): string
    {
        return (new \App\Shared\Security\IbanSealedBox(str_repeat('0', 63) . '2', 'test'))
            ->seal($iban, hex2bin(self::DEV_PUBLIC_KEY_HEX));
    }

    /** The opener a SEPA export is handed, standing in for the club's key. */
    protected function devIbanOpener(): \Closure
    {
        $box = new \App\Shared\Security\IbanSealedBox(str_repeat('0', 63) . '2', 'test');
        $secret = hex2bin(self::DEV_SECRET_KEY_HEX);

        return static fn(string $ciphertext): string => $box->open($ciphertext, $secret);
    }

    /** The keyed fingerprint a real write stores alongside the ciphertext. */
    protected function ibanFingerprint(string $iban): string
    {
        return (new \App\Shared\Security\IbanSealedBox(str_repeat('0', 63) . '2', 'test'))->fingerprint($iban);
    }

    /**
     * Make sure an ACTIVE IBAN encryption key exists (ADR-0036).
     *
     * Storing an IBAN seals it under the ACTIVE key, so every test that
     * creates banking data needs one. The dev/e2e seeds provide it, but CI's
     * phpunit job applies only the migrations — this inserts the same
     * published dev keypair's public half idempotently.
     */
    protected function ensureActiveEncryptionKey(): void
    {
        $active = $this->db
            ->query("SELECT COUNT(*) FROM encryption_keys WHERE status = 'active'")
            ->fetchColumn();

        if ((int) $active > 0) {
            return;
        }

        $this->db->prepare(
            "INSERT INTO encryption_keys (id, key_identifier, algorithm, public_key, fingerprint_sha256, status, created_at, activated_at, expires_at)
             VALUES ('99999991-9999-9999-9999-999999999991', 'dev-key-2026', 'SODIUM_CRYPTO_BOX_SEAL',
                     UNHEX('7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155'),
                     '82ebd93f662cb26a5293137a00fbb6d0c239579c8df5855df1d00bcd1e092717',
                     'active', NOW(), NOW(), NOW() + INTERVAL 365 DAY)
             ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = NOW() + INTERVAL 365 DAY"
        )->execute();
    }

    /**
     * Make sure a drain run has been observed (#405).
     *
     * Finalizing a direct-debit settlement is refused while `cron_heartbeat`
     * has never been stamped, so every test that creates one needs this. The
     * dev/e2e seed provides it, but CI's phpunit job applies only the
     * migrations — and migration 025 seeds the row with `last_run_at` NULL,
     * which is the honest initial state and exactly the state that blocks.
     *
     * Idempotent, and deliberately non-destructive: an existing run — from the
     * seed, or from the drain's own tests — is left alone.
     *
     * @return App\Modules\Notifications\Services\SchedulerStatusService The gate the service under test should hold.
     */
    protected function ensureObservedSchedulerRun(): \App\Modules\Notifications\Services\SchedulerStatusService
    {
        $this->db->exec(
            "INSERT INTO cron_heartbeat (id, last_run_at, source, sent, failed, php_version, missing_extensions)
             VALUES (1, NOW(), 'cli', 0, 0, 'phpunit', '')
             ON DUPLICATE KEY UPDATE last_run_at = COALESCE(last_run_at, NOW()), source = COALESCE(source, 'cli')"
        );

        return $this->schedulerStatusService();
    }

    /** The scheduler gate over this connection, reading whatever the heartbeat says. */
    protected function schedulerStatusService(): \App\Modules\Notifications\Services\SchedulerStatusService
    {
        return new \App\Modules\Notifications\Services\SchedulerStatusService(
            new \App\Modules\Notifications\Repositories\CronHeartbeatRepository($this->db),
            new \App\Shared\Config\AppConfig(),
        );
    }

    /**
     * Clean up test data by deleting records with specific IDs
     */
    protected function cleanupTestData(string $table, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
    }
}
