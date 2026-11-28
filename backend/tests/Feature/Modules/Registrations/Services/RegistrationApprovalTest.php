<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Registrations\Services;

use Tests\Support\MandateReferences;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;
use App\Modules\CreditLimits\Repositories\CreditLimitConfigRepository;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
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
            MandateReferences::real($this->db, $this->logger),
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
            new CreditLimitConfigService(
                new CreditLimitConfigRepository($this->db, $this->logger),
                new AuditService(new AuditLogRepository($this->db, $this->logger)),
            ),
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

    // ------------------------------------------------------------------
    // Accepting ahead of the paper (ADR-0052, amended 2026-11-28)
    // ------------------------------------------------------------------

    /**
     * A valid German IBAN nobody else in this database holds.
     *
     * The shared fixture IBAN is on a seeded member and on whatever other
     * suites left behind, so its fingerprint matches an active mandate — which
     * is exactly the duplicate flag the batch tests below turn on. An account
     * number drawn per call keeps the flag meaning what the test says it means.
     */
    private function uniqueIban(): string
    {
        $bban = '37040044' . str_pad((string) random_int(1, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        // ISO 13616: move "DE00" to the end as digits (D=13, E=14), mod 97.
        $check = 98 - (int) bcmod($bban . '131400', '97');

        return 'DE' . str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $bban;
    }

    /**
     * A pending registration sealed on an account of its own.
     *
     * @param array<string, mixed> $overrides
     */
    private function pendingOn(string $iban, array $overrides = []): string
    {
        $key = (new EncryptionKeysRepository($this->db, $this->logger))->requireOperationalActive();
        $sealedBox = new IbanSealedBox(str_repeat('0', 63) . '2', 'test');

        return $this->pending($overrides + [
            'iban_ciphertext' => $sealedBox->seal($iban, $key['public_key']),
            'iban_last4' => IbanSealedBox::lastFour($iban),
            'iban_fingerprint' => $sealedBox->fingerprint($iban),
        ]);
    }

    /**
     * The data model already carried this state; what changed is that approval
     * may now produce it. A mandate with no signature date is not a mandate
     * (ADR-0020), so the member exists and cannot buy or be collected from.
     */
    public function test_an_acceptance_without_a_date_creates_a_member_who_is_not_sepa_valid(): void
    {
        $id = $this->pending();

        $member = $this->service->approve($id, null, false, null);
        $this->memberIds[] = $member->id;

        $mandate = $this->members->findActiveMandate($member->id);
        $this->assertNotNull($mandate, 'The mandate is opened — only its signature is missing.');
        $this->assertNull($mandate['signed_at']);
        $this->assertFalse($member->isSepaValid, 'An unsigned mandate must not read as valid.');
        $this->assertNull($this->registrations->findById($id));
    }

    /** Recording the date on the member is what completes the mandate. */
    public function test_recording_the_signature_date_afterwards_makes_the_member_sepa_valid(): void
    {
        $member = $this->service->approve($this->pending(), null, false, null);
        $this->memberIds[] = $member->id;

        $updated = $this->members->updateById($member->id, ['mandate_signed_at' => '2026-11-28']);

        $this->assertNotNull($updated);
        $this->assertSame('2026-11-28', $updated['mandate_signed_at']);
        $this->assertTrue(
            \App\Modules\Members\Domain\MandateCompleteness::isComplete($updated),
            'A signature date on the mandate in hand completes it — no new mandate is opened.',
        );
        $this->assertSame(
            $this->members->findActiveMandate($member->id)['reference'],
            $updated['mandate_reference'],
        );
    }

    public function test_the_unsigned_acceptance_is_audited_as_unattested(): void
    {
        $id = $this->pending();

        $member = $this->service->approve($id, null, false, null);
        $this->memberIds[] = $member->id;

        $stmt = $this->db->prepare(
            "SELECT new_values FROM audit_log WHERE entity_id = ? AND action = 'registration_approved'"
        );
        $stmt->execute([$id]);
        $values = json_decode((string) $stmt->fetchColumn(), true);

        $this->assertArrayHasKey('mandate_signed_at', $values);
        $this->assertNull($values['mandate_signed_at']);
        $this->assertFalse($values['signed_mandate_confirmed']);
    }

    /**
     * The batch, against the real tables: each row is its own transaction, so
     * the members exist, their mandates are open and unsigned, and the pending
     * rows are gone.
     */
    public function test_a_batch_creates_unsigned_members_and_empties_the_inbox(): void
    {
        $first = $this->pendingOn($this->uniqueIban());
        $second = $this->pendingOn($this->uniqueIban());

        $result = $this->service->approveBatch([$first, $second], null);

        $this->memberIds = array_merge($this->memberIds, array_column($result['accepted'], 'member_id'));

        $this->assertSame([$first, $second], array_column($result['accepted'], 'registration_id'));
        $this->assertSame([], $result['skipped']);

        foreach ($result['accepted'] as $accepted) {
            $mandate = $this->members->findActiveMandate($accepted['member_id']);
            $this->assertNotNull($mandate);
            $this->assertNull($mandate['signed_at']);
            $this->assertNull($this->registrations->findById($accepted['registration_id']));
        }
    }

    /**
     * Two siblings registered on a parent's account. The fingerprint is shared,
     * and by the time the second is accepted the first *is* a member holding
     * it — so only an up-front evaluation lets both through.
     */
    public function test_two_siblings_on_one_account_are_both_accepted_in_one_batch(): void
    {
        $parentAccount = $this->uniqueIban();
        $lena = $this->pendingOn($parentAccount, ['account_holder_name' => 'Petra Brandt']);
        $jonas = $this->pendingOn($parentAccount, ['account_holder_name' => 'Petra Brandt']);

        $result = $this->service->approveBatch([$lena, $jonas], null);
        $this->memberIds = array_merge($this->memberIds, array_column($result['accepted'], 'member_id'));

        $this->assertCount(2, $result['accepted']);
        $this->assertSame([], $result['skipped']);
    }

    /**
     * A match against a member the club already has is left for a person.
     * Both flags, and the clean row beside them still goes through.
     */
    public function test_a_batch_skips_what_matches_an_existing_member(): void
    {
        $knownAccount = $this->uniqueIban();
        $knownEmail = 'known-' . substr(Uuid::v4(), 0, 8) . '@example.org';

        $existing = $this->members->create([
            'first_name' => 'Schon',
            'last_name' => 'Mitglied',
            'email' => $knownEmail,
            'preferred_language' => 'de',
            'iban' => $knownAccount,
            'mandate_signed_at' => '2026-01-01',
        ]);
        $this->memberIds[] = $existing['id'];

        $sameEmail = $this->pendingOn($this->uniqueIban(), ['email' => $knownEmail]);
        $sameAccount = $this->pendingOn($knownAccount);
        $clean = $this->pendingOn($this->uniqueIban());

        $result = $this->service->approveBatch([$sameEmail, $sameAccount, $clean], null);
        $this->memberIds = array_merge($this->memberIds, array_column($result['accepted'], 'member_id'));

        $this->assertSame([$clean], array_column($result['accepted'], 'registration_id'));
        $this->assertSame(
            [
                ['registration_id' => $sameEmail, 'reason' => 'registration_possible_duplicate'],
                ['registration_id' => $sameAccount, 'reason' => 'registration_possible_duplicate'],
            ],
            $result['skipped'],
        );
        $this->assertNotNull($this->registrations->findById($sameEmail), 'A skipped row stays in the inbox.');
        $this->assertNotNull($this->registrations->findById($sameAccount));
    }
}
