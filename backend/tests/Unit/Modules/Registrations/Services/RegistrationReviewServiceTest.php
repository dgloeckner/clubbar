<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Services;

use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Registrations\Repositories\RegistrationsRepository;
use App\Modules\Registrations\Services\RegistrationReviewService;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Logging\Logger;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The admin half of self-registration (#779, UC-A17).
 *
 * The pending store is real here — `sqlite::memory:` with a hand-maintained
 * copy of migration `059` — so the reads, the edit and the deletes are the
 * queries that will actually run. `MembersRepository` is a mock, deliberately:
 * what this service owes the members table is a precise *call*, made exactly
 * once, carrying the sealed material through untouched, and that is what a mock
 * can assert byte for byte. Whether the two rows then land atomically is a
 * property of MariaDB and belongs to the Feature suite, which has one.
 */
final class RegistrationReviewServiceTest extends TestCase
{
    private const ADMIN = '44444444-4444-4444-4444-444444444444';
    private const NEW_IBAN = 'DE02120300000000202051';

    private PDO $db;
    private RegistrationsRepository $registrations;
    private MembersRepository&MockObject $members;
    private AuditService&MockObject $audit;
    private RegistrationReviewService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec(
            'CREATE TABLE pending_registrations (
                id CHAR(36) NOT NULL PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL, phone VARCHAR(20) NULL,
                date_of_birth DATE NOT NULL, preferred_language VARCHAR(10) NOT NULL,
                account_holder_name VARCHAR(70) NULL,
                mandate_reference VARCHAR(35) NOT NULL UNIQUE,
                iban_ciphertext BLOB NOT NULL, iban_last4 CHAR(4) NOT NULL,
                iban_fingerprint CHAR(64) NOT NULL, encryption_key_id CHAR(36) NOT NULL,
                bank_name VARCHAR(255) NULL, privacy_notice_url VARCHAR(500) NOT NULL,
                privacy_notice_shown_at DATETIME NOT NULL,
                submitted_at DATETIME NOT NULL, expires_at DATETIME NOT NULL
            )'
        );

        $keypair = sodium_crypto_box_keypair();
        $this->db->exec(
            'CREATE TABLE encryption_keys (
                id CHAR(36) PRIMARY KEY, key_identifier VARCHAR(100) NOT NULL,
                algorithm VARCHAR(50) NOT NULL, public_key BLOB NOT NULL,
                fingerprint_sha256 CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL,
                activated_at DATETIME NULL, expires_at DATETIME NULL,
                created_at DATETIME NULL, created_by_admin_id CHAR(36) NULL
            )'
        );
        $stmt = $this->db->prepare(
            "INSERT INTO encryption_keys
                (id, key_identifier, algorithm, public_key, fingerprint_sha256, status, activated_at, expires_at)
             VALUES ('key-today', 'club-2026', 'SODIUM_CRYPTO_BOX_SEAL', ?, ?, 'active', '2026-01-01 00:00:00', NULL)"
        );
        $stmt->bindValue(1, sodium_crypto_box_publickey($keypair), PDO::PARAM_LOB);
        $stmt->bindValue(2, str_repeat('f', 64));
        $stmt->execute();

        $this->db->exec(
            'CREATE TABLE bank_codes (bank_code CHAR(8) NOT NULL PRIMARY KEY, bank_name VARCHAR(255) NOT NULL)'
        );
        $this->db->exec("INSERT INTO bank_codes (bank_code, bank_name) VALUES ('12030000', 'DKB')");

        $logger = new Logger(sys_get_temp_dir() . '/registration-review-tests', 'CRITICAL');

        $this->registrations = new RegistrationsRepository($this->db);
        $this->members = $this->createMock(MembersRepository::class);
        $this->audit = $this->createMock(AuditService::class);

        $this->service = new RegistrationReviewService(
            $this->registrations,
            $this->members,
            $this->audit,
            new EncryptionKeysRepository($this->db, $logger),
            new BankCodeService(new BankCodesRepository($this->db, $logger), $logger),
            new IbanSealedBox(str_repeat('ab', 32), 'testing'),
            $this->db,
            $logger,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function pending(array $overrides = []): string
    {
        return $this->registrations->create($overrides + [
            'first_name' => 'Lena',
            'last_name' => 'Brandt',
            'email' => 'lena@example.org',
            'phone' => null,
            'date_of_birth' => '1998-04-02',
            'preferred_language' => 'de',
            'account_holder_name' => null,
            'mandate_reference' => bin2hex(random_bytes(8)),
            'iban_ciphertext' => 'v1:c2VhbGVkLWF0LXN1Ym1pc3Npb24',
            'iban_last4' => '3000',
            'iban_fingerprint' => str_repeat('a', 64),
            'encryption_key_id' => 'key-of-the-day-it-was-sealed',
            'bank_name' => 'Sparkasse',
            'privacy_notice_url' => 'https://club.example/Anmeldung.pdf',
            'privacy_notice_shown_at' => '2026-08-31 10:00:00',
            'submitted_at' => '2026-08-31 10:00:00',
            'expires_at' => '2026-09-30 10:00:00',
        ]);
    }

    // ── reading ──────────────────────────────────────────────────────────

    public function test_the_list_masks_the_iban_and_never_carries_the_sealed_material(): void
    {
        $this->pending();
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('findExistingIbanFingerprints')->willReturn([]);

        $result = $this->service->list(10, 0);

        self::assertSame(1, $result->total);
        $row = $result->items[0];
        self::assertSame('****3000', $row['iban_masked']);
        self::assertArrayNotHasKey('iban_ciphertext', $row);
        self::assertArrayNotHasKey('iban_fingerprint', $row);
        self::assertArrayNotHasKey('encryption_key_id', $row);
    }

    /**
     * The whole reason the list exists as more than a table: an applicant the
     * club already has is the one thing an admin must not approve on autopilot.
     */
    public function test_the_list_flags_an_applicant_the_club_already_has(): void
    {
        $this->pending(['email' => 'known@example.org', 'iban_fingerprint' => str_repeat('b', 64)]);
        $this->pending(['email' => 'stranger@example.org', 'iban_fingerprint' => str_repeat('c', 64)]);

        $this->members->method('findExistingEmails')->willReturn(['known@example.org']);
        $this->members->method('findExistingIbanFingerprints')->willReturn([str_repeat('c', 64)]);

        $byEmail = [];
        foreach ($this->service->list(10, 0)->items as $row) {
            $byEmail[$row['email']] = $row;
        }

        self::assertTrue($byEmail['known@example.org']['duplicate_email']);
        self::assertFalse($byEmail['known@example.org']['duplicate_iban']);
        self::assertFalse($byEmail['stranger@example.org']['duplicate_email']);
        self::assertTrue($byEmail['stranger@example.org']['duplicate_iban']);
    }

    /** One page, two queries — not two queries per row. */
    public function test_the_duplicate_check_is_one_query_per_page(): void
    {
        $this->pending(['email' => 'a@example.org']);
        $this->pending(['email' => 'b@example.org']);
        $this->pending(['email' => 'c@example.org']);

        $this->members->expects(self::once())->method('findExistingEmails')->willReturn([]);
        $this->members->expects(self::once())->method('findExistingIbanFingerprints')->willReturn([]);

        $this->service->list(10, 0);
    }

    public function test_get_refuses_an_id_that_is_not_there(): void
    {
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('findExistingIbanFingerprints')->willReturn([]);

        $this->expectException(NotFoundException::class);
        $this->service->get('11111111-2222-4333-8444-555555555555');
    }

    // ── editing ──────────────────────────────────────────────────────────

    public function test_an_edit_corrects_the_row_and_is_audited(): void
    {
        $id = $this->pending(['first_name' => 'Lena']);
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('findExistingIbanFingerprints')->willReturn([]);

        $this->audit->expects(self::once())->method('log')->with(
            AuditAction::REGISTRATION_EDITED,
            EntityType::REGISTRATION,
            $id,
            self::anything(),
            self::anything(),
            self::ADMIN,
        );

        $dto = $this->service->update($id, ['first_name' => 'Magdalena'], self::ADMIN);

        self::assertSame('Magdalena', $dto->firstName);
    }

    /**
     * An edit that changes nothing writes no audit row. A log full of "Lena
     * Brandt edited, no fields changed" is a log nobody reads.
     */
    public function test_an_edit_that_changes_nothing_is_not_audited(): void
    {
        $id = $this->pending(['first_name' => 'Lena']);
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('findExistingIbanFingerprints')->willReturn([]);

        $this->audit->expects(self::never())->method('log');

        $this->service->update($id, ['first_name' => 'Lena'], self::ADMIN);
    }

    /**
     * Correcting an IBAN re-runs the whole write path — validate, seal under
     * *today's* active key, recompute the last four and the fingerprint, and
     * re-resolve the bank. Copying the new number into the old row's last4
     * while leaving the old ciphertext would produce a mandate that collects
     * from the wrong account and displays the right one.
     */
    public function test_replacing_the_iban_re_seals_the_whole_quartet(): void
    {
        $id = $this->pending();
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('findExistingIbanFingerprints')->willReturn([]);

        $dto = $this->service->update($id, ['iban' => self::NEW_IBAN], self::ADMIN);

        self::assertSame('2051', $dto->ibanLast4);
        self::assertSame('DKB', $dto->bankName);

        $row = $this->registrations->findById($id);
        self::assertSame('key-today', $row['encryption_key_id']);
        self::assertNotSame('v1:c2VhbGVkLWF0LXN1Ym1pc3Npb24', $row['iban_ciphertext']);
        self::assertNotSame(str_repeat('a', 64), $row['iban_fingerprint']);
    }

    /** Never the number, on any path a log can reach (ADR-0005). */
    public function test_an_iban_edit_is_audited_with_the_masked_value_only(): void
    {
        $id = $this->pending();
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('findExistingIbanFingerprints')->willReturn([]);

        $recorded = [];
        $this->audit->method('log')->willReturnCallback(
            function (...$args) use (&$recorded): void {
                $recorded[] = $args;
            }
        );

        $this->service->update($id, ['iban' => self::NEW_IBAN], self::ADMIN);

        $payload = json_encode($recorded);
        self::assertStringNotContainsString(self::NEW_IBAN, $payload);
        self::assertStringContainsString('****2051', $payload);
    }

    public function test_editing_a_row_that_is_not_there_is_a_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->update('11111111-2222-4333-8444-555555555555', ['first_name' => 'X'], self::ADMIN);
    }

    // ── approving ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function memberRow(): array
    {
        return [
            'id' => '77777777-7777-7777-7777-777777777777',
            'first_name' => 'Lena', 'last_name' => 'Brandt', 'email' => 'lena@example.org',
            'phone' => null, 'date_of_birth' => '1998-04-02', 'card_uid' => null,
            'preferred_language' => 'de', 'credit_limit_cents' => null, 'is_active' => 1,
            'account_holder_name' => null, 'iban_last4' => '3000', 'has_iban' => 1,
            'bank_name' => 'Sparkasse', 'mandate_reference' => 'ref', 'mandate_signed_at' => '2026-08-30',
            'balance_cents' => 0, 'deleted_at' => null,
            'created_at' => '2026-09-01 09:00:00', 'updated_at' => '2026-09-01 09:00:00',
        ];
    }

    /**
     * The sealed material is copied, not re-derived. There is nothing to
     * re-derive it from: the plaintext was sealed at submission under a key
     * this server does not hold (ADR-0036), so a mandate that did not carry the
     * original ciphertext *and* the original key id could never be collected on.
     */
    public function test_approval_copies_the_sealed_material_across_verbatim(): void
    {
        $id = $this->pending();
        $row = $this->registrations->findById($id);
        $this->members->method('findExistingEmails')->willReturn([]);

        $this->members->expects(self::once())->method('createFromSealedMandate')->with(
            self::callback(static fn(array $m): bool => $m['email'] === 'lena@example.org'
                && $m['date_of_birth'] === '1998-04-02'),
            self::callback(static fn(array $mandate): bool =>
                $mandate['reference'] === $row['mandate_reference']
                && $mandate['iban_ciphertext'] === $row['iban_ciphertext']
                && $mandate['iban_last4'] === '3000'
                && $mandate['iban_fingerprint'] === str_repeat('a', 64)
                && $mandate['encryption_key_id'] === 'key-of-the-day-it-was-sealed'
                && $mandate['bank_name'] === 'Sparkasse'
                && $mandate['signed_at'] === '2026-08-30'),
        )->willReturn($this->memberRow());

        $this->service->approve($id, '2026-08-30', true, self::ADMIN);
    }

    public function test_approval_deletes_the_pending_row(): void
    {
        $id = $this->pending();
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('createFromSealedMandate')->willReturn($this->memberRow());

        $this->service->approve($id, '2026-08-30', true, self::ADMIN);

        self::assertNull($this->registrations->findById($id));
    }

    /**
     * The confirmation is the attestation — an admin stating they hold the
     * signed paper and that its IBAN matches the `****last4` on file (epic
     * decision 9). Without it there is no approval to make.
     */
    public function test_approval_without_the_attestation_is_refused(): void
    {
        $id = $this->pending();
        $this->members->expects(self::never())->method('createFromSealedMandate');

        try {
            $this->service->approve($id, '2026-08-30', false, self::ADMIN);
            self::fail('An unattested approval must be refused.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::REGISTRATION_ATTESTATION_REQUIRED, $e->getReason());
        }

        self::assertNotNull($this->registrations->findById($id), 'A refused approval must leave the row alone.');
    }

    /**
     * `members.email` carries no UNIQUE constraint, so nothing below this would
     * have refused — the club would end up with two member records for one
     * person and find out at the next settlement, when both got a statement.
     */
    public function test_approval_is_refused_when_the_email_is_already_a_member(): void
    {
        $id = $this->pending(['email' => 'known@example.org']);
        $this->members->method('findExistingEmails')->willReturn(['known@example.org']);
        $this->members->expects(self::never())->method('createFromSealedMandate');

        try {
            $this->service->approve($id, '2026-08-30', true, self::ADMIN);
            self::fail('A colliding email must be refused.');
        } catch (BusinessRuleException $e) {
            self::assertSame(BusinessRuleReason::REGISTRATION_MEMBER_EMAIL_EXISTS, $e->getReason());
        }

        self::assertNotNull($this->registrations->findById($id));
    }

    /**
     * The entry names the *registration*, not the member, and it is what keeps
     * a member's origin traceable once the pending row is gone — nothing else
     * records that this person arrived through a poster.
     */
    public function test_approval_is_audited_against_the_registration_with_a_masked_iban(): void
    {
        $id = $this->pending();
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('createFromSealedMandate')->willReturn($this->memberRow());

        $recorded = [];
        $this->audit->method('log')->willReturnCallback(
            function (...$args) use (&$recorded): void {
                $recorded[] = $args;
            }
        );

        $this->service->approve($id, '2026-08-30', true, self::ADMIN);

        self::assertCount(1, $recorded);
        [$action, $entityType, $entityId] = $recorded[0];
        self::assertSame(AuditAction::REGISTRATION_APPROVED, $action);
        self::assertSame(EntityType::REGISTRATION, $entityType);
        self::assertSame($id, $entityId);

        $payload = json_encode($recorded);
        self::assertStringContainsString('****3000', $payload);
        self::assertStringContainsString('77777777-7777-7777-7777-777777777777', $payload);
        self::assertStringNotContainsString(str_repeat('a', 64), $payload, 'No fingerprint reaches the log.');
        self::assertStringNotContainsString('c2VhbGVkLWF0', $payload, 'No ciphertext reaches the log.');
    }

    /**
     * A member created without their mandate is SEPA-invalid, invisible to the
     * next collection, and — because approval deletes the pending row —
     * unrecoverable. So a refusal below must leave the queue exactly as it was.
     */
    public function test_a_failed_member_write_leaves_the_pending_row_standing(): void
    {
        $id = $this->pending();
        $this->members->method('findExistingEmails')->willReturn([]);
        $this->members->method('createFromSealedMandate')
            ->willThrowException(new \RuntimeException('mandate reference taken'));

        try {
            $this->service->approve($id, '2026-08-30', true, self::ADMIN);
            self::fail('The failure must propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNotNull($this->registrations->findById($id));
    }

    public function test_approving_a_row_that_is_not_there_is_a_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->approve('11111111-2222-4333-8444-555555555555', '2026-08-30', true, self::ADMIN);
    }

    // ── rejecting ────────────────────────────────────────────────────────

    public function test_rejection_deletes_the_row_and_records_the_reason(): void
    {
        $id = $this->pending();

        $recorded = [];
        $this->audit->method('log')->willReturnCallback(
            function (...$args) use (&$recorded): void {
                $recorded[] = $args;
            }
        );

        $this->service->reject($id, 'No signed mandate arrived', self::ADMIN);

        self::assertNull($this->registrations->findById($id));
        self::assertSame(AuditAction::REGISTRATION_REJECTED, $recorded[0][0]);
        $payload = json_encode($recorded);
        self::assertStringContainsString('No signed mandate arrived', $payload);
        self::assertStringContainsString('****3000', $payload);
    }

    public function test_rejecting_a_row_that_is_not_there_is_a_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->reject('11111111-2222-4333-8444-555555555555', null, self::ADMIN);
    }
}
