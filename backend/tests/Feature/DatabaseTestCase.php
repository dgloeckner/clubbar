<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
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

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $this->db = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Match production bootstrap.php: emulated prepares quote bound LIMIT/OFFSET
            // integers as strings, which MariaDB rejects. Native prepares avoid this.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

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
