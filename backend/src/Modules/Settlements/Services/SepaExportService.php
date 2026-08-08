<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\DTOs\SepaExportResultDto;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Utils\BankingCalendar;
use App\Shared\Utils\SepaSanitizer;

class SepaExportService
{
    public function __construct(
        private SepaConfigRepository $sepaConfigRepository,
        private MembersRepository $membersRepository,
        private SettlementsRepository $settlementsRepository,
    ) {}

    /**
     * Build the pain.008 file for a settlement, together with the members the
     * file deliberately omits. Callers get both or neither: the omissions are
     * not recoverable from the XML, and #114 records what happens when they
     * are dropped on the floor.
     */
    public function export(string $settlementId): SepaExportResultDto
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) throw NotFoundException::forResource('Settlement', $settlementId);

        // A cancelled settlement collects nothing — its claims on the
        // transactions were released, and they belong to whatever run takes
        // them next. Exporting it would instruct the bank to collect money the
        // club has already decided not to collect, twice over once the
        // transactions are settled again (#114, ruling #142 §5).
        //
        // This never had a guard. It used to fail by accident with the
        // misleading "Settlement has no items", because cancellation deleted
        // the rows; now that they survive it would not fail at all.
        if (!empty($settlement['is_cancelled'])) {
            throw new BusinessRuleException(sprintf(
                'Settlement %s was cancelled and cannot be exported to SEPA',
                $settlementId
            ));
        }

        // #163: only direct_debit settlements were ever collected through the
        // SEPA mandate. Exporting a bank_transfer/write_off settlement would
        // double-collect the balance from the member's bank account (e.g. a
        // member pays by bank transfer, the treasurer records it as such, and
        // someone later exports it anyway — the bank would still debit them).
        $method = SettlementMethod::tryFrom($settlement['method'] ?? '') ?? SettlementMethod::DIRECT_DEBIT;
        if (!$method->isSepaExportable()) {
            throw new BusinessRuleException(sprintf(
                'Settlement %s uses method "%s" and cannot be exported to SEPA; only direct_debit settlements are exportable',
                $settlementId,
                $method->value
            ));
        }

        $config = $this->sepaConfigRepository->getConfig();
        if (!$config || empty($config['creditor_id']) || empty($config['creditor_name']) || empty($config['creditor_iban'])) {
            throw new BusinessRuleException('SEPA configuration incomplete');
        }

        // Settlements created before the business-day rule existed (issue #11) can
        // still hold a weekend or TARGET2 closing date. Refuse rather than emit an
        // invalid ReqdColltnDt that a bank portal would reject.
        if (!BankingCalendar::isBusinessDay($settlement['execution_date'])) {
            throw new BusinessRuleException(sprintf(
                'Settlement execution date %s is not a bank business day (Mon-Fri, excluding TARGET2 closing days); '
                . 'cancel the settlement and recreate it with a valid date',
                $settlement['execution_date']
            ));
        }

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        if (empty($items)) throw new BusinessRuleException('Settlement has no items');

        // Group by member
        $memberTotals = [];
        foreach ($items as $item) {
            $mid = $item['member_id'];
            if (!isset($memberTotals[$mid])) {
                $memberTotals[$mid] = ['amount_cents' => 0, 'member_id' => $mid];
            }
            $memberTotals[$mid]['amount_cents'] += (int) $item['amount_cents'];
        }

        // Build SEPA XML using digitick/sepa-xml.
        // ISO 20022 caps MsgId/PmtInfId/EndToEndId at 35 chars, so identifiers
        // derived from UUIDs use the hyphen-stripped, truncated form.
        $settlementIdHex = str_replace('-', '', $settlement['id']);
        $messageId = $settlement['sepa_message_id'] ?? 'MSG-' . substr($settlementIdHex, 0, 31);
        $paymentId = 'PMT-' . substr($settlementIdHex, 0, 16);
        $creditorName = $this->sanitizeName($config['creditor_name']);

        $directDebit = \Digitick\Sepa\TransferFile\Factory\TransferFileFacadeFactory::createDirectDebit(
            $messageId,
            $creditorName,
            'pain.008.001.08'
        );

        // IBAN-only submission: no agent BIC is collected. Omitting the BIC key
        // makes digitick emit <FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>,
        // the encoding the EPC/DK guidelines prescribe for pain.008.001.08.
        // Passing the literal 'NOTPROVIDED' would instead land in <BICFI>, where it
        // parses as a (non-existent) Romanian BIC and can trip bank-side validators.
        $directDebit->addPaymentInfo(
            $paymentId,
            [
                'id' => $paymentId,
                'creditorName' => $this->sanitizeName($config['creditor_name']),
                'creditorAccountIBAN' => $this->sanitizeIban($config['creditor_iban']),
                'seqType' => \Digitick\Sepa\PaymentInformation::S_RECURRING,
                'creditorId' => $config['creditor_id'],
                // Must be 'dueDate' — that is the key digitick's facade reads for
                // ReqdColltnDt. An unrecognised key is silently ignored and the
                // library falls back to today + 5 days (issue #11).
                'dueDate' => $settlement['execution_date'],
            ]
        );

        $sequence = 0;
        $creditExcludedMembers = [];
        foreach ($memberTotals as $entry) {
            $member = $this->membersRepository->findById($entry['member_id']);
            if (!$member || empty($member['iban']) || empty($member['mandate_reference'])) continue;

            // #80 / ruling #141 (exclude-and-flag): guard the *signed* total.
            // abs() ran before the guard, so a net credit of -1500 became a
            // +1500 debit — collecting money the club owes the member
            // (§ 812 BGB). A credit gets no file line; it carries forward and
            // is refunded by hand.
            $amountCents = (int) $entry['amount_cents'];
            if ($amountCents < 0) {
                $creditExcludedMembers[] = [
                    'member_id' => $entry['member_id'],
                    'first_name' => $member['first_name'],
                    'last_name' => $member['last_name'],
                    'balance_cents' => $amountCents,
                ];
                continue;
            }
            // Only zero remains: it closes the rows out without a collection
            // instruction, and is not an exclusion — nothing is owed either way.
            if ($amountCents <= 0) continue;

            $sequence++;
            $directDebit->addTransfer(
                $paymentId,
                [
                    'amount' => $amountCents,
                    'endToEndId' => $paymentId . '-' . $sequence,
                    'debtorIban' => $this->sanitizeIban($member['iban']),
                    // No debtorBic: see the CdtrAgt note above — the omitted BIC
                    // yields <DbtrAgt><FinInstnId><Othr><Id>NOTPROVIDED</Id>.
                    'debtorName' => $this->sanitizeName($member['account_holder_name'] ?? ($member['first_name'] . ' ' . $member['last_name'])),
                    'debtorMandate' => $member['mandate_reference'],
                    'debtorMandateSignDate' => $member['mandate_signed_at'] ?? $settlement['settlement_date'],
                    'remittanceInformation' => $this->buildRemittanceInfo($config, $settlement['settlement_date']),
                ]
            );
        }

        $xml = $directDebit->asXML();
        $this->validateSepaXml($xml);

        return new SepaExportResultDto($xml, $creditExcludedMembers);
    }

    private function buildRemittanceInfo(array $config, string $settlementDate): string
    {
        $prefix = $config['payment_reference_prefix'] ?? null;
        if ($prefix) {
            return SepaSanitizer::sanitizeName($prefix) . ' ' . $settlementDate;
        }
        return 'Settlement ' . $settlementDate;
    }

    public function sanitizeName(string $name): string
    {
        return SepaSanitizer::sanitizeName($name);
    }

    public function sanitizeIban(string $iban): string
    {
        return SepaSanitizer::sanitizeIban($iban);
    }

    private function validateSepaXml(string $xml): void
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            throw new BusinessRuleException('Generated SEPA XML is malformed');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pain', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        if (!$xpath->query('//pain:GrpHdr')->length) {
            throw new BusinessRuleException('SEPA XML missing GrpHdr element');
        }
    }
}
