<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Services\MandateDocumentService;
use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class MembersServiceTest extends TestCase
{
    private MembersRepository $membersRepository;
    private TransactionsRepository $transactionsRepository;
    private AuditService $auditService;
    private AuditLogRepository $auditLogRepository;
    private \PDO $db;
    private MandateDocumentService $mandateDocumentService;
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
        $this->mandateDocumentService = $this->createMock(MandateDocumentService::class);

        // Create service instance
        $this->membersService = new MembersService(
            $this->membersRepository,
            $this->transactionsRepository,
            $this->auditService,
            $this->auditLogRepository,
            $this->db,
            $this->mandateDocumentService,
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

    // ── anonymizeMember: the signed SEPA mandate (#85) ─────

    /**
     * The mandate PDF is the club's legal proof that this member authorized
     * direct debits. A refused anonymization leaves the member fully active,
     * so the proof must survive it untouched.
     */
    public function test_anonymizeMember_keeps_the_mandate_document_when_an_outstanding_balance_blocks_it(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn($this->member('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(1200);

        $this->mandateDocumentService->expects($this->never())->method('deleteRecordForMember');
        $this->mandateDocumentService->expects($this->never())->method('removeStoredFile');

        $this->expectException(BusinessRuleException::class);

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_keeps_the_mandate_document_when_an_active_settlement_blocks_it(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn($this->member('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(true);

        $this->mandateDocumentService->expects($this->never())->method('deleteRecordForMember');
        $this->mandateDocumentService->expects($this->never())->method('removeStoredFile');

        $this->expectException(BusinessRuleException::class);

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_keeps_the_mandate_document_when_the_member_is_already_anonymized(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1', ['deleted_at' => '2026-01-01 00:00:00']));

        $this->mandateDocumentService->expects($this->never())->method('deleteRecordForMember');
        $this->mandateDocumentService->expects($this->never())->method('removeStoredFile');

        $this->expectException(BusinessRuleException::class);

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_keeps_the_mandate_document_when_the_member_does_not_exist(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn(null);

        $this->mandateDocumentService->expects($this->never())->method('deleteRecordForMember');
        $this->mandateDocumentService->expects($this->never())->method('removeStoredFile');

        $this->expectException(NotFoundException::class);

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    public function test_anonymizeMember_deletes_the_mandate_document_once_the_checks_pass(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(true);

        $this->mandateDocumentService->expects($this->once())
            ->method('deleteRecordForMember')
            ->with('member-1', 'admin-1')
            ->willReturn('/storage/mandates/member-1.pdf');

        $this->mandateDocumentService->expects($this->once())
            ->method('removeStoredFile')
            ->with('/storage/mandates/member-1.pdf');

        $this->membersService->anonymizeMember('member-1', 'admin-1');
    }

    /**
     * Deleting the mandate is itself audited, with the document's original
     * filename — which routinely carries the member's name. That entry has to
     * be written before the audit scrub, or the anonymization leaves a fresh
     * PII trail behind it.
     */
    public function test_anonymizeMember_scrubs_the_audit_trail_after_the_mandate_deletion_is_logged(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(true);

        $sequence = [];
        $this->mandateDocumentService->method('deleteRecordForMember')
            ->willReturnCallback(function () use (&$sequence): ?string {
                $sequence[] = 'delete-mandate-record';
                return null;
            });
        $this->auditLogRepository->method('scrubByEntityId')
            ->willReturnCallback(function () use (&$sequence): int {
                $sequence[] = 'scrub-audit-trail';
                return 0;
            });

        $this->membersService->anonymizeMember('member-1', 'admin-1');

        $this->assertSame(['delete-mandate-record', 'scrub-audit-trail'], $sequence);
    }

    /**
     * The record deletion joins the anonymization transaction, but the PDF is
     * unlinked only after the commit — an unlink has no rollback.
     */
    public function test_anonymizeMember_unlinks_the_mandate_pdf_only_after_the_commit(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(true);

        $sequence = [];
        $this->mandateDocumentService->method('deleteRecordForMember')
            ->willReturnCallback(function () use (&$sequence): string {
                $sequence[] = 'delete-record';
                return '/storage/mandates/member-1.pdf';
            });
        $this->db->method('commit')->willReturnCallback(function () use (&$sequence): bool {
            $sequence[] = 'commit';
            return true;
        });
        $this->mandateDocumentService->method('removeStoredFile')
            ->willReturnCallback(function () use (&$sequence): void {
                $sequence[] = 'unlink-pdf';
            });

        $this->membersService->anonymizeMember('member-1', 'admin-1');

        $this->assertSame(['delete-record', 'commit', 'unlink-pdf'], $sequence);
    }

    public function test_anonymizeMember_leaves_the_mandate_pdf_on_disk_when_the_transaction_rolls_back(): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn($this->member('member-1'), $this->member('member-1', ['deleted_at' => '2026-08-08 12:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);
        $this->transactionsRepository->method('hasMemberInActiveSettlement')->willReturn(false);
        $this->membersRepository->method('anonymize')->willReturn(true);
        $this->mandateDocumentService->method('deleteRecordForMember')->willReturn('/storage/mandates/member-1.pdf');

        $this->auditService->method('log')->willThrowException(new \RuntimeException('audit write failed'));

        $this->db->expects($this->once())->method('rollBack');
        // The DELETE rolls back with the rest of the transaction; unlinking the
        // PDF would not have, so it must not have happened.
        $this->mandateDocumentService->expects($this->never())->method('removeStoredFile');

        $this->expectException(\RuntimeException::class);

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
