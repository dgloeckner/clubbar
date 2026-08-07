<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use PDO;
use App\Shared\Logging\Logger;

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
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
