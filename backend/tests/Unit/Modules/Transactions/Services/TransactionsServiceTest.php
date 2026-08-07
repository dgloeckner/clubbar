<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Services;

use App\Modules\Transactions\Services\TransactionsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\Transactions\DTOs\TransactionBatchResultDto;
use App\Modules\Transactions\Exceptions\TransactionNotStorableException;
use App\Modules\Members\Repositories\MembersRepository;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\SepaValidationException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;

class TransactionsServiceTest extends TestCase
{
    private TransactionsRepository $transactionsRepository;
    private MembersRepository $membersRepository;
    private Logger $logger;
    private TransactionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->logger = $this->createMock(Logger::class);

        $this->service = new TransactionsService(
            $this->transactionsRepository,
            $this->membersRepository,
            $this->logger,
        );
    }

    private function sepaValidMember(string $id): array
    {
        return [
            'id' => $id,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE-' . $id,
        ];
    }

    // ------------------------------------------------------------------
    // processBatch
    // ------------------------------------------------------------------

    public function test_processBatch_happy_path_accepts_transaction_and_returns_balance(): void
    {
        $memberId = 'member-1';
        $tx = ['id' => 'tx-1', 'member_id' => $memberId, 'amount_cents' => -500];

        $this->membersRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn($this->sepaValidMember($memberId));

        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($tx)
            ->willReturn(array_merge($tx, ['created_at' => '2026-01-01 10:00:00']));

        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with($memberId)
            ->willReturn(-500);

        $result = $this->service->processBatch([$tx]);

        $this->assertInstanceOf(TransactionBatchResultDto::class, $result);
        $this->assertSame(['tx-1'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame([], $result->errors);
        $this->assertSame([$memberId => -500], $result->memberBalances);
    }

    public function test_processBatch_reports_the_unsettled_position_not_the_lifetime_sum(): void
    {
        // #83: the balance the terminal caches and shows as the member's Deckel
        // is their *unsettled* position (ruling #141). A lifetime sum over every
        // transaction ever booked ignores settlement runs and grows forever, so
        // the member is shown a Deckel they have already paid.
        $memberId = 'member-1';
        $tx = ['id' => 'tx-1', 'member_id' => $memberId, 'amount_cents' => 300];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));
        $this->transactionsRepository->method('insertTransaction')
            ->willReturn(array_merge($tx, ['created_at' => '2026-01-01 10:00:00']));

        // The member has 1100 booked over their lifetime, of which 800 has been
        // swept into a settlement. Only the remaining 300 is still owed.
        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with($memberId)
            ->willReturn(300);

        $result = $this->service->processBatch([$tx]);

        $this->assertSame([$memberId => 300], $result->memberBalances);
    }

    public function test_processBatch_duplicate_client_uuid_is_idempotent_not_double_booked(): void
    {
        // Core offline-first guarantee (ADR-0004): resubmitting a batch containing
        // an already-persisted transaction id must NOT create a second row, and
        // must still be reported back to the client as accepted so retries succeed.
        $memberId = 'member-1';
        $tx = ['id' => 'tx-dup', 'member_id' => $memberId, 'amount_cents' => -300];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));

        // Repository signals "duplicate" by returning null (INSERT IGNORE, rowCount 0)
        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($tx)
            ->willReturn(null);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(-300);

        $result = $this->service->processBatch([$tx]);

        // Only a single accepted id is reported — no duplication in the response,
        // and insertTransaction (which uses INSERT IGNORE at the DB layer) was
        // called exactly once, never twice, for this single-entry batch.
        $this->assertSame(['tx-dup'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame([], $result->errors);
    }

    /**
     * Issue #82 — the sale-loss case. A row the database refuses must come
     * back as *rejected*; reporting it as accepted is what let the terminal
     * purge a served drink from its offline queue with no record anywhere.
     */
    public function test_processBatch_rejects_an_entry_the_database_refuses(): void
    {
        $memberId = 'member-1';
        $tx = ['id' => 'tx-unstorable', 'member_id' => $memberId, 'amount_cents' => 350];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));

        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($tx)
            ->willThrowException(new TransactionNotStorableException('refused'));

        $result = $this->service->processBatch([$tx]);

        $this->assertSame([], $result->acceptedIds, 'A refused row is never reported as accepted');
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame('unstorable', $result->errors[0]['error']);
        $this->assertSame('tx-unstorable', $result->errors[0]['transaction_id']);
    }

    /**
     * A refusal is per-row. The rest of the batch is unaffected — one bad
     * entry must not cost the terminal the sales that surround it.
     */
    public function test_processBatch_accepts_the_rest_of_the_batch_around_a_refused_entry(): void
    {
        $memberId = 'member-1';
        $goodTx = ['id' => 'tx-good', 'member_id' => $memberId, 'amount_cents' => 100];
        $badTx = ['id' => 'tx-refused', 'member_id' => $memberId, 'amount_cents' => 200];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));

        $this->transactionsRepository->method('insertTransaction')
            ->willReturnCallback(function (array $tx) use ($goodTx) {
                if ($tx['id'] === $goodTx['id']) {
                    return array_merge($goodTx, ['created_at' => '2026-08-07 10:00:00']);
                }
                throw new TransactionNotStorableException('refused');
            });

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(100);

        $result = $this->service->processBatch([$goodTx, $badTx]);

        $this->assertSame(['tx-good'], $result->acceptedIds);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame([$memberId => 100], $result->memberBalances);
    }

    /**
     * A transient database failure is not a rejection. The terminal should
     * retry the whole batch, so the exception must reach the error handler
     * rather than be recorded as a per-row verdict.
     */
    public function test_processBatch_propagates_a_transient_database_failure(): void
    {
        $memberId = 'member-1';
        $tx = ['id' => 'tx-transient', 'member_id' => $memberId, 'amount_cents' => 350];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));

        $this->transactionsRepository->method('insertTransaction')
            ->willThrowException(new \PDOException('server has gone away'));

        $this->expectException(\PDOException::class);

        $this->service->processBatch([$tx]);
    }

    public function test_processBatch_mixed_new_and_duplicate_entries(): void
    {
        $memberId = 'member-1';
        $newTx = ['id' => 'tx-new', 'member_id' => $memberId, 'amount_cents' => -100];
        $dupTx = ['id' => 'tx-dup', 'member_id' => $memberId, 'amount_cents' => -200];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));

        $this->transactionsRepository->expects($this->exactly(2))
            ->method('insertTransaction')
            ->willReturnMap([
                [$newTx, array_merge($newTx, ['created_at' => '2026-01-01 10:00:00'])],
                [$dupTx, null],
            ]);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(-300);

        $result = $this->service->processBatch([$newTx, $dupTx]);

        $this->assertSame(['tx-new', 'tx-dup'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
        // The balance is only queried once per distinct affected member,
        // even though two transactions in the batch touched that member.
        $this->assertSame([$memberId => -300], $result->memberBalances);
    }

    public function test_processBatch_rejects_entry_with_missing_member_id(): void
    {
        $tx = ['id' => 'tx-1', 'amount_cents' => -100];

        $this->membersRepository->expects($this->never())->method('findById');
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');

        $result = $this->service->processBatch([$tx]);

        $this->assertSame([], $result->acceptedIds);
        $this->assertSame(1, $result->rejectedCount);
        // Every rejection names the transaction under the same key, so the
        // terminal can read one contract rather than three.
        $this->assertSame(
            [[
                'error' => 'unstorable',
                'transaction_id' => 'tx-1',
                'message' => 'member_id is required',
            ]],
            $result->errors,
        );
        $this->assertSame([], $result->memberBalances);
    }

    public function test_processBatch_rejects_entry_when_member_not_found(): void
    {
        $tx = ['id' => 'tx-1', 'member_id' => 'missing-member', 'amount_cents' => -100];

        $this->membersRepository->method('findById')->with('missing-member')->willReturn(null);
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');

        $result = $this->service->processBatch([$tx]);

        $this->assertSame([], $result->acceptedIds);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame(
            [['error' => 'not_found', 'transaction_id' => 'tx-1', 'message' => 'Member not found']],
            $result->errors,
        );
    }

    public function test_processBatch_rejects_entry_when_member_lacks_sepa_mandate(): void
    {
        $tx = ['id' => 'tx-1', 'member_id' => 'member-1', 'amount_cents' => -100];

        $this->membersRepository->method('findById')->willReturn([
            'id' => 'member-1',
            'iban' => null,
            'mandate_reference' => null,
        ]);
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');

        $result = $this->service->processBatch([$tx]);

        $this->assertSame([], $result->acceptedIds);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame(
            [[
                'error' => 'sepa_invalid',
                'transaction_id' => 'tx-1',
                'message' => 'SEPA mandate is required to process transactions for this member',
            ]],
            $result->errors,
        );
    }

    public function test_processBatch_partial_failure_mixes_accepted_and_rejected(): void
    {
        $memberId = 'member-1';
        $goodTx = ['id' => 'tx-good', 'member_id' => $memberId, 'amount_cents' => -100];
        $badTx = ['id' => 'tx-bad', 'member_id' => 'missing-member', 'amount_cents' => -100];

        $this->membersRepository->method('findById')
            ->willReturnMap([
                [$memberId, $this->sepaValidMember($memberId)],
                ['missing-member', null],
            ]);

        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($goodTx)
            ->willReturn(array_merge($goodTx, ['created_at' => '2026-01-01 10:00:00']));

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->with($memberId)->willReturn(-100);

        $result = $this->service->processBatch([$goodTx, $badTx]);

        $this->assertSame(['tx-good'], $result->acceptedIds);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame(
            [['error' => 'not_found', 'transaction_id' => 'tx-bad', 'message' => 'Member not found']],
            $result->errors,
        );
        $this->assertSame([$memberId => -100], $result->memberBalances);
    }

    public function test_processBatch_empty_batch_returns_empty_result(): void
    {
        $this->membersRepository->expects($this->never())->method('findById');
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');
        $this->transactionsRepository->expects($this->never())->method('getUnsettledMemberBalanceCents');

        $result = $this->service->processBatch([]);

        $this->assertSame([], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame([], $result->errors);
        $this->assertSame([], $result->memberBalances);
    }

    // ------------------------------------------------------------------
    // recordCorrection
    // ------------------------------------------------------------------

    public function test_recordCorrection_creates_transaction_with_type_storno_and_given_amount(): void
    {
        // NOTE: actual behaviour — this service does NOT compute a reversal/inverse
        // amount and does NOT write a separate audit-log entry (no AuditService is
        // injected into this class at all). It records a single new immutable row
        // with transaction_type "storno" using amountCents exactly as passed by
        // the caller (sign convention is the caller's responsibility; deriving the
        // amount from the original transaction is deferred to issue #169). What IS
        // enforced is the linkage: relatedTransactionId is mandatory and must name
        // an existing transaction belonging to the same member.
        $memberId = 'member-1';
        $relatedTransactionId = 'tx-original';

        $this->membersRepository->expects($this->once())
            ->method('findById')
            ->with($memberId)
            ->willReturn($this->sepaValidMember($memberId));

        $this->transactionsRepository->expects($this->once())
            ->method('findById')
            ->with($relatedTransactionId)
            ->willReturn(['id' => $relatedTransactionId, 'member_id' => $memberId, 'amount_cents' => 1500]);

        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($this->callback(function (array $data) use ($memberId, $relatedTransactionId) {
                return $data['member_id'] === $memberId
                    && $data['amount_cents'] === -1500
                    && $data['transaction_type'] === 'storno'
                    && $data['notes'] === 'Refund for wrong charge'
                    && $data['product_id'] === null
                    && $data['created_by_admin_id'] === 'admin-1'
                    && $data['related_transaction_id'] === $relatedTransactionId
                    && is_string($data['id']) && $data['id'] !== ''
                    && is_string($data['created_at']);
            }))
            ->willReturn([
                'id' => 'generated-id',
                'member_id' => $memberId,
                'amount_cents' => -1500,
                'transaction_type' => 'storno',
                'notes' => 'Refund for wrong charge',
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with($memberId)
            ->willReturn(-1500);

        $result = $this->service->recordCorrection($memberId, -1500, 'Refund for wrong charge', 'admin-1', $relatedTransactionId);

        $this->assertSame(-1500, $result['transaction']['amount_cents']);
        $this->assertSame('storno', $result['transaction']['transaction_type']);
        // formatTransactionTimestamps converts created_at to ISO 8601 UTC
        $this->assertSame('2026-01-01T10:00:00Z', $result['transaction']['created_at']);
        $this->assertSame(-1500, $result['new_balance_cents']);
    }

    public function test_recordCorrection_reports_the_unsettled_position_not_the_lifetime_sum(): void
    {
        // #83: new_balance_cents is what the Kassenwart reads back after a
        // storno. It must be the member's unsettled position (ruling #141), not
        // a lifetime sum that still counts transactions already collected.
        $memberId = 'member-1';
        $relatedTransactionId = 'tx-original';

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));
        $this->transactionsRepository->method('findById')
            ->with($relatedTransactionId)
            ->willReturn(['id' => $relatedTransactionId, 'member_id' => $memberId, 'amount_cents' => 500]);
        $this->transactionsRepository->method('insertTransaction')->willReturn([
            'id' => 'generated-id',
            'member_id' => $memberId,
            'amount_cents' => -500,
            'transaction_type' => 'storno',
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with($memberId)
            ->willReturn(-500);

        $result = $this->service->recordCorrection($memberId, -500, 'Wrong drink', 'admin-1', $relatedTransactionId);

        $this->assertSame(-500, $result['new_balance_cents']);
    }

    public function test_recordCorrection_accepts_zero_amount_and_records_it_as_is(): void
    {
        // NOTE: actual behaviour — no guard against a zero-amount correction;
        // the service happily records it verbatim (deriving the amount from the
        // original transaction is deferred to issue #169), as long as the
        // mandatory linkage to the reversed transaction is present.
        $memberId = 'member-1';
        $relatedTransactionId = 'tx-original';

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));
        $this->transactionsRepository->method('findById')
            ->with($relatedTransactionId)
            ->willReturn(['id' => $relatedTransactionId, 'member_id' => $memberId, 'amount_cents' => 0]);

        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($this->callback(fn(array $data) => $data['amount_cents'] === 0))
            ->willReturn([
                'id' => 'generated-id',
                'member_id' => $memberId,
                'amount_cents' => 0,
                'transaction_type' => 'storno',
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(0);

        $result = $this->service->recordCorrection($memberId, 0, 'No-op correction', null, $relatedTransactionId);

        $this->assertSame(0, $result['transaction']['amount_cents']);
        $this->assertSame(0, $result['new_balance_cents']);
    }

    public function test_recordCorrection_accepts_positive_amount_and_records_it_as_is(): void
    {
        // NOTE: actual behaviour — a positive correction amount is recorded
        // verbatim too; the service applies no sign normalization at all
        // (deriving the amount from the original transaction is deferred to
        // issue #169), but the mandatory linkage still applies.
        $memberId = 'member-1';
        $relatedTransactionId = 'tx-original';

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));
        $this->transactionsRepository->method('findById')
            ->with($relatedTransactionId)
            ->willReturn(['id' => $relatedTransactionId, 'member_id' => $memberId, 'amount_cents' => -2000]);

        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($this->callback(fn(array $data) => $data['amount_cents'] === 2000))
            ->willReturn([
                'id' => 'generated-id',
                'member_id' => $memberId,
                'amount_cents' => 2000,
                'transaction_type' => 'storno',
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(2000);

        $result = $this->service->recordCorrection($memberId, 2000, 'Goodwill credit', null, $relatedTransactionId);

        $this->assertSame(2000, $result['transaction']['amount_cents']);
    }

    public function test_recordCorrection_throws_not_found_when_member_missing(): void
    {
        $this->membersRepository->method('findById')->willReturn(null);
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');

        $this->expectException(NotFoundException::class);

        $this->service->recordCorrection('missing-member', -100, 'reason');
    }

    public function test_recordCorrection_throws_sepa_validation_exception_when_mandate_missing(): void
    {
        $this->membersRepository->method('findById')->willReturn([
            'id' => 'member-1',
            'iban' => null,
            'mandate_reference' => null,
        ]);
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');

        $this->expectException(SepaValidationException::class);

        $this->service->recordCorrection('member-1', -100, 'reason');
    }

    public function test_recordCorrection_throws_validation_exception_when_related_transaction_id_missing(): void
    {
        // GoBD Rz. 64 requires a storno to explicitly name the transaction it
        // reverses; a free-amount adjustment with no linkage is no longer permitted.
        $memberId = 'member-1';

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));
        $this->transactionsRepository->expects($this->never())->method('findById');
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');

        $this->expectException(ValidationException::class);

        $this->service->recordCorrection($memberId, -100, 'reason', null, null);
    }

    public function test_recordCorrection_throws_not_found_when_related_transaction_belongs_to_different_member(): void
    {
        // GoBD Rz. 64 ties a storno to the specific transaction it reverses; a
        // storno must not silently reverse another member's booking.
        $memberId = 'member-1';
        $relatedTransactionId = 'tx-other-member';

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));
        $this->transactionsRepository->expects($this->once())
            ->method('findById')
            ->with($relatedTransactionId)
            ->willReturn(['id' => $relatedTransactionId, 'member_id' => 'member-2', 'amount_cents' => -500]);
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');

        $this->expectException(NotFoundException::class);

        $this->service->recordCorrection($memberId, -100, 'reason', null, $relatedTransactionId);
    }

    // ------------------------------------------------------------------
    // getTransactions
    // ------------------------------------------------------------------

    public function test_getTransactions_passes_sort_key_filters_and_pagination_through_to_repository(): void
    {
        // NOTE: the mapping of sort=member -> m.last_name happens inside
        // TransactionsRepository::listPaginated, not in this service. The
        // service itself just forwards the raw sortKey untouched.
        $filters = ['type' => 'purchase', 'member_id' => 'member-1'];

        $this->transactionsRepository->expects($this->once())
            ->method('listPaginated')
            ->with(25, 50, $filters, 'member', 'asc')
            ->willReturn([
                'items' => [
                    ['id' => 'tx-1', 'created_at' => '2026-01-01 10:00:00'],
                ],
                'total' => 1,
            ]);

        $result = $this->service->getTransactions(25, 50, $filters, 'member', 'asc');

        $this->assertInstanceOf(PaginatedResultDto::class, $result);
        $this->assertSame(1, $result->total);
        $this->assertSame(25, $result->limit);
        $this->assertSame(50, $result->offset);
        $this->assertSame('2026-01-01T10:00:00Z', $result->items[0]['created_at']);
    }

    public function test_getTransactions_uses_default_sort_and_empty_filters_when_omitted(): void
    {
        $this->transactionsRepository->expects($this->once())
            ->method('listPaginated')
            ->with(10, 0, [], 'created_at', 'desc')
            ->willReturn(['items' => [], 'total' => 0]);

        $result = $this->service->getTransactions(10, 0);

        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->items);
    }

    // ------------------------------------------------------------------
    // getMemberTransactionHistory
    // ------------------------------------------------------------------

    public function test_getMemberTransactionHistory_returns_balance_and_transactions_with_type_filter(): void
    {
        $memberId = 'member-1';

        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with($memberId)
            ->willReturn(-750);

        $this->transactionsRepository->expects($this->once())
            ->method('findByMemberId')
            ->with($memberId, 1000, 0, 'correction')
            ->willReturn([
                ['id' => 'tx-1', 'created_at' => '2026-01-01 10:00:00'],
            ]);

        $result = $this->service->getMemberTransactionHistory($memberId, 'correction');

        $this->assertSame($memberId, $result['member_id']);
        $this->assertSame(-750, $result['current_balance_cents']);
        $this->assertCount(1, $result['transactions']);
        $this->assertSame('2026-01-01T10:00:00Z', $result['transactions'][0]['created_at']);
    }

    public function test_getMemberTransactionHistory_reports_the_unsettled_position_not_the_lifetime_sum(): void
    {
        // #83: current_balance_cents heads the member's history in the admin
        // panel. The history below it lists every transaction ever booked, but
        // the figure on top is what the member still owes — their unsettled
        // position (ruling #141), which settlement runs bring back to zero.
        $memberId = 'member-1';

        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with($memberId)
            ->willReturn(300);

        $this->transactionsRepository->method('findByMemberId')->willReturn([]);

        $result = $this->service->getMemberTransactionHistory($memberId);

        $this->assertSame(300, $result['current_balance_cents']);
    }

    // ------------------------------------------------------------------
    // getRecentTransactions
    // ------------------------------------------------------------------

    public function test_getRecentTransactions_passes_limit_offset_and_since_through_no_type_filter(): void
    {
        $memberId = 'member-1';

        $this->transactionsRepository->expects($this->once())
            ->method('findByMemberId')
            ->with($memberId, 20, 5, null, '2026-01-01T00:00:00Z')
            ->willReturn([['id' => 'tx-1']]);

        $result = $this->service->getRecentTransactions($memberId, 20, 5, '2026-01-01T00:00:00Z');

        $this->assertSame([['id' => 'tx-1']], $result);
    }

    public function test_getRecentTransactions_uses_default_limit_offset_and_null_since(): void
    {
        $memberId = 'member-1';

        $this->transactionsRepository->expects($this->once())
            ->method('findByMemberId')
            ->with($memberId, 50, 0, null, null)
            ->willReturn([]);

        $result = $this->service->getRecentTransactions($memberId);

        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // getRecentTransactionsForMember
    // ------------------------------------------------------------------

    public function test_getRecentTransactionsForMember_throws_not_found_when_member_missing(): void
    {
        $this->membersRepository->method('findById')->willReturn(null);
        $this->transactionsRepository->expects($this->never())->method('findByMemberId');

        $this->expectException(NotFoundException::class);

        $this->service->getRecentTransactionsForMember('missing-member');
    }

    public function test_getRecentTransactionsForMember_passes_limit_offset_since_and_normalizes_rows(): void
    {
        $memberId = 'member-1';

        $this->membersRepository->method('findById')->willReturn([
            'id' => $memberId,
            'preferred_language' => 'de',
        ]);

        $this->transactionsRepository->expects($this->once())
            ->method('findByMemberId')
            ->with($memberId, 30, 10, null, '2026-01-01T00:00:00Z')
            ->willReturn([
                [
                    'id' => 'tx-1',
                    'created_at' => '2026-01-01 10:00:00',
                    'transaction_type' => 'purchase',
                    'product_names' => json_encode(['de' => 'Bier', 'en' => 'Beer']),
                ],
            ]);

        $result = $this->service->getRecentTransactionsForMember($memberId, 30, 10, '2026-01-01T00:00:00Z');

        $this->assertCount(1, $result);
        $this->assertSame('2026-01-01T10:00:00Z', $result[0]['created_at']);
        $this->assertSame('purchase', $result[0]['type']);
        $this->assertSame('Bier', $result[0]['product_name']);
    }

    public function test_getRecentTransactionsForMember_falls_back_to_notes_or_type_label_when_no_product(): void
    {
        $memberId = 'member-1';

        $this->membersRepository->method('findById')->willReturn([
            'id' => $memberId,
            'preferred_language' => 'de',
        ]);

        $this->transactionsRepository->method('findByMemberId')->willReturn([
            [
                'id' => 'tx-1',
                'created_at' => '2026-01-01 10:00:00',
                'transaction_type' => 'storno',
                'product_names' => null,
                'notes' => null,
            ],
        ]);

        $result = $this->service->getRecentTransactionsForMember($memberId);

        // No product and no notes -> falls back to the type label for 'storno'
        $this->assertSame('Storno', $result[0]['product_name']);
    }
}
