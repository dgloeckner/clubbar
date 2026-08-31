<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Repositories;

use App\Modules\Registrations\Repositories\RegistrationsRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * An in-memory SQLite database exercises the real queries without the Docker
 * MariaDB instance. The schema below is a hand-maintained copy of migration
 * `059`: a column this repository reads or writes has to be reflected here too.
 */
final class RegistrationsRepositoryTest extends TestCase
{
    private PDO $db;
    private RegistrationsRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE pending_registrations (
                id CHAR(36) NOT NULL PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(20) NULL,
                date_of_birth DATE NOT NULL,
                preferred_language VARCHAR(10) NOT NULL,
                account_holder_name VARCHAR(70) NULL,
                mandate_reference VARCHAR(35) NOT NULL UNIQUE,
                iban_ciphertext BLOB NOT NULL,
                iban_last4 CHAR(4) NOT NULL,
                iban_fingerprint CHAR(64) NOT NULL,
                encryption_key_id CHAR(36) NOT NULL,
                bank_name VARCHAR(255) NULL,
                privacy_notice_url VARCHAR(500) NOT NULL,
                privacy_notice_shown_at DATETIME NOT NULL,
                submitted_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL
            )'
        );

        $this->repository = new RegistrationsRepository($this->db);
    }

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): string
    {
        return $this->repository->create($overrides + [
            'first_name' => 'Lena',
            'last_name' => 'Brandt',
            'email' => 'lena@example.org',
            'phone' => null,
            'date_of_birth' => '2010-04-02',
            'preferred_language' => 'de',
            'account_holder_name' => null,
            'mandate_reference' => bin2hex(random_bytes(8)),
            'iban_ciphertext' => 'v1:c2VhbGVk',
            'iban_last4' => '3000',
            'iban_fingerprint' => str_repeat('a', 64),
            'encryption_key_id' => '11111111-1111-4111-8111-111111111111',
            'bank_name' => 'Sparkasse',
            'privacy_notice_url' => 'https://club.example/Anmeldung.pdf',
            'privacy_notice_shown_at' => '2026-08-31 10:00:00',
            'submitted_at' => '2026-08-31 10:00:00',
            'expires_at' => '2026-09-30 10:00:00',
        ]);
    }

    public function test_create_returns_an_id_and_stores_the_row(): void
    {
        $id = $this->insert();

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);

        $row = $this->db->query('SELECT * FROM pending_registrations')->fetch();
        self::assertSame($id, $row['id']);
        self::assertSame('Lena', $row['first_name']);
        self::assertSame('3000', $row['iban_last4']);
    }

    /**
     * The guarantee the whole store rests on: what is written is the sealed
     * box, and nothing anywhere in the row is a readable IBAN.
     */
    public function test_no_column_holds_a_plaintext_iban(): void
    {
        $this->insert();

        $row = $this->db->query('SELECT * FROM pending_registrations')->fetch();

        self::assertArrayNotHasKey('iban', $row);
        foreach ($row as $column => $value) {
            self::assertStringNotContainsString(
                'DE89370400440532013000',
                (string) $value,
                "Column {$column} holds a readable IBAN",
            );
        }
    }

    /**
     * Two people may submit the same details; nothing here refuses a duplicate,
     * because refusing one would answer a question the public endpoint must not
     * answer (ADR-0052 decision 9).
     */
    public function test_a_duplicate_email_is_accepted(): void
    {
        $this->insert();
        $this->insert();

        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM pending_registrations')->fetchColumn());
    }

    public function test_purge_removes_only_rows_past_their_expiry(): void
    {
        $stale = $this->insert(['expires_at' => '2026-08-30 09:00:00']);
        $fresh = $this->insert(['expires_at' => '2026-09-30 09:00:00']);

        $purged = $this->repository->purgeExpired('2026-08-31 10:00:00');

        self::assertSame(1, $purged);
        $remaining = $this->db->query('SELECT id FROM pending_registrations')->fetchAll();
        self::assertCount(1, $remaining);
        self::assertSame($fresh, $remaining[0]['id']);
        self::assertNotSame($stale, $remaining[0]['id']);
    }

    public function test_purge_returns_zero_when_nothing_has_expired(): void
    {
        $this->insert();

        self::assertSame(0, $this->repository->purgeExpired('2026-08-31 10:00:00'));
    }

    /**
     * The review inbox flags a returning applicant, and it does so without a
     * key: a sealed box is randomized, so its ciphertext is not comparable, but
     * the keyed fingerprint is exactly the comparison ADR-0036 built for.
     */
    public function test_duplicates_are_findable_by_email_and_by_fingerprint(): void
    {
        $this->insert(['email' => 'petra@example.org', 'iban_fingerprint' => str_repeat('b', 64)]);

        self::assertSame(1, $this->repository->countByEmail('petra@example.org'));
        self::assertSame(0, $this->repository->countByEmail('nobody@example.org'));
        self::assertSame(1, $this->repository->countByFingerprint(str_repeat('b', 64)));
        self::assertSame(0, $this->repository->countByFingerprint(str_repeat('c', 64)));
    }
}
