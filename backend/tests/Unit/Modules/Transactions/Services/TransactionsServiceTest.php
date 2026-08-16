<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Services;

use App\Modules\Transactions\Services\TransactionsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\Transactions\DTOs\TransactionBatchResultDto;
use App\Modules\Transactions\Exceptions\CannotStornoAStornoException;
use App\Modules\Transactions\Exceptions\TransactionAlreadyStornoedException;
use App\Modules\Transactions\Exceptions\TransactionNotStorableException;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class TransactionsServiceTest extends TestCase
{
    private TransactionsRepository $transactionsRepository;
    private MembersRepository $membersRepository;
    private ProductsRepository $productsRepository;
    private AuditService $auditService;
    private Logger $logger;
    private TransactionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->productsRepository = $this->createMock(ProductsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->logger = $this->createMock(Logger::class);

        $this->service = new TransactionsService(
            $this->transactionsRepository,
            $this->membersRepository,
            $this->productsRepository,
            $this->auditService,
            $this->logger,
        );
    }

    private function sepaValidMember(string $id): array
    {
        return [
            'id' => $id,
            // What repository reads expose since ADR-0036 — presence, not the IBAN.
            'has_iban' => 1,
            'iban_last4' => '3000',
            'mandate_reference' => 'MANDATE-' . $id,
        ];
    }

    // ------------------------------------------------------------------
    // processBatch
    // ------------------------------------------------------------------

    public function test_processBatch_happy_path_accepts_transaction_and_returns_balance(): void
    {
        $memberId = 'member-1';
        $tx = ['id' => 'tx-1', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 500, 'created_at' => '2026-08-08T18:00:00Z'];

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
        $tx = ['id' => 'tx-1', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 300, 'created_at' => '2026-08-08T18:00:00Z'];

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
        $tx = ['id' => 'tx-dup', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 300, 'created_at' => '2026-08-08T18:00:00Z'];

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
        $tx = ['id' => 'tx-unstorable', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 350, 'created_at' => '2026-08-08T18:00:00Z'];

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
        $goodTx = ['id' => 'tx-good', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 100, 'created_at' => '2026-08-08T18:00:00Z'];
        $badTx = ['id' => 'tx-refused', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 200, 'created_at' => '2026-08-08T18:00:00Z'];

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
        $tx = ['id' => 'tx-transient', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 350, 'created_at' => '2026-08-08T18:00:00Z'];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));

        $this->transactionsRepository->method('insertTransaction')
            ->willThrowException(new \PDOException('server has gone away'));

        $this->expectException(\PDOException::class);

        $this->service->processBatch([$tx]);
    }

    public function test_processBatch_mixed_new_and_duplicate_entries(): void
    {
        $memberId = 'member-1';
        $newTx = ['id' => 'tx-new', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 100, 'created_at' => '2026-08-08T18:00:00Z'];
        $dupTx = ['id' => 'tx-dup', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 200, 'created_at' => '2026-08-08T18:00:00Z'];

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
        $tx = ['id' => 'tx-1', 'member_id' => 'missing-member', 'product_id' => 'product-1', 'amount_cents' => 100, 'created_at' => '2026-08-08T18:00:00Z'];

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

    /**
     * #162 / ruling #143 §1: the drink was already served, so by sync time the
     * only question is whether the row can be stored. A missing mandate is a
     * billing obstacle, not a storage one — rejecting here would destroy the
     * record of a sale that happened and bill nobody for it.
     */
    public function test_processBatch_stores_and_accepts_entry_when_member_lacks_sepa_mandate(): void
    {
        $tx = ['id' => 'tx-1', 'member_id' => 'member-1', 'product_id' => 'product-1', 'amount_cents' => 100, 'created_at' => '2026-08-08T18:00:00Z'];

        $this->membersRepository->method('findById')->willReturn([
            'id' => 'member-1',
            'iban' => null,
            'mandate_reference' => null,
        ]);
        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($tx)
            ->willReturn(array_merge($tx, ['created_at' => '2026-01-01 10:00:00']));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(-100);

        $result = $this->service->processBatch([$tx]);

        $this->assertSame(['tx-1'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame([], $result->errors);
        $this->assertSame(['member-1' => -100], $result->memberBalances);
    }

    /**
     * Ruling #143's test contract, item 1 — and the whole point of #259.
     *
     * A batch of 100 with one malformed row stores 99 and returns that row as a
     * permanent rejection. Until this shipped, the malformed row refused the
     * entire batch with a 422; the terminal, which treats a non-2xx as
     * transient, resent the identical batch forever and never reached the
     * quarantine path, so the 99 good sales were never collected.
     */
    public function test_processBatch_stores_the_rest_of_the_batch_around_one_unstorable_row(): void
    {
        $memberId = 'member-1';

        $batch = [];
        for ($i = 0; $i < 99; $i++) {
            $batch[] = [
                'id' => "tx-{$i}",
                'member_id' => $memberId,
                'product_id' => 'product-1',
                'amount_cents' => 350,
                'created_at' => '2026-08-08T18:00:00Z',
            ];
        }
        // The row that used to take the other 99 down with it: no id, so it can
        // never be deduplicated and can never become storable.
        $batch[] = [
            'member_id' => $memberId,
            'product_id' => 'product-1',
            'amount_cents' => 350,
            'created_at' => '2026-08-08T18:00:00Z',
        ];

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember($memberId));
        $this->transactionsRepository->expects($this->exactly(99))
            ->method('insertTransaction')
            ->willReturn(['id' => 'stored']);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(34650);

        $result = $this->service->processBatch($batch);

        $this->assertCount(99, $result->acceptedIds);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame('unstorable', $result->errors[0]['error']);
        $this->assertNull($result->errors[0]['transaction_id']);
    }

    public function test_processBatch_partial_failure_mixes_accepted_and_rejected(): void
    {
        $memberId = 'member-1';
        $goodTx = ['id' => 'tx-good', 'member_id' => $memberId, 'product_id' => 'product-1', 'amount_cents' => 100, 'created_at' => '2026-08-08T18:00:00Z'];
        $badTx = ['id' => 'tx-bad', 'member_id' => 'missing-member', 'product_id' => 'product-1', 'amount_cents' => 100, 'created_at' => '2026-08-08T18:00:00Z'];

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
    // processBatch — asking for a balance without selling anything (#191)
    // ------------------------------------------------------------------

    public function test_processBatch_reports_balances_for_requested_members_with_an_empty_batch(): void
    {
        // The terminal after a settlement: nothing to upload, but the member is
        // standing at the card reader and their tab is now zero.
        $this->membersRepository->method('findById')
            ->with('member-1')
            ->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->expects($this->never())->method('insertTransaction');
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')
            ->with('member-1')
            ->willReturn(0);

        $result = $this->service->processBatch([], ['member-1']);

        $this->assertSame([], $result->acceptedIds);
        $this->assertSame(['member-1' => 0], $result->memberBalances);
    }

    public function test_processBatch_omits_a_requested_member_the_backend_does_not_know(): void
    {
        // A phantom 0 would read to the terminal as "owes nothing" and overwrite
        // a real cached balance. An absent key leaves the cache alone.
        $this->membersRepository->method('findById')->willReturn(null);
        $this->transactionsRepository->expects($this->never())->method('getUnsettledMemberBalanceCents');

        $result = $this->service->processBatch([], ['ghost-member']);

        $this->assertSame([], $result->memberBalances);
    }

    public function test_processBatch_does_not_report_a_requested_member_twice(): void
    {
        $this->membersRepository->method('findById')
            ->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('insertTransaction')->willReturn(['id' => 'tx-1']);
        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with('member-1')
            ->willReturn(350);

        $tx = [
            'id' => 'tx-1',
            'member_id' => 'member-1',
            'product_id' => 'product-1',
            'amount_cents' => 350,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $result = $this->service->processBatch([$tx], ['member-1']);

        $this->assertSame(['member-1' => 350], $result->memberBalances);
    }

    // ------------------------------------------------------------------
    // processBatch — implausible sale times (#79, ruling #144 §2)
    //
    // occurred_at is terminal-owned and stays exactly as sent: clamping it
    // would make a dead clock battery indistinguishable from a probing
    // attacker, and rejecting would cost the club an evening's revenue over a
    // hardware fault (the failure mode ruling #143 forbids). The server's
    // answer is a flag, and received_at is the unforgeable anchor beside it.
    // ------------------------------------------------------------------

    private function saleAt(string $occurredAt): array
    {
        return [
            'id' => 'tx-1',
            'member_id' => 'member-1',
            'product_id' => 'product-1',
            'amount_cents' => 350,
            'created_at' => $occurredAt,
        ];
    }

    private function expectAccepted(array $tx): void
    {
        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('insertTransaction')->willReturn($tx);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(350);
    }

    public function test_processBatch_flags_a_sale_time_absurdly_far_in_the_past(): void
    {
        $tx = $this->saleAt('2019-01-02T03:04:05Z');
        $this->expectAccepted($tx);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('implausible sale time'),
                $this->callback(fn (array $ctx) => $ctx['transaction_id'] === 'tx-1'
                    && $ctx['occurred_at'] === '2019-01-02T03:04:05Z'),
            );

        $result = $this->service->processBatch([$tx]);

        // Flagged, not refused.
        $this->assertSame(['tx-1'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
    }

    public function test_processBatch_flags_a_sale_time_in_the_far_future(): void
    {
        $tx = $this->saleAt((new \DateTimeImmutable('+30 days'))->format(DATE_ATOM));
        $this->expectAccepted($tx);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('implausible sale time'), $this->anything());

        $result = $this->service->processBatch([$tx]);

        $this->assertSame(['tx-1'], $result->acceptedIds);
    }

    public function test_processBatch_stores_an_implausible_sale_time_exactly_as_sent(): void
    {
        $tx = $this->saleAt('2019-01-02T03:04:05Z');

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(350);

        // Never rewritten on its way to the insert — ADR-0004's ledger records
        // what the terminal claimed, and the flag sits beside it.
        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($this->callback(fn (array $row) => $row['created_at'] === '2019-01-02T03:04:05Z'))
            ->willReturn($tx);

        $this->service->processBatch([$tx]);
    }

    public function test_processBatch_does_not_flag_a_sale_time_from_an_offline_weekend(): void
    {
        // ADR-0012: a batch legitimately arrives days late carrying real sale
        // times. Flagging those would drown the real signal.
        $tx = $this->saleAt((new \DateTimeImmutable('-3 days'))->format(DATE_ATOM));
        $this->expectAccepted($tx);

        $this->logger->expects($this->never())->method('warning');

        $result = $this->service->processBatch([$tx]);

        $this->assertSame(['tx-1'], $result->acceptedIds);
    }

    public function test_processBatch_does_not_flag_a_sale_time_a_few_minutes_ahead(): void
    {
        // Ordinary terminal clock skew, not a claim about the future.
        $tx = $this->saleAt((new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM));
        $this->expectAccepted($tx);

        $this->logger->expects($this->never())->method('warning');

        $this->service->processBatch([$tx]);
    }

    public function test_processBatch_does_not_flag_an_unparseable_sale_time(): void
    {
        // Nothing to judge: the database refuses the row and #82 reports it as
        // unstorable, which is a rejection rather than a flag.
        $tx = $this->saleAt('not-a-timestamp');
        $this->expectAccepted($tx);

        $this->logger->expects($this->never())->method('warning');

        $this->service->processBatch([$tx]);
    }

    // ------------------------------------------------------------------
    // processBatch — price divergence (#204, ruling #144 §3)
    //
    // amount_cents is terminal-owned and stored exactly as sent: the terminal
    // charged a price the member saw and accepted, possibly weeks earlier while
    // offline, and a deleted product leaves nothing to recompute from. The
    // server's answer is an audit entry beside the row — never a correction, and
    // never a rejection. These tests exist mostly to hold that last line: the
    // whole risk of this feature is a flag quietly becoming a rejection.
    // ------------------------------------------------------------------

    /** A sale of `product-1` claiming $amountCents, otherwise ordinary. */
    private function saleClaiming(int $amountCents): array
    {
        return [
            'id' => 'tx-1',
            'member_id' => 'member-1',
            'product_id' => 'product-1',
            'created_by_terminal_id' => 'terminal-1',
            'amount_cents' => $amountCents,
            'created_at' => '2026-08-16T12:00:00Z',
        ];
    }

    /** Accepts the row and puts $priceCents (or no product at all) in the catalogue. */
    private function expectAcceptedWithCatalogPrice(array $tx, ?int $priceCents): void
    {
        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('insertTransaction')->willReturn($tx);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn($tx['amount_cents']);
        $this->productsRepository->method('findById')->willReturn(
            $priceCents === null ? null : ['id' => 'product-1', 'price_cents' => $priceCents],
        );
    }

    public function test_processBatch_records_an_amount_that_diverges_from_the_current_price(): void
    {
        $tx = $this->saleClaiming(500);
        $this->expectAcceptedWithCatalogPrice($tx, 350);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::TRANSACTION_PRICE_DIVERGENCE,
                EntityType::TRANSACTION,
                'tx-1',
                null,
                $this->callback(fn (array $values) => $values['amount_cents'] === 500
                    && $values['current_price_cents'] === 350
                    && $values['member_id'] === 'member-1'
                    && $values['product_id'] === 'product-1'
                    && $values['terminal_id'] === 'terminal-1'),
                // No actor: a terminal synced this, no admin acted.
                null,
            );

        $result = $this->service->processBatch([$tx]);

        // Recorded, not refused — and not corrected.
        $this->assertSame(['tx-1'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame([], $result->errors);
    }

    public function test_processBatch_stores_a_divergent_amount_exactly_as_sent(): void
    {
        $tx = $this->saleClaiming(500);

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(500);
        $this->productsRepository->method('findById')->willReturn(['id' => 'product-1', 'price_cents' => 350]);

        // The claimed amount reaches the ledger untouched; the entry sits beside
        // it rather than in place of it.
        $this->transactionsRepository->expects($this->once())
            ->method('insertTransaction')
            ->with($this->callback(fn (array $row) => $row['amount_cents'] === 500))
            ->willReturn($tx);

        $this->service->processBatch([$tx]);
    }

    public function test_processBatch_records_nothing_when_the_amount_matches_the_current_price(): void
    {
        $tx = $this->saleClaiming(350);
        $this->expectAcceptedWithCatalogPrice($tx, 350);

        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->processBatch([$tx]);

        $this->assertSame(['tx-1'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
    }

    public function test_processBatch_accepts_a_sale_for_a_deleted_product_without_recording_a_divergence(): void
    {
        // findById already excludes tombstones, so a deleted product reads as
        // absent. There is nothing to compare against — that is not a
        // divergence, and the sale still happened (ruling #143).
        $tx = $this->saleClaiming(500);
        $this->expectAcceptedWithCatalogPrice($tx, null);

        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->processBatch([$tx]);

        $this->assertSame(['tx-1'], $result->acceptedIds);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame([], $result->errors);
    }

    public function test_processBatch_records_no_divergence_for_a_replayed_transaction(): void
    {
        // A null insert means the id was already stored. The entry was written
        // the first time round; writing it again on every retry would fill the
        // trail with copies of one event.
        $tx = $this->saleClaiming(500);

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('insertTransaction')->willReturn(null);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(500);
        $this->productsRepository->method('findById')->willReturn(['id' => 'product-1', 'price_cents' => 350]);

        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->processBatch([$tx]);

        // Still accepted: the transaction is on the server either way (ADR-0004).
        $this->assertSame(['tx-1'], $result->acceptedIds);
    }

    public function test_processBatch_records_no_divergence_for_a_row_the_database_refused(): void
    {
        // Naming a transaction that exists nowhere would be a lie in an
        // append-only table.
        $tx = $this->saleClaiming(500);

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('insertTransaction')
            ->willThrowException(new TransactionNotStorableException('refused'));
        $this->productsRepository->method('findById')->willReturn(['id' => 'product-1', 'price_cents' => 350]);

        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->processBatch([$tx]);

        $this->assertSame([], $result->acceptedIds);
        $this->assertSame(1, $result->rejectedCount);
    }

    public function test_processBatch_looks_a_product_price_up_once_per_batch(): void
    {
        // A 100-row batch is frequently 100 rows for one drink.
        $first = $this->saleClaiming(500);
        $second = ['id' => 'tx-2'] + $this->saleClaiming(500);

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('insertTransaction')->willReturnArgument(0);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(1000);

        $this->productsRepository->expects($this->once())
            ->method('findById')
            ->with('product-1')
            ->willReturn(['id' => 'product-1', 'price_cents' => 350]);

        // Both rows still get their own entry — the divergence is per booking.
        $this->auditService->expects($this->exactly(2))->method('log');

        $result = $this->service->processBatch([$first, $second]);

        $this->assertSame(['tx-1', 'tx-2'], $result->acceptedIds);
    }

    public function test_processBatch_does_not_requery_a_product_that_does_not_exist(): void
    {
        // The cached miss is null, so a lookup guarded by isset() would re-query
        // it for every remaining row of the batch.
        $first = $this->saleClaiming(500);
        $second = ['id' => 'tx-2'] + $this->saleClaiming(500);

        $this->membersRepository->method('findById')->willReturn($this->sepaValidMember('member-1'));
        $this->transactionsRepository->method('insertTransaction')->willReturnArgument(0);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(1000);

        $this->productsRepository->expects($this->once())->method('findById')->willReturn(null);
        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->processBatch([$first, $second]);

        $this->assertSame(['tx-1', 'tx-2'], $result->acceptedIds);
    }

    // ------------------------------------------------------------------
    // storno
    // ------------------------------------------------------------------

    public function test_storno_derives_amount_as_exact_negation_of_original(): void
    {
        $transactionId = 'tx-original';
        $memberId = 'member-1';

        $this->transactionsRepository->expects($this->once())
            ->method('findById')
            ->with($transactionId)
            ->willReturn(['id' => $transactionId, 'member_id' => $memberId, 'transaction_type' => 'purchase', 'amount_cents' => -1500]);

        $this->transactionsRepository->method('findStornoFor')->with($transactionId)->willReturn(null);

        $this->transactionsRepository->expects($this->once())
            ->method('insertStorno')
            ->with($this->callback(function (array $data) use ($memberId, $transactionId) {
                return $data['amount_cents'] === 1500
                    && $data['member_id'] === $memberId
                    && $data['transaction_type'] === 'storno'
                    && $data['related_transaction_id'] === $transactionId
                    && $data['product_id'] === null;
            }))
            ->willReturn([
                'id' => 'storno-id',
                'member_id' => $memberId,
                'amount_cents' => 1500,
                'transaction_type' => 'storno',
                'related_transaction_id' => $transactionId,
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->with($memberId)->willReturn(1500);

        $result = $this->service->storno($transactionId, 'Refund for wrong charge', 'admin-1');

        $this->assertSame(1500, $result['transaction']['amount_cents']);
        $this->assertSame('storno', $result['transaction']['transaction_type']);
        // formatTransactionTimestamps converts created_at to ISO 8601 UTC
        $this->assertSame('2026-01-01T10:00:00Z', $result['transaction']['created_at']);
        $this->assertSame(1500, $result['new_balance_cents']);
    }

    public function test_storno_of_a_negative_amount_original_produces_a_positive_storno(): void
    {
        $transactionId = 'tx-original';
        $memberId = 'member-1';

        $this->transactionsRepository->method('findById')
            ->willReturn(['id' => $transactionId, 'member_id' => $memberId, 'transaction_type' => 'purchase', 'amount_cents' => -1500]);
        $this->transactionsRepository->method('findStornoFor')->willReturn(null);

        $this->transactionsRepository->expects($this->once())
            ->method('insertStorno')
            ->with($this->callback(fn(array $data) => $data['amount_cents'] === 1500))
            ->willReturn([
                'id' => 'storno-id',
                'member_id' => $memberId,
                'amount_cents' => 1500,
                'transaction_type' => 'storno',
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(1500);

        $result = $this->service->storno($transactionId, 'reason');

        $this->assertSame(1500, $result['transaction']['amount_cents']);
    }

    public function test_storno_of_a_positive_amount_original_produces_a_negative_storno(): void
    {
        $transactionId = 'tx-original';
        $memberId = 'member-1';

        $this->transactionsRepository->method('findById')
            ->willReturn(['id' => $transactionId, 'member_id' => $memberId, 'transaction_type' => 'payout', 'amount_cents' => 2000]);
        $this->transactionsRepository->method('findStornoFor')->willReturn(null);

        $this->transactionsRepository->expects($this->once())
            ->method('insertStorno')
            ->with($this->callback(fn(array $data) => $data['amount_cents'] === -2000))
            ->willReturn([
                'id' => 'storno-id',
                'member_id' => $memberId,
                'amount_cents' => -2000,
                'transaction_type' => 'storno',
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(-2000);

        $result = $this->service->storno($transactionId, 'reason');

        $this->assertSame(-2000, $result['transaction']['amount_cents']);
    }

    public function test_storno_takes_member_id_from_the_original_not_from_the_caller(): void
    {
        $transactionId = 'tx-original';
        $memberId = 'member-owner';

        $this->transactionsRepository->method('findById')
            ->willReturn(['id' => $transactionId, 'member_id' => $memberId, 'transaction_type' => 'purchase', 'amount_cents' => -500]);
        $this->transactionsRepository->method('findStornoFor')->willReturn(null);

        $this->transactionsRepository->expects($this->once())
            ->method('insertStorno')
            ->with($this->callback(fn(array $data) => $data['member_id'] === $memberId))
            ->willReturn([
                'id' => 'storno-id',
                'member_id' => $memberId,
                'amount_cents' => 500,
                'transaction_type' => 'storno',
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->expects($this->once())
            ->method('getUnsettledMemberBalanceCents')
            ->with($memberId)
            ->willReturn(500);

        $this->service->storno($transactionId, 'reason');
    }

    public function test_storno_throws_not_found_when_transaction_missing(): void
    {
        $this->transactionsRepository->method('findById')->willReturn(null);
        $this->transactionsRepository->expects($this->never())->method('insertStorno');

        $this->expectException(NotFoundException::class);

        $this->service->storno('missing-tx', 'reason');
    }

    public function test_storno_throws_cannot_storno_a_storno_when_target_is_a_storno(): void
    {
        $this->transactionsRepository->method('findById')->willReturn([
            'id' => 'tx-storno',
            'member_id' => 'member-1',
            'transaction_type' => 'storno',
            'amount_cents' => 500,
        ]);
        $this->transactionsRepository->expects($this->never())->method('insertStorno');

        $this->expectException(CannotStornoAStornoException::class);

        $this->service->storno('tx-storno', 'reason');
    }

    public function test_storno_throws_already_stornoed_when_findStornoFor_returns_a_row(): void
    {
        $transactionId = 'tx-original';

        $this->transactionsRepository->method('findById')->willReturn([
            'id' => $transactionId,
            'member_id' => 'member-1',
            'transaction_type' => 'purchase',
            'amount_cents' => -500,
        ]);
        $this->transactionsRepository->expects($this->once())
            ->method('findStornoFor')
            ->with($transactionId)
            ->willReturn(['id' => 'existing-storno-id']);
        $this->transactionsRepository->expects($this->never())->method('insertStorno');

        $this->expectException(TransactionAlreadyStornoedException::class);

        $this->service->storno($transactionId, 'reason');
    }

    public function test_storno_succeeds_for_a_member_with_no_iban_or_mandate(): void
    {
        // No SEPA mandate check at all — a storno reduces debt, so it must not
        // be gated on the member's ability to be billed (#169, ADR-0028 §1).
        $transactionId = 'tx-original';
        $memberId = 'member-no-mandate';

        $this->transactionsRepository->method('findById')->willReturn([
            'id' => $transactionId,
            'member_id' => $memberId,
            'transaction_type' => 'purchase',
            'amount_cents' => -500,
        ]);
        $this->transactionsRepository->method('findStornoFor')->willReturn(null);

        $this->membersRepository->expects($this->never())->method('findById');

        $this->transactionsRepository->expects($this->once())
            ->method('insertStorno')
            ->willReturn([
                'id' => 'storno-id',
                'member_id' => $memberId,
                'amount_cents' => 500,
                'transaction_type' => 'storno',
                'created_at' => '2026-01-01 10:00:00',
            ]);

        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(500);

        $result = $this->service->storno($transactionId, 'reason');

        $this->assertSame(500, $result['transaction']['amount_cents']);
    }

    public function test_storno_logs_an_audit_entry_with_action_entity_type_admin_and_new_values(): void
    {
        $transactionId = 'tx-original';
        $memberId = 'member-1';
        $adminId = 'admin-1';
        $reason = 'Wrong drink charged';

        $this->transactionsRepository->method('findById')->willReturn([
            'id' => $transactionId,
            'member_id' => $memberId,
            'transaction_type' => 'purchase',
            'amount_cents' => -700,
        ]);
        $this->transactionsRepository->method('findStornoFor')->willReturn(null);
        $this->transactionsRepository->method('insertStorno')->willReturn([
            'id' => 'storno-id',
            'member_id' => $memberId,
            'amount_cents' => 700,
            'transaction_type' => 'storno',
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $this->transactionsRepository->method('getUnsettledMemberBalanceCents')->willReturn(700);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::TRANSACTION_STORNO,
                EntityType::TRANSACTION,
                'storno-id',
                null,
                $this->callback(function (array $newValues) use ($transactionId, $reason) {
                    return $newValues['related_transaction_id'] === $transactionId
                        && $newValues['reason'] === $reason;
                }),
                $adminId,
            );

        $this->service->storno($transactionId, $reason, $adminId);
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
