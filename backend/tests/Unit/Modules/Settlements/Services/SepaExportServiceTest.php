<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SepaExportService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

class SepaExportServiceTest extends TestCase
{
    private SepaConfigRepository $sepaConfigRepository;
    private MembersRepository $membersRepository;
    private SettlementsRepository $settlementsRepository;
    private SepaExportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sepaConfigRepository = $this->createMock(SepaConfigRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);

        $this->service = new SepaExportService(
            $this->sepaConfigRepository,
            $this->membersRepository,
            $this->settlementsRepository,
        );
    }

    public function test_export_throws_notFoundException_when_the_settlement_is_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->export('missing-id');
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

        $this->service->export('settlement-1');
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

        $xml = $this->service->export(self::SETTLEMENT_ID)->xml;

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
        ]);
    }

    private function givenSettlement(string $id): void
    {
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => $id,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => '2026-08-20',
            'settlement_date' => '2026-08-06',
            'sepa_message_id' => null,
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
        $this->membersRepository->method('findById')
            ->willReturnCallback(static fn(string $id): ?array => $members[$id] ?? null);
    }

    /** @return array<string, mixed> */
    private static function member(string $first, string $last): array
    {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'account_holder_name' => null,
            'iban' => 'DE02120300000000202051',
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
        $this->sepaConfigRepository = $this->createMock(SepaConfigRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);
        $this->service = new SepaExportService(
            $this->sepaConfigRepository,
            $this->membersRepository,
            $this->settlementsRepository,
        );

        $this->givenSepaConfig();
        $this->givenSettlement(self::SETTLEMENT_ID);
        $this->givenItems($memberAmounts);
        $this->givenMembers($members);

        return $this->endToEndIds($this->service->export(self::SETTLEMENT_ID)->xml);
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
