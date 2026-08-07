<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SepaExportService;
use App\Shared\Exceptions\BusinessRuleException;
use PHPUnit\Framework\TestCase;

class SepaExportServiceTest extends TestCase
{
    private const SETTLEMENT_ID = '401f7c9d-bf50-4925-b462-5eb83d8cdc64';
    private const MEMBER_ID = 'a1b2c3d4-0000-0000-0000-000000000001';
    private const MEMBER_ID_2 = 'a1b2c3d4-0000-0000-0000-000000000002';
    private const XSD_PATH = __DIR__ . '/../../../../../vendor/digitick/sepa-xml/doc/ISO20022/pain/008/001/pain.008.001.08.xsd';

    private function makeService(
        bool $twoMembers = false,
        string $executionDate = '2026-04-08',
        string $method = 'direct_debit',
        ?array $items = null,
    ): SepaExportService {
        $sepaConfig = $this->createMock(SepaConfigRepository::class);
        $sepaConfig->method('getConfig')->willReturn([
            'creditor_id' => 'DE98ZZZ09999999999',
            'creditor_name' => 'Test Club',
            'creditor_iban' => 'DE89370400440532013000',
            'payment_reference_prefix' => 'CLUBBAR',
        ]);

        $settlements = $this->createMock(SettlementsRepository::class);
        $settlements->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => $method,
            'sepa_message_id' => 'SEPA-TEST-MSG',
            'settlement_date' => '2026-04-01',
            'execution_date' => $executionDate,
        ]);
        if ($items === null) {
            $items = [['member_id' => self::MEMBER_ID, 'amount_cents' => 500]];
            if ($twoMembers) {
                $items[] = ['member_id' => self::MEMBER_ID_2, 'amount_cents' => 750];
            }
        }
        $settlements->method('findItemsBySettlementId')->willReturn($items);

        $memberRow = fn(string $id, string $lastName) => [
            'id' => $id,
            'first_name' => 'Max',
            'last_name' => $lastName,
            'account_holder_name' => null,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'F3332CA866B249E7A202BFBF4836B605',
            'mandate_signed_at' => '2024-01-01',
        ];
        $members = $this->createMock(MembersRepository::class);
        $members->method('findById')->willReturnMap([
            [self::MEMBER_ID, $memberRow(self::MEMBER_ID, 'Mustermann')],
            [self::MEMBER_ID_2, $memberRow(self::MEMBER_ID_2, 'Musterfrau')],
        ]);

        return new SepaExportService($sepaConfig, $members, $settlements);
    }

    public function testGeneratesPain008001V08Document(): void
    {
        $xml = $this->makeService()->export(self::SETTLEMENT_ID)->xml;

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Export must be well-formed XML');
        $this->assertSame(
            'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08',
            $dom->documentElement->namespaceURI,
            'SEPA export must use the pain.008.001.08 namespace (EPC 2023 rulebook / DK EBICS)'
        );
        $this->assertStringContainsString('pain.008.001.08.xsd', $xml, 'schemaLocation must reference the .08 XSD');
    }

    public function testExportContainsCoreDirectDebitStructure(): void
    {
        $xml = $this->makeService()->export(self::SETTLEMENT_ID)->xml;

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $this->assertSame(1, $xpath->query('//p:GrpHdr')->length);
        $this->assertSame(1, $xpath->query('//p:DrctDbtTxInf')->length);
        $this->assertSame('5.00', $xpath->query('//p:CtrlSum')->item(0)->textContent);
        $this->assertSame('RCUR', $xpath->query('//p:SeqTp')->item(0)->textContent);
        $this->assertSame('CORE', $xpath->query('//p:LclInstrm/p:Cd')->item(0)->textContent);
        $this->assertSame('F3332CA866B249E7A202BFBF4836B605', $xpath->query('//p:MndtId')->item(0)->textContent);
    }

    public function testIbanOnlySubmissionUsesOthrIdNotProvidedForAgents(): void
    {
        $xml = $this->makeService()->export(self::SETTLEMENT_ID)->xml;

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        // EPC/DK guidelines: when no BIC is supplied, the agent must be identified
        // via Othr/Id = NOTPROVIDED. BICFI=NOTPROVIDED only passes XSD validation by
        // accident — it parses as a non-existent Romanian BIC (NOTP-RO-VI-DED) and is
        // rejected by bank-side validators that resolve BICs against a directory.
        $this->assertSame(0, $xpath->query('//p:FinInstnId/p:BICFI')->length);

        foreach (['CdtrAgt', 'DbtrAgt'] as $agent) {
            // Both agents are mandatory (minOccurs=1) in pain.008.001.08 and must not
            // be dropped just because no BIC is available.
            $this->assertSame(1, $xpath->query("//p:{$agent}")->length, "{$agent} is mandatory");

            $ids = $xpath->query("//p:{$agent}/p:FinInstnId/p:Othr/p:Id");
            $this->assertSame(1, $ids->length, "{$agent} must carry exactly one Othr/Id");
            $this->assertSame('NOTPROVIDED', $ids->item(0)->textContent);
        }
    }

    public function testExportValidatesAgainstOfficialXsd(): void
    {
        $xml = $this->makeService(twoMembers: true)->export(self::SETTLEMENT_ID)->xml;

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate(self::XSD_PATH);
        $errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();

        $this->assertTrue($valid, "Export must validate against official pain.008.001.08 XSD:\n" . implode("\n", $errors));
    }

    public function testEndToEndIdsAreUniquePerTransaction(): void
    {
        $xml = $this->makeService(twoMembers: true)->export(self::SETTLEMENT_ID)->xml;

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $ids = [];
        foreach ($xpath->query('//p:EndToEndId') as $node) {
            $ids[] = $node->textContent;
        }
        $this->assertCount(2, $ids);
        $this->assertSame($ids, array_unique($ids), 'EndToEndIds must be unique within the file');
    }

    // ── Business-day guard (issue #11) ────────────────────────────────
    //
    // Validation at creation time cannot help settlements stored before that
    // rule existed, so the export refuses to emit an invalid ReqdColltnDt.

    public function testExportRejectsWeekendExecutionDate(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/business day/i');

        // 2026-08-09 is the Sunday reported in issue #11.
        $this->makeService(executionDate: '2026-08-09')->export(self::SETTLEMENT_ID);
    }

    public function testExportRejectsTarget2ClosingDay(): void
    {
        $this->expectException(BusinessRuleException::class);

        // Good Friday — a weekday, so only the holiday set catches it.
        $this->makeService(executionDate: '2026-04-03')->export(self::SETTLEMENT_ID);
    }

    /**
     * Regression test for the root cause behind issue #11: the payment info was
     * built with a 'requestedCollectionDate' key, which digitick's facade does
     * not read. The key was silently ignored and ReqdColltnDt fell back to the
     * library default of today + 5 days, so the admin-chosen execution date
     * never reached the file. Asserting the exact date keeps that from
     * regressing — a fallback would produce a moving, today-relative value.
     */
    public function testExportEmitsTheSettlementExecutionDateAsRequestedCollectionDate(): void
    {
        $xml = $this->makeService(executionDate: '2026-04-07')->export(self::SETTLEMENT_ID)->xml;

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $nodes = $xpath->query('//p:ReqdColltnDt');
        $this->assertSame(1, $nodes->length, 'Export must carry exactly one ReqdColltnDt');
        $this->assertSame('2026-04-07', $nodes->item(0)->textContent);
    }

    // ── Method guard (ruling #163) ─────────────────────────────────────
    //
    // Only direct_debit settlements were ever collected via the SEPA
    // mandate. Exporting a bank_transfer/write_off settlement anyway would
    // double-collect: e.g. a member pays by bank transfer, the treasurer
    // records that as a bank_transfer settlement, and someone later exports
    // it regardless — the bank would still debit the member's account.

    public function testExportRejectsBankTransferSettlement(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/cannot be exported/i');

        $this->makeService(method: 'bank_transfer')->export(self::SETTLEMENT_ID);
    }

    public function testExportRejectsWriteOffSettlement(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/cannot be exported/i');

        $this->makeService(method: 'write_off')->export(self::SETTLEMENT_ID);
    }

    // ── Credit exclusion (issue #80, ruling #141) ──────────────────────
    //
    // A member whose settlement items net to a credit is owed money by the
    // club (§ 812 BGB). abs() turned that credit into a collection: a net
    // -15.00 EUR position became a +15.00 EUR debit instruction, taking 15
    // EUR the member does not owe. Exclude-and-flag: no file line, and the
    // member is reported so the treasurer can refund by hand.

    /**
     * @return array<int, array{member_id: string, amount_cents: int}>
     */
    private static function itemsNetting(int $memberOneCents, int $memberTwoCents): array
    {
        return [
            ['member_id' => self::MEMBER_ID, 'amount_cents' => $memberOneCents],
            ['member_id' => self::MEMBER_ID_2, 'amount_cents' => $memberTwoCents],
        ];
    }

    public function testMemberInCreditIsNotDebited(): void
    {
        // Mustermann was refunded a mis-charged round and nets to -15.00 EUR;
        // Musterfrau owes 7.50 EUR.
        $xml = $this->makeService(items: self::itemsNetting(-1500, 750))
            ->export(self::SETTLEMENT_ID)->xml;

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $amounts = [];
        foreach ($xpath->query('//p:InstdAmt') as $node) {
            $amounts[] = $node->textContent;
        }

        $this->assertSame(['7.50'], $amounts, 'Only the member who owes money may appear in the file');
        $this->assertSame('7.50', $xpath->query('//p:CtrlSum')->item(0)->textContent);
    }

    public function testMemberInCreditIsReportedToTheTreasurer(): void
    {
        $result = $this->makeService(items: self::itemsNetting(-1500, 750))
            ->export(self::SETTLEMENT_ID);

        $this->assertSame(
            [[
                'member_id' => self::MEMBER_ID,
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'balance_cents' => -1500,
            ]],
            $result->creditExcludedMembers,
            'A member excluded for being in credit must be reported, never silently dropped'
        );
    }

    public function testMemberWhoNetsToZeroIsClosedOutRatherThanExcluded(): void
    {
        // A credit and its payout cancel: nothing to collect, nothing owed.
        // The rows still settle, so this is not an exclusion the treasurer
        // must act on — the boundary the ruling draws at exactly zero.
        $result = $this->makeService(items: self::itemsNetting(0, 750))
            ->export(self::SETTLEMENT_ID);

        $this->assertSame([], $result->creditExcludedMembers);

        $dom = new \DOMDocument();
        $dom->loadXML($result->xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $this->assertSame(1, $xpath->query('//p:DrctDbtTxInf')->length, 'A zero position gets no file line');
    }
}
