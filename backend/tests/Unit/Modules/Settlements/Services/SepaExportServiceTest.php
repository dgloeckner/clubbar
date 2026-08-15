<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\DTOs\ExcludedMemberDto;
use App\Modules\Settlements\Enums\SepaExclusionReason;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SepaExportService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;

class SepaExportServiceTest extends TestCase
{
    private SepaConfigRepository $sepaConfigRepository;
    private MembersRepository $membersRepository;
    private SettlementsRepository $settlementsRepository;
    private SettlementReversalsRepository $reversalsRepository;
    private Logger $logger;
    private SepaExportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildService();
    }

    /** Fresh mocks and a fresh service, so one test can run two exports. */
    private function buildService(): void
    {
        $this->sepaConfigRepository = $this->createMock(SepaConfigRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);
        $this->reversalsRepository = $this->createMock(SettlementReversalsRepository::class);
        $this->logger = $this->createMock(Logger::class);

        $this->service = new SepaExportService(
            $this->sepaConfigRepository,
            $this->membersRepository,
            $this->settlementsRepository,
            $this->reversalsRepository,
            $this->logger,
        );
    }

    public function test_export_throws_notFoundException_when_the_settlement_is_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->export('missing-id', self::opener());
    }

    public function test_export_refuses_when_creditor_details_are_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => '2026-08-20',
        ]);
        $this->sepaConfigRepository->method('getConfig')->willReturn([
            'creditor_id' => null,
            'creditor_name' => null,
            'creditor_iban' => null,
            'mandate_template_url' => 'https://club.example/anmeldung',
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('SEPA configuration incomplete');

        $this->service->export(self::SETTLEMENT_ID, self::opener());
    }

    /**
     * #360/#456: the blank mandate form moved out of the app to an
     * externally hosted registration form. A member has nothing to sign
     * until the club has told the system where it lives, so this is refused
     * the same way missing creditor details always were.
     */
    public function test_export_refuses_when_the_mandate_template_url_is_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => '2026-08-20',
        ]);
        $this->sepaConfigRepository->method('getConfig')->willReturn([
            'creditor_id' => 'DE98ZZZ09999999999',
            'creditor_name' => 'Ruderclub',
            'creditor_iban' => 'DE89370400440532013000',
            'mandate_template_url' => null,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('SEPA configuration incomplete');

        $this->service->export(self::SETTLEMENT_ID, self::opener());
    }

    public function test_export_refuses_a_cancelled_settlement_with_an_accurate_error(): void
    {
        // #114 / #142 §5. This never had a guard: cancellation used to delete
        // the items, so the export failed by accident with "Settlement has no
        // items" — and now that the rows survive it would not fail at all, and
        // would instruct the bank to collect a run the club called off.
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => 'settlement-1',
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 1,
            'execution_date' => '2026-08-20',
        ]);

        // The refusal comes before anything else is even looked up.
        $this->sepaConfigRepository->expects($this->never())->method('getConfig');
        $this->settlementsRepository->expects($this->never())->method('findItemsBySettlementId');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/was cancelled and cannot be exported/i');

        $this->service->export('settlement-1', self::opener());
    }

    public function test_export_refuses_a_settlement_whose_collections_were_reversed(): void
    {
        // #114 / rulings #142 §5 and #148. A reversal is only reachable once
        // money has moved, so the file already went to the bank once. Handing
        // the same file over again debits every member on it a second time —
        // the nine whose collection stood as much as the one that bounced.
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => '2026-08-20',
        ]);
        $this->reversalsRepository->method('findReversedMemberIds')->willReturn([self::MEMBER_ID]);

        // Refused before anything is built, like the cancellation guard beside it.
        $this->sepaConfigRepository->expects($this->never())->method('getConfig');
        $this->settlementsRepository->expects($this->never())->method('findItemsBySettlementId');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/reversed collection/i');

        $this->service->export(self::SETTLEMENT_ID, self::opener());
    }

    public function test_export_reports_a_member_whose_mandate_is_gone_instead_of_dropping_them(): void
    {
        // The failure #114 opens with: an IBAN cleared between settlement
        // creation and export. The member used to be skipped by a bare
        // `continue` — no file line, full `total_amount_cents` still on the
        // books, and nothing anywhere saying the two disagreed.
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 3500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 2000]]);
        $this->givenMembers([
            self::MEMBER_ID => self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => ['has_iban' => 0, 'iban_last4' => null, 'mandate_reference' => null]
                + self::member('Grace', 'Hopper'),
        ]);

        $result = $this->service->export(self::SETTLEMENT_ID, self::opener());

        $this->assertSame(
            [self::OTHER_MEMBER_ID],
            array_map(fn(ExcludedMemberDto $m): string => $m->memberId, $result->uncollectableMembers()),
        );
        $this->assertSame(
            SepaExclusionReason::NO_ACTIVE_MANDATE,
            $result->uncollectableMembers()[0]->reason,
        );
        $this->assertSame('Grace Hopper', $result->uncollectableMembers()[0]->displayName());

        // And the divergence is a number, not something to be inferred.
        $this->assertSame(1500, $result->collectedAmountCents);
        $this->assertSame(3500, $result->settlementAmountCents);
        $this->assertSame(2000, $result->shortfallAmountCents());

        // Ada is still collected — one member's missing mandate does not stop
        // the run, it is reported alongside it (ruling #141 §3).
        $this->assertSame(['E2E-111111112222-aaaaaaaabbbb'], $this->endToEndIds($result->xml));
    }

    public function test_export_reports_a_member_deleted_after_the_settlement_was_created(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 3500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 2000]]);
        $this->givenMembers([
            self::MEMBER_ID => ['deleted_at' => '2026-08-07 09:00:00'] + self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => self::member('Grace', 'Hopper'),
        ]);

        $result = $this->service->export(self::SETTLEMENT_ID, self::opener());

        $this->assertSame(SepaExclusionReason::MEMBER_DELETED, $result->excludedMembers[0]->reason);
        // Named, not a bare UUID: the treasurer has to act on this.
        $this->assertSame('Ada Lovelace', $result->excludedMembers[0]->displayName());
        $this->assertSame(2000, $result->collectedAmountCents);
        $this->assertSame(1500, $result->shortfallAmountCents());
        // Grace is still collected; the deletion is reported alongside the run.
        $this->assertSame(['E2E-111111112222-999999998888'], $this->endToEndIds($result->xml));
    }

    /**
     * #372, the storno case: every member's sales were cancelled, so the run
     * owes nothing and there is no direct debit to write.
     *
     * pain.008 has no way to say that — a PmtInf must carry at least one
     * DrctDbtTxInf — so what came out was a file with an empty payment block
     * that a bank portal rejects, and the caller stamped the settlement as
     * exported on the way past.
     */
    public function test_export_refuses_a_settlement_whose_members_all_owe_nothing(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 0);
        $this->givenItems([[self::MEMBER_ID, 0], [self::OTHER_MEMBER_ID, 0]]);
        $this->givenMembers([
            self::MEMBER_ID => self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => self::member('Grace', 'Hopper'),
        ]);

        // Owing nothing is not an exclusion, so there is no shortfall to log.
        $this->logger->expects($this->never())->method('warning');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('every member in it owes 0.00 EUR');

        $this->service->export(self::SETTLEMENT_ID, self::opener());
    }

    /**
     * The same refusal reached the other way: the members owed money, and every
     * one of them dropped out between creation and export. The exclusions are
     * still logged — #114 is about a divergence nobody could see afterwards,
     * and an export that failed is no reason to stop recording why.
     */
    public function test_export_refuses_a_file_in_which_every_member_is_excluded(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 3500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 2000]]);
        $this->givenMembers([
            self::MEMBER_ID => ['has_iban' => 0, 'iban_last4' => null] + self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => ['deleted_at' => '2026-08-07 09:00:00'] + self::member('Grace', 'Hopper'),
        ]);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('collects less than the settlement records'),
                $this->callback(static fn(array $ctx): bool => $ctx['shortfall_amount_cents'] === 3500
                    && $ctx['collected_amount_cents'] === 0),
            );

        $this->expectException(BusinessRuleException::class);
        // Reasons and counts, never names: the message is logged verbatim by
        // the error handler, and no erasure reaches a log file (#115).
        $this->expectExceptionMessage('every member in it is excluded');

        $this->service->export(self::SETTLEMENT_ID, self::opener());
    }

    /** The refusal must carry no member PII into the log (#115). */
    public function test_the_empty_file_refusal_names_reasons_not_members(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 1500);
        $this->givenItems([[self::MEMBER_ID, 1500]]);
        $this->givenMembers([
            self::MEMBER_ID => ['has_iban' => 0, 'iban_last4' => null] + self::member('Ada', 'Lovelace'),
        ]);

        try {
            $this->service->export(self::SETTLEMENT_ID, self::opener());
            $this->fail('An export with nothing to collect must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('1 × No active SEPA mandate at export time', $e->getMessage());
            foreach (['Ada', 'Lovelace'] as $pii) {
                $this->assertStringNotContainsString($pii, $e->getMessage());
            }
        }
    }

    public function test_a_credit_member_is_reported_in_their_own_bucket_and_is_not_a_shortfall(): void
    {
        // Ruling #141 §3: the two exclusions need opposite remedies — chase the
        // bank details, versus pay the member back — so they are never folded
        // into one list. And a credit is not money the settlement expected to
        // collect, so it must not be counted as a gap in the file.
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 1500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, -2000]]);
        $this->givenMembers([
            self::MEMBER_ID => self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => self::member('Grace', 'Hopper'),
        ]);

        $result = $this->service->export(self::SETTLEMENT_ID, self::opener());

        $this->assertSame(
            [self::OTHER_MEMBER_ID],
            array_map(fn(ExcludedMemberDto $m): string => $m->memberId, $result->creditExcludedMembers()),
        );
        $this->assertSame(-2000, $result->creditExcludedMembers()[0]->amountCents);
        $this->assertSame([], $result->uncollectableMembers());
        $this->assertSame(0, $result->shortfallAmountCents());
    }

    public function test_a_member_who_owes_nothing_is_closed_out_without_being_reported(): void
    {
        // Ruling #141 §5: zero settles but is not collected, and is not an
        // exclusion either — nothing is owed in either direction, so there is
        // nothing for a treasurer to chase.
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 1500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 0]]);
        $this->givenMembers([
            self::MEMBER_ID => self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => self::member('Grace', 'Hopper'),
        ]);

        $result = $this->service->export(self::SETTLEMENT_ID, self::opener());

        $this->assertSame([], $result->excludedMembers);
        $this->assertSame(1500, $result->collectedAmountCents);
        $this->assertSame(0, $result->shortfallAmountCents());
    }

    public function test_a_clean_export_collects_exactly_what_the_settlement_records(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 3500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 2000]]);
        $this->givenMembers([
            self::MEMBER_ID => self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => self::member('Grace', 'Hopper'),
        ]);

        // Nothing to warn about, so nothing is written to the log.
        $this->logger->expects($this->never())->method('warning');

        $result = $this->service->export(self::SETTLEMENT_ID, self::opener());

        $this->assertSame([], $result->excludedMembers);
        $this->assertSame($result->settlementAmountCents, $result->collectedAmountCents);
        $this->assertSame(0, $result->shortfallAmountCents());
    }

    public function test_a_shortfall_is_written_to_the_application_log(): void
    {
        // The response is a file download the browser saves and forgets. If
        // the divergence lived only there, #114's "nobody is notified" would
        // survive the fix.
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 3500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 2000]]);
        $this->givenMembers([
            self::MEMBER_ID => ['has_iban' => 0, 'iban_last4' => null] + self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => self::member('Grace', 'Hopper'),
        ]);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('collects less than the settlement records'),
                $this->callback(static fn(array $ctx): bool => $ctx['shortfall_amount_cents'] === 1500
                    && $ctx['collected_amount_cents'] === 2000
                    && $ctx['settlement_amount_cents'] === 3500
                    // Ids, not names: no erasure reaches a log file (#115).
                    && $ctx['excluded_members'] === [[
                        'member_id' => self::MEMBER_ID,
                        'amount_cents' => 1500,
                        'reason' => 'no_active_mandate',
                    ]]),
            );

        $this->service->export(self::SETTLEMENT_ID, self::opener());
    }

    public function test_the_audit_summary_carries_every_exclusion_and_both_totals(): void
    {
        // What survives the request: the audit entry is where the omission can
        // still be read a month later, when the bank statement is short.
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 3500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 2000]]);
        $this->givenMembers([
            self::MEMBER_ID => self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => ['mandate_reference' => null] + self::member('Grace', 'Hopper'),
        ]);

        $summary = $this->service->export(self::SETTLEMENT_ID, self::opener())->toAuditSummary();

        $this->assertSame(1500, $summary['collected_amount_cents']);
        $this->assertSame(3500, $summary['settlement_amount_cents']);
        $this->assertSame(2000, $summary['shortfall_amount_cents']);
        $this->assertSame([[
            'member_id' => self::OTHER_MEMBER_ID,
            'amount_cents' => 2000,
            'reason' => 'no_active_mandate',
        ]], $summary['excluded_members']);
    }

    /**
     * The scrub that answers an Art. 17 erasure sweeps the audit entries keyed
     * to the member's id. This entry is keyed to the settlement, so nothing
     * sweeps it, and nulling it wholesale would erase the exclusion record of
     * every other member in the run — so it must not carry a name in the first
     * place (#115). The id is fine: after the erasure it resolves to nobody.
     */
    public function test_the_audit_summary_carries_no_member_pii(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 3500);
        $this->givenItems([[self::MEMBER_ID, 1500], [self::OTHER_MEMBER_ID, 2000]]);
        $this->givenMembers([
            self::MEMBER_ID => self::member('Ada', 'Lovelace'),
            self::OTHER_MEMBER_ID => ['mandate_reference' => null] + self::member('Grace', 'Hopper'),
        ]);

        $summary = $this->service->export(self::SETTLEMENT_ID, self::opener())->toAuditSummary();

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach (['Grace', 'Hopper', 'Ada', 'Lovelace', 'first_name', 'last_name'] as $pii) {
            $this->assertStringNotContainsString($pii, $encoded);
        }
    }

    public function test_the_end_to_end_id_identifies_the_settlement_and_the_member(): void
    {
        // #150: a bank return quotes the EndToEndId back as `EREF+`, and that
        // is the only field that survives an R-transaction (SVWZ is replaced
        // by the constant RETURN/REFUND — see #149). It therefore has to name
        // the collection: which run, and whose money.
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID);
        $this->givenItems([[self::MEMBER_ID, 1500]]);
        $this->givenMembers([self::MEMBER_ID => self::member('Ada', 'Lovelace')]);

        $xml = $this->service->export(self::SETTLEMENT_ID, self::opener())->xml;

        // Worked by hand from the two UUIDs: hyphens stripped, first 12 hex
        // digits of each. 29 characters, inside the ISO 20022 cap of 35.
        $this->assertSame(
            ['E2E-111111112222-aaaaaaaabbbb'],
            $this->endToEndIds($xml),
        );
    }

    public function test_a_member_keeps_the_same_end_to_end_id_when_the_export_skips_someone_else(): void
    {
        // The old identifier was the loop index over the members that survived
        // the skip rules, so it moved whenever the composition of the run did:
        // an IBAN cleared between exports, or the credit exclusion of ruling
        // #141 dropping an earlier member, silently renumbered everyone after
        // them. A return quoting last month's EREF then resolved to the wrong
        // member, or to nobody (#150).
        $ada = self::member('Ada', 'Lovelace');
        $grace = self::member('Grace', 'Hopper');

        // First run: Grace comes first and is collected, so under the old
        // scheme Ada was number two.
        $first = $this->exportedEndToEndIds(
            [[self::OTHER_MEMBER_ID, 2000], [self::MEMBER_ID, 1500]],
            [self::OTHER_MEMBER_ID => $grace, self::MEMBER_ID => $ada],
        );

        // Second run: Grace is now in credit, so she gets no file line at all
        // and Ada becomes the only transfer.
        $second = $this->exportedEndToEndIds(
            [[self::OTHER_MEMBER_ID, -2000], [self::MEMBER_ID, 1500]],
            [self::OTHER_MEMBER_ID => $grace, self::MEMBER_ID => $ada],
        );

        $this->assertSame(
            ['E2E-111111112222-999999998888', 'E2E-111111112222-aaaaaaaabbbb'],
            $first,
        );
        $this->assertSame(
            ['E2E-111111112222-aaaaaaaabbbb'],
            $second,
            'Ada\'s identifier must survive Grace dropping out of the run'
        );
    }

    public function test_the_end_to_end_id_stays_inside_the_iso_20022_length_cap(): void
    {
        $ids = $this->exportedEndToEndIds(
            [[self::MEMBER_ID, 1500]],
            [self::MEMBER_ID => self::member('Ada', 'Lovelace')],
        );

        $this->assertLessThanOrEqual(35, strlen($ids[0]));
    }

    private const SETTLEMENT_ID = '11111111-2222-3333-4444-555555555555';
    private const MEMBER_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    private const OTHER_MEMBER_ID = '99999999-8888-7777-6666-555555555555';

    private function givenSepaConfig(): void
    {
        $this->sepaConfigRepository->method('getConfig')->willReturn([
            'creditor_id' => 'DE98ZZZ09999999999',
            'creditor_name' => 'Ruderclub',
            'creditor_iban' => 'DE89370400440532013000',
            'payment_reference_prefix' => null,
            'mandate_template_url' => 'https://club.example/anmeldung',
        ]);
    }

    private function givenSettlement(string $id, ?int $totalAmountCents = null): void
    {
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => $id,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => '2026-08-20',
            'settlement_date' => '2026-08-06',
            'sepa_message_id' => null,
            'total_amount_cents' => $totalAmountCents ?? 0,
        ]);
    }

    /** @param list<array{0: string, 1: int}> $memberAmounts member id => signed cents */
    private function givenItems(array $memberAmounts): void
    {
        $items = [];
        foreach ($memberAmounts as [$memberId, $amountCents]) {
            $items[] = ['member_id' => $memberId, 'amount_cents' => $amountCents];
        }
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn($items);
    }

    /** @param array<string, array<string, mixed>|null> $members */
    private function givenMembers(array $members): void
    {
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturnCallback(static fn(string $id): ?array => $members[$id] ?? null);

        // The export resolves the plaintext through the dedicated sealed-IBAN
        // read (ADR-0036). Every stored mandate is sealed, so these fixtures
        // answer with ciphertext and the tests supply an opener for it.
        $this->membersRepository->method('findSealedIban')
            ->willReturnCallback(static function (string $id) use ($members): ?array {
                $member = $members[$id] ?? null;
                if ($member === null || empty($member['has_iban'])) {
                    return null;
                }
                return ['iban_ciphertext' => 'v1:sealed', 'encryption_key_id' => 'key-1'];
            });
    }

    /**
     * Stands in for the club's private key: the export opens every ciphertext
     * through this closure, so a test that does not care which IBAN comes out
     * can hand it one fixed answer.
     */
    private static function opener(string $iban = 'DE02120300000000202051'): \Closure
    {
        return static fn(string $ciphertext): string => $iban;
    }

    /** @return array<string, mixed> */
    private static function member(string $first, string $last): array
    {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'account_holder_name' => null,
            'has_iban' => 1,
            'iban_last4' => '2051',
            'mandate_reference' => 'MND-' . strtoupper($last),
            'mandate_signed_at' => '2025-01-15',
        ];
    }

    /**
     * Run one export against a throwaway set of mocks, so a single test can
     * compare two runs of the same settlement.
     *
     * @param list<array{0: string, 1: int}> $memberAmounts
     * @param array<string, array<string, mixed>> $members
     * @return list<string>
     */
    private function exportedEndToEndIds(array $memberAmounts, array $members): array
    {
        $this->buildService();

        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID);
        $this->givenItems($memberAmounts);
        $this->givenMembers($members);

        return $this->endToEndIds($this->service->export(self::SETTLEMENT_ID, self::opener())->xml);
    }

    public function test_a_sealed_iban_is_opened_through_the_supplied_closure(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 1500);
        $this->givenItems([[self::MEMBER_ID, 1500]]);
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn(self::member('Ada', 'Lovelace'));
        $this->membersRepository->method('findSealedIban')
            ->willReturn(['iban_ciphertext' => 'v1:sealed', 'encryption_key_id' => 'key-1']);

        $opened = [];
        $result = $this->service->export(self::SETTLEMENT_ID, function (string $ciphertext) use (&$opened): string {
            $opened[] = $ciphertext;
            return 'DE02120300000000202051';
        });

        $this->assertSame(['v1:sealed'], $opened, 'the closure gets exactly the stored ciphertext');
        $this->assertStringContainsString('DE02120300000000202051', $result->xml);
    }

    public function test_a_sealed_iban_without_a_private_key_is_a_hard_error_not_an_exclusion(): void
    {
        // A short file that reads as complete is the #114 failure mode all over
        // again — sealed rows must stop the export, not fall out of it.
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 1500);
        $this->givenItems([[self::MEMBER_ID, 1500]]);
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn(self::member('Ada', 'Lovelace'));
        $this->membersRepository->method('findSealedIban')
            ->willReturn(['iban_ciphertext' => 'v1:sealed', 'encryption_key_id' => 'key-1']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/private key/i');

        $this->service->export(self::SETTLEMENT_ID);
    }

    public function test_a_mandate_vanishing_between_the_two_reads_fails_loudly(): void
    {
        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID, totalAmountCents: 1500);
        $this->givenItems([[self::MEMBER_ID, 1500]]);
        $this->membersRepository->method('findByIdIncludingDeleted')
            ->willReturn(self::member('Ada', 'Lovelace'));
        $this->membersRepository->method('findSealedIban')->willReturn(null);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/vanished/i');

        $this->service->export(self::SETTLEMENT_ID, self::opener());
    }

    /** @return list<string> the EndToEndIds the file carries, in document order */
    private function endToEndIds(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pain', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $ids = [];
        foreach ($xpath->query('//pain:DrctDbtTxInf/pain:PmtId/pain:EndToEndId') as $node) {
            $ids[] = $node->textContent;
        }
        return $ids;
    }
}
