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

    public function test_find_by_id_returns_the_row_and_null_for_a_stranger(): void
    {
        $id = $this->insert(['email' => 'petra@example.org']);

        $row = $this->repository->findById($id);

        self::assertNotNull($row);
        self::assertSame('petra@example.org', $row['email']);
        self::assertNull($this->repository->findById('11111111-2222-4333-8444-555555555555'));
    }

    public function test_the_list_is_newest_first_and_carries_a_total(): void
    {
        $older = $this->insert(['submitted_at' => '2026-08-20 09:00:00', 'last_name' => 'Aal']);
        $newer = $this->insert(['submitted_at' => '2026-08-29 09:00:00', 'last_name' => 'Zander']);

        $result = $this->repository->listPaginated(10, 0);

        self::assertSame(2, $result['total']);
        self::assertSame([$newer, $older], array_column($result['items'], 'id'));
    }

    public function test_the_list_paginates_without_losing_the_total(): void
    {
        $this->insert(['submitted_at' => '2026-08-20 09:00:00']);
        $this->insert(['submitted_at' => '2026-08-21 09:00:00']);
        $this->insert(['submitted_at' => '2026-08-22 09:00:00']);

        $result = $this->repository->listPaginated(2, 2);

        self::assertSame(3, $result['total']);
        self::assertCount(1, $result['items']);
    }

    /**
     * An admin scanning the inbox for the person on the phone types a name, and
     * the search has to cover the three things they might type.
     */
    public function test_the_list_searches_names_and_email(): void
    {
        $this->insert(['first_name' => 'Petra', 'last_name' => 'Vogel', 'email' => 'pv@example.org']);
        $this->insert(['first_name' => 'Lena', 'last_name' => 'Brandt', 'email' => 'lb@example.org']);

        self::assertCount(1, $this->repository->listPaginated(10, 0, 'submitted_at', 'desc', 'vogel')['items']);
        self::assertCount(1, $this->repository->listPaginated(10, 0, 'submitted_at', 'desc', 'Petra')['items']);
        self::assertCount(1, $this->repository->listPaginated(10, 0, 'submitted_at', 'desc', 'lb@')['items']);
        self::assertCount(0, $this->repository->listPaginated(10, 0, 'submitted_at', 'desc', 'nobody')['items']);
    }

    /**
     * A sort key is a column name reaching SQL, so the allow-list is the whole
     * defence — anything else falls back to the default rather than being
     * interpolated.
     */
    public function test_an_unknown_sort_key_falls_back_instead_of_reaching_sql(): void
    {
        $older = $this->insert(['submitted_at' => '2026-08-20 09:00:00']);
        $newer = $this->insert(['submitted_at' => '2026-08-29 09:00:00']);

        $result = $this->repository->listPaginated(10, 0, 'id; DROP TABLE pending_registrations', 'desc');

        self::assertSame([$newer, $older], array_column($result['items'], 'id'));
    }

    public function test_update_writes_only_the_fields_it_is_given(): void
    {
        $id = $this->insert(['first_name' => 'Lena']);

        $row = $this->repository->updateById($id, [
            'first_name' => 'Magdalena',
            'account_holder_name' => 'Petra Brandt',
            // Not in the allow-list — and not a column any more either, so a
            // caller still sending it must be ignored rather than reaching the
            // UPDATE and erroring on an unknown column.
            'phone' => '+49 69 1234',
        ]);

        self::assertNotNull($row);
        self::assertSame('Magdalena', $row['first_name']);
        self::assertSame('Petra Brandt', $row['account_holder_name']);
        self::assertSame('Brandt', $row['last_name']);
        self::assertArrayNotHasKey('phone', $row);
    }

    /**
     * The pending row is the applicant's data, not a place to reset the clock
     * on it. An expiry an admin could push out is a retention rule that never
     * runs (decision 10).
     */
    public function test_update_ignores_columns_outside_its_allow_list(): void
    {
        $id = $this->insert(['expires_at' => '2026-09-30 10:00:00']);

        $row = $this->repository->updateById($id, [
            'first_name' => 'Magdalena',
            'expires_at' => '2099-01-01 00:00:00',
            'submitted_at' => '2099-01-01 00:00:00',
            'mandate_reference' => 'somebodyelses',
        ]);

        self::assertSame('Magdalena', $row['first_name']);
        self::assertSame('2026-09-30 10:00:00', $row['expires_at']);
        self::assertSame('2026-08-31 10:00:00', $row['submitted_at']);
    }

    /**
     * Four columns, one fact. A key id arriving without the ciphertext it
     * belongs to labels the old seal with a key that cannot open it — a row
     * that is wrong the moment it is written and looks fine until a SEPA export
     * needs the plaintext, by which time the plaintext is gone.
     */
    public function test_update_refuses_a_partial_write_of_the_sealed_columns(): void
    {
        $id = $this->insert();

        $this->expectException(\InvalidArgumentException::class);
        $this->repository->updateById($id, ['encryption_key_id' => '99999999-9999-4999-8999-999999999999']);
    }

    public function test_update_replaces_the_sealed_iban_material_as_one_unit(): void
    {
        $id = $this->insert();

        $row = $this->repository->updateById($id, [
            'iban_ciphertext' => 'v1:bmV3',
            'iban_last4' => '7777',
            'iban_fingerprint' => str_repeat('d', 64),
            'encryption_key_id' => '22222222-2222-4222-8222-222222222222',
            'bank_name' => 'Volksbank',
        ]);

        self::assertSame('v1:bmV3', $row['iban_ciphertext']);
        self::assertSame('7777', $row['iban_last4']);
        self::assertSame(str_repeat('d', 64), $row['iban_fingerprint']);
        // The key id travels *with* the ciphertext and only with it: a row
        // sealed under one key and labelled with another cannot be opened.
        self::assertSame('22222222-2222-4222-8222-222222222222', $row['encryption_key_id']);
        self::assertSame('Volksbank', $row['bank_name']);
    }

    public function test_update_returns_null_for_a_row_that_is_not_there(): void
    {
        self::assertNull($this->repository->updateById('11111111-2222-4333-8444-555555555555', ['first_name' => 'X']));
    }

    public function test_delete_removes_the_row_and_reports_whether_it_did(): void
    {
        $id = $this->insert();

        self::assertTrue($this->repository->deleteById($id));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM pending_registrations')->fetchColumn());
        self::assertFalse($this->repository->deleteById($id));
    }
}
