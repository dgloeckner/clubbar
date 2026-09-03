<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Registrations\Services;

use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\MembersService;
use App\Modules\Registrations\Repositories\RegistrationsRepository;
use App\Modules\Registrations\Services\RegistrationReviewService;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use App\Shared\Utils\Uuid;
use Tests\Feature\DatabaseTestCase;

/**
 * Approval against the real database (#779).
 *
 * The unit suite asserts the orchestration — that `createFromSealedMandate()` is
 * called once, with the sealed material untouched. Three things it cannot
 * assert, and this file is for exactly those:
 *
 * 1. **Atomicity.** Two rows in two tables, or neither. `sqlite::memory:` would
 *    let this pass while MariaDB's engine, its FK to `encryption_keys` and its
 *    UNIQUE key over `mandates.reference` decide the real outcome.
 * 2. **The ciphertext survives the round trip byte for byte.** It is binary in a
 *    `VARBINARY(512)` column, moved between two tables through PHP — the place
 *    a silent charset or truncation change would corrupt it, and where nobody
 *    would notice until a SEPA export could not open it, months later, after
 *    the plaintext was gone.
 * 3. **The audit ENUM accepts the action.** `AuditActionSchemaTest` checks the
 *    migration as text; this checks that the migration was actually applied to
 *    the database the code is talking to.
 *
 * And the invariant the whole feature rests on: a pending registration is not a
 * member, so no terminal can see one.
 */
final class RegistrationApprovalTest extends DatabaseTestCase
{
    private const IBAN = 'DE89370400440532013000';

    private RegistrationsRepository $registrations;
    private MembersRepository $members;
    private RegistrationReviewService $service;

    /** @var list<string> */
    private array $memberIds = [];
    /** @var list<string> */
    private array $registrationIds = [];
    /** @var list<string> */
    private array $auditEntityIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEncryptionKey();

        $sealedBox = new IbanSealedBox(str_repeat('0', 63) . '2', 'test');

        $this->registrations = new RegistrationsRepository($this->db);
        $this->members = new MembersRepository(
            $this->db,
            $this->logger,
            $sealedBox,
            new EncryptionKeysRepository($this->db, $this->logger),
        );

        $this->service = new RegistrationReviewService(
            $this->registrations,
            $this->members,
            new AuditService(new AuditLogRepository($this->db, $this->logger)),
            new EncryptionKeysRepository($this->db, $this->logger),
            new BankCodeService(new BankCodesRepository($this->db, $this->logger), $this->logger),
            $sealedBox,
            $this->db,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM mandates WHERE member_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }
        foreach ($this->registrationIds as $id) {
            $this->db->prepare('DELETE FROM pending_registrations WHERE id = ?')->execute([$id]);
        }
        foreach ($this->auditEntityIds as $id) {
            $this->db->prepare('DELETE FROM audit_log WHERE entity_id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    /**
     * A submission, sealed the way the public endpoint seals one.
     *
     * @param array<string, mixed> $overrides
     */
    private function pending(array $overrides = []): string
    {
        $key = (new EncryptionKeysRepository($this->db, $this->logger))->requireOperationalActive();
        $sealedBox = new IbanSealedBox(str_repeat('0', 63) . '2', 'test');
        $suffix = substr(Uuid::v4(), 0, 8);

        $id = $this->registrations->create($overrides + [
            'first_name' => 'Selbst',
            'last_name' => 'Anmeldung' . $suffix,
            'email' => "selfreg-{$suffix}@example.org",
            'date_of_birth' => '1998-04-02',
            'preferred_language' => 'de',
            'account_holder_name' => null,
            'mandate_reference' => 'SELFREG' . strtoupper($suffix),
            'iban_ciphertext' => $sealedBox->seal(self::IBAN, $key['public_key']),
            'iban_last4' => IbanSealedBox::lastFour(self::IBAN),
            'iban_fingerprint' => $sealedBox->fingerprint(self::IBAN),
            'encryption_key_id' => $key['id'],
            'bank_name' => 'Sparkasse',
            'privacy_notice_url' => 'https://club.example/Anmeldung.pdf',
            'privacy_notice_shown_at' => date('Y-m-d H:i:s'),
            'submitted_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $this->registrationIds[] = $id;
        $this->auditEntityIds[] = $id;

        return $id;
    }

    public function test_approval_creates_the_member_and_the_mandate_together(): void
    {
        $id = $this->pending();
        $before = $this->registrations->findById($id);

        $member = $this->service->approve($id, '2026-08-30', true, null);
        $this->memberIds[] = $member->id;

        $mandate = $this->members->findActiveMandate($member->id);

        $this->assertNotNull($mandate, 'An approved member must have an active mandate.');
        $this->assertSame($before['mandate_reference'], $mandate['reference']);
        $this->assertSame('2026-08-30', $mandate['signed_at']);
        $this->assertSame('Sparkasse', $mandate['bank_name']);
        $this->assertNull($this->registrations->findById($id), 'The pending row must be gone.');
    }

    /**
     * The claim the SEPA export depends on, asserted the only way that counts:
     * open the copied ciphertext and get the original number back.
     */
    public function test_the_copied_ciphertext_still_opens_to_the_submitted_iban(): void
    {
        $id = $this->pending();
        $before = $this->registrations->findById($id);

        $member = $this->service->approve($id, '2026-08-30', true, null);
        $this->memberIds[] = $member->id;

        $sealed = $this->members->findSealedIban($member->id);

        $this->assertSame(
            (string) $before['iban_ciphertext'],
            (string) $sealed['iban_ciphertext'],
            'The ciphertext must survive the copy byte for byte.',
        );
        $this->assertSame($before['encryption_key_id'], $sealed['encryption_key_id']);
        $this->assertSame(self::IBAN, ($this->devIbanOpener())((string) $sealed['iban_ciphertext']));
    }

    /**
     * Both rows or neither. The reference was minted at submission and has been
     * sitting in the pending row since — long enough for something else to have
     * taken it — and the member that write half-created would be SEPA-invalid,
     * excluded from every collection, and unrecoverable once the pending row
     * was deleted.
     */
    public function test_a_mandate_that_cannot_be_written_leaves_no_member_behind(): void
    {
        $taken = 'TAKEN' . strtoupper(substr(Uuid::v4(), 0, 10));

        $existing = $this->members->create([
            'first_name' => 'Bestehendes',
            'last_name' => 'Mitglied',
            'email' => 'existing-' . substr(Uuid::v4(), 0, 8) . '@example.org',
            'preferred_language' => 'de',
            'iban' => 'DE02120300000000202051',
            'mandate_reference' => $taken,
            'mandate_signed_at' => '2026-01-01',
        ]);
        $this->memberIds[] = $existing['id'];

        $id = $this->pending(['mandate_reference' => $taken]);
        $emailBefore = $this->registrations->findById($id)['email'];

        $membersBefore = (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn();

        try {
            $this->service->approve($id, '2026-08-30', true, null);
            $this->fail('A reference already in use must refuse the approval.');
        } catch (DuplicateResourceException) {
            // expected
        }

        $this->assertSame(
            $membersBefore,
            (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn(),
            'A refused approval must leave no member behind.',
        );

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM members WHERE email = ?');
        $stmt->execute([$emailBefore]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        $this->assertNotNull($this->registrations->findById($id), 'The submission must still be reviewable.');
    }

    /**
     * `audit_log.action` is an ENUM, so this asserts the migration was applied
     * to the database the code is talking to — and, because the entry is
     * written inside the approval's own transaction, that a missing value would
     * take the whole approval down with it.
     */
    public function test_the_approval_is_audited_against_the_registration(): void
    {
        $id = $this->pending();

        $member = $this->service->approve($id, '2026-08-30', true, null);
        $this->memberIds[] = $member->id;

        $stmt = $this->db->prepare(
            "SELECT * FROM audit_log WHERE entity_id = ? AND action = 'registration_approved'"
        );
        $stmt->execute([$id]);
        $entry = $stmt->fetch();

        $this->assertNotFalse($entry, 'An approval must be audited.');
        $this->assertSame('registration', $entry['entity_type']);

        $payload = (string) $entry['new_values'];
        $this->assertStringContainsString($member->id, $payload);
        $this->assertStringContainsString('****3000', $payload);
        $this->assertStringNotContainsString(self::IBAN, $payload, 'No readable IBAN reaches the log.');
    }

    public function test_a_rejection_is_audited_and_creates_no_member(): void
    {
        $id = $this->pending();
        $membersBefore = (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn();

        $this->service->reject($id, 'No signed mandate arrived', null);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE entity_id = ? AND action = 'registration_rejected'"
        );
        $stmt->execute([$id]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
        $this->assertNull($this->registrations->findById($id));
        $this->assertSame(
            $membersBefore,
            (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn(),
        );
    }

    /**
     * The gate invariant, asserted where a terminal would see it.
     *
     * A pending registration is not a member and no sync can reach it. Nothing
     * makes that true except approval being the only door — so this is the
     * assertion that catches any future path that quietly opens a second one.
     */
    public function test_a_pending_registration_never_appears_in_the_terminal_sync(): void
    {
        // A real member first, so the assertion below distinguishes "the sync
        // excludes the pending row" from "the sync returned nothing" — which
        // is what it did on a database whose members table happened to be
        // empty, passing while asserting precisely nothing.
        $visible = $this->members->create([
            'first_name' => 'Sichtbares',
            'last_name' => 'Mitglied',
            'email' => 'visible-' . substr(Uuid::v4(), 0, 8) . '@example.org',
            'preferred_language' => 'de',
        ]);
        $this->memberIds[] = $visible['id'];

        $id = $this->pending();
        $row = $this->registrations->findById($id);

        $membersService = new MembersService(
            $this->members,
            new TransactionsRepository($this->db, $this->logger),
            new AuditService(new AuditLogRepository($this->db, $this->logger)),
            new AuditLogRepository($this->db, $this->logger),
            $this->createMock(\App\Modules\Notifications\Services\NotificationsService::class),
            $this->db,
        );

        $synced = array_map(
            static fn($member): array => $member->toArray(),
            $membersService->syncSince(0)->items,
        );

        // Matched on the surname, not the email: the terminal payload carries no
        // email at all (ADR-0045 keeps it to what a kiosk needs), so an
        // email-based assertion would have compared a column of nulls and
        // passed regardless. Both fixtures mint a unique surname for this.
        $names = array_column($synced, 'last_name');

        $this->assertContains($visible['last_name'], $names, 'The sync must be returning members at all.');
        $this->assertNotContains($row['last_name'], $names, 'A pending registration reached the terminal sync.');
    }

    public function test_an_email_that_is_already_a_member_refuses_the_approval(): void
    {
        $email = 'collision-' . substr(Uuid::v4(), 0, 8) . '@example.org';

        $existing = $this->members->create([
            'first_name' => 'Bestehendes',
            'last_name' => 'Mitglied',
            'email' => $email,
            'preferred_language' => 'de',
        ]);
        $this->memberIds[] = $existing['id'];

        $id = $this->pending(['email' => $email]);

        $this->expectException(BusinessRuleException::class);
        $this->service->approve($id, '2026-08-30', true, null);
    }

    /**
     * The duplicate flags the review list shows, against the real tables — the
     * fingerprint match in particular, which is the comparison ADR-0036 built
     * for and the only one answerable without a key.
     */
    public function test_the_duplicate_flags_find_an_existing_member(): void
    {
        $email = 'returning-' . substr(Uuid::v4(), 0, 8) . '@example.org';

        $existing = $this->members->create([
            'first_name' => 'Wieder',
            'last_name' => 'Da',
            'email' => $email,
            'preferred_language' => 'de',
            'iban' => self::IBAN,
            'mandate_signed_at' => '2026-01-01',
        ]);
        $this->memberIds[] = $existing['id'];

        $id = $this->pending(['email' => $email]);

        $dto = $this->service->get($id);

        $this->assertTrue($dto->duplicateEmail, 'The club already has a member at this address.');
        $this->assertTrue($dto->duplicateIban, 'The club already holds a mandate on this account.');
    }
}
