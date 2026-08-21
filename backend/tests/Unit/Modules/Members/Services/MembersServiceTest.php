<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Services\MembersService;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class MembersServiceTest extends TestCase
{
    private MembersRepository $membersRepository;
    private TransactionsRepository $transactionsRepository;
    private AuditService $auditService;
    private AuditLogRepository $auditLogRepository;
    private NotificationsService $notificationsService;
    private \PDO $db;
    private MembersService $membersService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->auditLogRepository = $this->createMock(AuditLogRepository::class);
        $this->db = $this->createMock(\PDO::class);
        // Erasure reaches the mail outbox too (#408); these tests do not
        // exercise it, so the collaborator is present and silent.
        $this->notificationsService = $this->createMock(NotificationsService::class);

        // Create service instance
        $this->membersService = new MembersService(
            $this->membersRepository,
            $this->transactionsRepository,
            $this->auditService,
            $this->auditLogRepository,
            $this->notificationsService,
            $this->db,
        );
    }

    public function test_syncSince_returns_cursor_in_milliseconds_when_no_rows(): void
    {
        // Mock repository to return empty array (no rows)
        $this->membersRepository
            ->expects($this->once())
            ->method('findModifiedSince')
            ->with($this->anything())
            ->willReturn([]);

        $result = $this->membersService->syncSince(9999999999999);

        // When no rows found, cursor echoes back $since to avoid race conditions
        // (items created during query execution won't be lost on next sync)
        $this->assertSame(9999999999999, $result->cursor);
    }

    public function test_syncSince_cursor_covers_every_row_it_returned(): void
    {
        $newest = (new \DateTime('2026-01-01 10:00:03'))->getTimestamp();

        $this->membersRepository
            ->method('findModifiedSince')
            ->willReturn([
                $this->syncRow('2026-01-01 10:00:01'),
                $this->syncRow('2026-01-01 10:00:03'),
            ]);

        $result = $this->membersService->syncSince(0);

        // Those seconds are long over, so the cursor steps past the newest one
        // rather than re-sending it forever (#84).
        $this->assertSame(($newest + 1) * 1000, $result->cursor);
    }

    private function syncRow(string $updatedAt): array
    {
        return [
            'id' => 'member-' . $updatedAt,
            'card_uid' => null,
            'first_name' => 'Sync',
            'last_name' => 'Row',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => null,
            'mandate_reference' => null,
            'deleted_at' => null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ];
    }

    // ── anonymizeMember ────────────────────────────────────

    private function member(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'email' => 'max@example.com',
            'phone' => '+491234567',
            'card_uid' => 'CARD-1',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'account_holder_name' => 'Max Mustermann',
            'mandate_reference' => 'F3332CA866B249E7A202BFBF4836B605',
            'mandate_signed_at' => '2026-01-01',
            'deleted_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    public function test_updateMember_passes_through_only_the_updatable_fields(): void
    {
        // The allowlist used to be written as a camelCase => snake_case map
        // whose camelCase half the loop never read (#120). It is a plain list
        // of the snake_case fields now, and it still has to drop everything
        // else — `id` and `created_at` are not the caller's to rewrite.
        $this->membersRepository->method('findById')->willReturn($this->member('member-1'));

        $this->membersRepository
            ->expects($this->once())
            ->method('updateById')
            ->with('member-1', [
                'first_name' => 'Erika',
                'iban' => 'DE02120300000000202051',
                'mandate_signed_at' => '2026-02-02',
                // Added by the service at write time (ADR-0036): the sealed row
                // cannot answer the bank-name question later. Null here because
                // the test wires no BankCodeService.
                'bank_name' => null,
            ])
            ->willReturn($this->member('member-1', ['first_name' => 'Erika']));

        $this->membersService->updateMember('member-1', [
            'first_name' => 'Erika',
            'iban' => 'DE02120300000000202051',
            'mandate_signed_at' => '2026-02-02',
            'id' => 'someone-else',
            'created_at' => '2000-01-01 00:00:00',
            'firstName' => 'Ignored',
        ]);
    }

    /**
     * A corrected birth date has to reach the row (#582 M2, ADR-0045).
     *
     * The allowlist above is a second gate behind the controller's rules, and
     * this field is the one where being silently dropped is worst: the value on
     * file is what the terminal's Jugendschutz check trusts, so an
     * uncorrectable typo is a member refused every restricted product for good,
     * with the edit form reporting success.
     */
    public function test_updateMember_lets_a_corrected_date_of_birth_through(): void
    {
        $this->membersRepository->method('findById')->willReturn($this->member('member-1'));

        $this->membersRepository
            ->expects($this->once())
            ->method('updateById')
            ->with('member-1', ['date_of_birth' => '1990-04-05'])
            ->willReturn($this->member('member-1', ['date_of_birth' => '1990-04-05']));

        $this->membersService->updateMember('member-1', ['date_of_birth' => '1990-04-05']);
    }

    /**
     * `createMember()` threads the birth date to the repository under the
     * column name. It is a named parameter rather than another key in an array,
     * so a caller that forgets it is a type error rather than a member with no
     * age.
     */
    public function test_createMember_writes_the_date_of_birth(): void
    {
        $this->membersRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data): bool {
                $this->assertSame('1990-05-04', $data['date_of_birth']);

                return true;
            }))
            ->willReturn($this->member('member-1', ['date_of_birth' => '1990-05-04']));

        $dto = $this->membersService->createMember(
            firstName: 'Ada',
            lastName: 'Lovelace',
            email: 'ada@example.org',
            phone: null,
            cardUid: null,
            language: \App\Modules\Members\Enums\SupportedLanguage::from('de'),
            dateOfBirth: '1990-05-04',
        );

        $this->assertSame('1990-05-04', $dto->dateOfBirth);
    }

    /**
     * The member list does not carry birth dates (#582 M2, ADR-0045).
     *
     * The list is a roster on a screen — names, balances, SEPA state — and
     * every row of it is personal data an admin scrolls past without asking
     * for. The birth date is needed in exactly two places: the terminal, which
     * gets it through the sync, and the edit form, which loads the member by id
     * before it opens. `MemberListItem` in `api/admin.yaml` says so too.
     *
     * This is the one place minimization is cheap enough to be worth writing
     * down, so it is asserted rather than assumed.
     */
    public function test_listMembers_leaves_the_date_of_birth_out_of_the_roster(): void
    {
        $this->membersRepository
            ->method('listPaginated')
            ->willReturn([
                'items' => [$this->member('member-1', ['date_of_birth' => '1990-05-04'])],
                'total' => 1,
            ]);

        $result = $this->membersService->listMembers(20, 0);

        $this->assertArrayNotHasKey('date_of_birth', $result->items[0]);
        // The row is otherwise unchanged — this drops one key, it does not
        // reshape the list.
        $this->assertSame('member-1', $result->items[0]['id']);
    }

    /**
     * The flag that replaces the date (#629).
     *
     * Dropping `date_of_birth` kept the roster minimal and made the one gap
     * with a legal edge — a member the terminal refuses every age-restricted
     * product to (ADR-0045) — impossible to find from the panel. The boolean
     * restores the ability to find them without restoring the date.
     */
    public function test_listMembers_reports_whether_a_birth_date_exists(): void
    {
        $this->membersRepository
            ->method('listPaginated')
            ->willReturn([
                'items' => [
                    $this->member('has-dob', ['date_of_birth' => '1990-05-04']),
                    $this->member('no-dob', ['date_of_birth' => null]),
                ],
                'total' => 2,
            ]);

        $result = $this->membersService->listMembers(20, 0);

        $this->assertTrue($result->items[0]['has_date_of_birth']);
        $this->assertFalse($result->items[1]['has_date_of_birth']);
        // The flag replaces the date, it does not accompany it.
        $this->assertArrayNotHasKey('date_of_birth', $result->items[0]);
        $this->assertArrayNotHasKey('date_of_birth', $result->items[1]);
    }

    public function test_getDataCompleteness_passes_the_repository_counts_through(): void
    {
        $counts = [
            'total' => 132,
            'without_card_uid' => 9,
            'without_email' => 2,
            'without_date_of_birth' => 3,
            'without_mandate' => 4,
            'incomplete' => 14,
        ];

        $this->membersRepository
            ->expects($this->once())
            ->method('countDataGaps')
            ->willReturn($counts);

        $this->assertSame($counts, $this->membersService->getDataCompleteness());
    }

    public function test_updateMember_converts_is_active_to_an_int(): void
    {
        // PDO turns a bound `false` into an empty string, which MariaDB then
        // stores as 0 only by coercion.
        $this->membersRepository->method('findById')->willReturn($this->member('member-1'));

        $this->membersRepository
            ->expects($this->once())
            ->method('updateById')
            ->with('member-1', ['is_active' => 0])
            ->willReturn($this->member('member-1', ['is_active' => 0]));

        $this->membersService->updateMember('member-1', ['is_active' => false]);
    }

    public function test_updateMember_throws_notFoundException_when_missing(): void
    {
        $this->membersRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->membersService->updateMember('missing', ['first_name' => 'Erika']);
    }

    /**
     * Email is required at application level for an active member (#362): the
     * pre-notification and settlement statement emails are a contractual
     * promise (Nutzungsordnung § 7). The column itself stays nullable — this
     * is an app-level rule, not a DB one — so it is enforced here rather than
     * with a NOT NULL constraint that would also break anonymization.
     */
    public function test_updateMember_rejects_clearing_email_for_an_active_member(): void
    {
        $this->membersRepository->method('findById')->willReturn($this->member('member-1', ['is_active' => 1]));

        $this->membersRepository->expects($this->never())->method('updateById');

        try {
            $this->membersService->updateMember('member-1', ['email' => null]);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->getErrors());
        }
    }

    /** A blank string means the same as `null` here — neither may reach the row. */
    public function test_updateMember_rejects_a_blank_string_email_for_an_active_member(): void
    {
        $this->membersRepository->method('findById')->willReturn($this->member('member-1', ['is_active' => 1]));

        $this->membersRepository->expects($this->never())->method('updateById');

        $this->expectException(ValidationException::class);

        $this->membersService->updateMember('member-1', ['email' => '']);
    }

    /**
     * An inactive member is not bound by the contractual-promise rule above —
     * deactivating first (or anonymizing, which is a separate write entirely)
     * is how a member is meant to lose their email.
     */
    public function test_updateMember_allows_clearing_email_for_an_already_inactive_member(): void
    {
        $this->membersRepository->method('findById')->willReturn($this->member('member-1', ['is_active' => 0]));

        $this->membersRepository->expects($this->once())
            ->method('updateById')
            ->with('member-1', ['email' => null])
            ->willReturn($this->member('member-1', ['is_active' => 0, 'email' => null]));

        $this->membersService->updateMember('member-1', ['email' => null]);
    }

    /** Deactivating and clearing the email in the same request is not a conflict. */
    public function test_updateMember_allows_clearing_email_when_deactivating_in_the_same_request(): void
    {
        $this->membersRepository->method('findById')->willReturn($this->member('member-1', ['is_active' => 1]));

        $this->membersRepository->expects($this->once())
            ->method('updateById')
            ->with('member-1', ['email' => null, 'is_active' => 0])
            ->willReturn($this->member('member-1', ['is_active' => 0, 'email' => null]));

        $this->membersService->updateMember('member-1', ['email' => null, 'is_active' => false]);
    }

    public function test_updateMember_allows_replacing_the_email_of_an_active_member(): void
    {
        $this->membersRepository->method('findById')->willReturn($this->member('member-1', ['is_active' => 1]));

        $this->membersRepository->expects($this->once())
            ->method('updateById')
            ->with('member-1', ['email' => 'new@example.com'])
            ->willReturn($this->member('member-1', ['email' => 'new@example.com']));

        $this->membersService->updateMember('member-1', ['email' => 'new@example.com']);
    }

    public function test_anonymizeMember_throws_notFoundException_when_missing(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->membersService->anonymizeMember('missing-member', 'admin-1');
    }

    public function test_anonymizeMember_refuses_a_member_already_anonymized(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1', ['deleted_at' => '2026-01-01 00:00:00']));

        $this->membersRepository->expects($this->never())->method('anonymize');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already anonymized');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_refuses_an_outstanding_balance(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn($this->member('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(1500);

        $this->membersRepository->expects($this->never())->method('anonymize');
        $this->db->expects($this->never())->method('beginTransaction');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('outstanding balance of €15.00');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_refuses_an_outstanding_credit_balance(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn($this->member('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(-1500);

        $this->membersRepository->expects($this->never())->method('anonymize');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('outstanding balance of -€15.00');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_refuses_a_member_in_an_active_settlement(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn($this->member('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(true);

        $this->membersRepository->expects($this->never())->method('anonymize');
        $this->db->expects($this->never())->method('beginTransaction');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('active settlement');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_succeeds_with_a_zero_balance_and_no_active_settlement(): void
    {
        $before = $this->member('member-1');
        $after = $this->member('member-1', [
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'phone' => null,
            'card_uid' => 'ANON-abc123',
            'account_holder_name' => null,
            'is_active' => 0,
            'deleted_at' => '2026-08-08 12:00:00',
        ]);

        $this->membersRepository->expects($this->exactly(2))
            ->method('findByIdIncludingDeleted')
            ->willReturnOnConsecutiveCalls($before, $after);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);

        $this->membersRepository->expects($this->once())
            ->method('anonymize')
            ->with('member-1', 'admin-1')
            ->willReturn(true);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $result = $this->membersService->anonymizeMember('member-1', 'admin-1');

        $this->assertNull($result->firstName);
        $this->assertNull($result->lastName);
        $this->assertNull($result->email);
        $this->assertSame('2026-08-08 12:00:00', $result->deletedAt);
    }

    public function test_anonymizeMember_scrubs_prior_audit_history(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(true);

        // Keyed on the member's id alone: an entry filed under the wrong
        // entity type is exactly the one an erasure must not skip (#115).
        $this->auditLogRepository->expects($this->once())
            ->method('scrubByEntityId')
            ->with('member-1');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    /**
     * A member has to be live to be anonymized, so nothing had ever written
     * `deleted_by_admin_id` by the time the erasure ran and it stayed NULL —
     * the one irreversible action in the system with no actor on the record
     * (#115). The admin who erased is passed down to the row.
     */
    public function test_anonymizeMember_records_the_admin_who_erased_on_the_member_row(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);

        $this->membersRepository->expects($this->once())
            ->method('anonymize')
            ->with('member-1', 'admin-1')
            ->willReturn(true);

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_writes_a_pii_free_audit_entry(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(true);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->anything(),
                $this->anything(),
                'member-1',
                null,
                $this->callback(function (?array $newValues): bool {
                    // Only the anonymization timestamp is logged — no name, email,
                    // phone, iban, or any other PII may leak into the audit trail.
                    return $newValues === ['deleted_at' => '2026-08-08 12:00:00'];
                }),
                'admin-1',
            );

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_rolls_back_and_rethrows_when_the_repository_reports_failure(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn($this->member('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(false);

        $this->auditLogRepository->expects($this->never())->method('scrubByEntityId');
        $this->auditService->expects($this->never())->method('log');

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Anonymization failed');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    /**
     * A crash between nulling the member's PII and scrubbing its audit
     * history must not leave a half-anonymized member: the row's PII is
     * gone but old audit entries (and the anonymization record itself)
     * still reference it in plain text. The write must roll back as one
     * unit.
     */
    public function test_anonymizeMember_rolls_back_the_member_row_when_scrubbing_audit_history_fails(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn($this->member('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);

        $this->membersRepository->expects($this->once())->method('anonymize')->willReturn(true);

        $this->auditLogRepository->method('scrubByEntityId')
            ->willThrowException(new \RuntimeException('database gone away'));

        $this->auditService->expects($this->never())->method('log');

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database gone away');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_rolls_back_when_writing_the_audit_entry_fails(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(true);

        $this->auditService->method('log')->willThrowException(new \RuntimeException('audit write failed'));

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }
}
