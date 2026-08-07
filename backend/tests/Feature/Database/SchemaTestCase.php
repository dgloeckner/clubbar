<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use PDOException;
use Tests\Feature\DatabaseTestCase;

/**
 * Base class for tests that assert the *database* enforces a rule, rather than
 * a service doing so.
 *
 * The consolidated remediation migration (#151) deliberately pushes several
 * money invariants down into the schema — "a transaction is stornoed at most
 * once", "a member has at most one active mandate", "two live settlements
 * cannot claim the same transaction". Those are only worth asserting at the
 * seam that enforces them, so these tests talk SQL directly.
 */
abstract class SchemaTestCase extends DatabaseTestCase
{
    /** @var array<string, string[]> table => ids, torn down in insertion-reverse order */
    private array $created = [];

    /** Tables cleaned up in this order, which is the reverse of FK dependency. */
    private const CLEANUP_ORDER = [
        'settlement_reversals',
        'settlement_items',
        'settlements',
        'transactions',
        'mandates',
        'products',
        'categories',
        'members',
        'admin_users',
    ];

    protected function tearDown(): void
    {
        foreach (self::CLEANUP_ORDER as $table) {
            if (!empty($this->created[$table])) {
                $this->cleanupTestData($table, $this->created[$table]);
            }
        }
        $this->created = [];
        parent::tearDown();
    }

    protected function track(string $table, string $id): string
    {
        $this->created[$table][] = $id;
        return $id;
    }

    /**
     * Assert that running $write is refused by the database.
     *
     * Deliberately asserts on the driver rejecting the statement rather than on
     * a specific SQLSTATE: a UNIQUE index, a CHECK constraint and a NOT NULL
     * column all express "the schema forbids this", and which one the migration
     * reaches for is an implementation choice the test should not pin.
     */
    protected function assertDatabaseRefuses(callable $write, string $message): void
    {
        try {
            $write();
        } catch (PDOException) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->fail($message);
    }

    protected function createMember(string $lastName = 'Schema'): string
    {
        $id = $this->track('members', $this->generateUuid());
        $stmt = $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$id, 'Test', $lastName, $id . '@example.com', 'de']);

        return $id;
    }

    protected function createAdminUser(): string
    {
        $id = $this->track('admin_users', $this->generateUuid());
        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$id, $id . '@example.com', password_hash('test123', PASSWORD_BCRYPT)]);

        return $id;
    }

    protected function createCategory(): string
    {
        $id = $this->track('categories', $this->generateUuid());
        $stmt = $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, 1)');
        $stmt->execute([$id, json_encode(['de' => 'Getränke', 'en' => 'Drinks'])]);

        return $id;
    }

    protected function createProduct(string $categoryId, int $priceCents = 350): string
    {
        $id = $this->track('products', $this->generateUuid());
        $stmt = $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, is_active) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$id, $categoryId, json_encode(['de' => 'Bier', 'en' => 'Beer']), $priceCents]);

        return $id;
    }
}
