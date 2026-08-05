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

    private function makeService(bool $twoMembers = false, string $executionDate = '2026-04-08'): SepaExportService
    {
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
            'sepa_message_id' => 'SEPA-TEST-MSG',
            'settlement_date' => '2026-04-01',
            'execution_date' => $executionDate,
        ]);
        $items = [['member_id' => self::MEMBER_ID, 'amount_cents' => 500]];
        if ($twoMembers) {
            $items[] = ['member_id' => self::MEMBER_ID_2, 'amount_cents' => 750];
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
        $xml = $this->makeService()->generateSepaXml(self::SETTLEMENT_ID);

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
        $xml = $this->makeService()->generateSepaXml(self::SETTLEMENT_ID);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $this->assertSame(1, $xpath->query('//p:GrpHdr')->length);
        $this->assertSame(1, $xpath->query('//p:DrctDbtTxInf')->length);
        $this->assertSame('5.00', $xpath->query('//p:CtrlSum')->item(0)->textContent);
        $this->assertSame('RCUR', $xpath->query('//p:SeqTp')->item(0)->textContent);
        $this->assertSame('CORE', $xpath->query('//p:LclInstrm/p:Cd')->item(0)->textContent);
        // ISO 20022 2019 versions (>= .08) use BICFI instead of BIC
        $this->assertGreaterThan(0, $xpath->query('//p:FinInstnId/p:BICFI')->length);
        $this->assertSame('F3332CA866B249E7A202BFBF4836B605', $xpath->query('//p:MndtId')->item(0)->textContent);
    }

    public function testExportValidatesAgainstOfficialXsd(): void
    {
        $xml = $this->makeService(twoMembers: true)->generateSepaXml(self::SETTLEMENT_ID);

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
        $xml = $this->makeService(twoMembers: true)->generateSepaXml(self::SETTLEMENT_ID);

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
        $this->makeService(executionDate: '2026-08-09')->generateSepaXml(self::SETTLEMENT_ID);
    }

    public function testExportRejectsTarget2ClosingDay(): void
    {
        $this->expectException(BusinessRuleException::class);

        // Good Friday — a weekday, so only the holiday set catches it.
        $this->makeService(executionDate: '2026-04-03')->generateSepaXml(self::SETTLEMENT_ID);
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
        $xml = $this->makeService(executionDate: '2026-04-07')->generateSepaXml(self::SETTLEMENT_ID);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        $nodes = $xpath->query('//p:ReqdColltnDt');
        $this->assertSame(1, $nodes->length, 'Export must carry exactly one ReqdColltnDt');
        $this->assertSame('2026-04-07', $nodes->item(0)->textContent);
    }
}
