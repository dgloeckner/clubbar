<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;

class SepaExportService
{
    public function __construct(
        private SepaConfigRepository $sepaConfigRepository,
        private MembersRepository $membersRepository,
        private SettlementsRepository $settlementsRepository,
    ) {}

    public function generateSepaXml(string $settlementId): string
    {
        $config = $this->sepaConfigRepository->getConfig();
        if (!$config || empty($config['creditor_id']) || empty($config['creditor_name']) || empty($config['creditor_iban'])) {
            throw new \RuntimeException('SEPA configuration incomplete');
        }

        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) throw new \RuntimeException("Settlement not found: $settlementId");

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        if (empty($items)) throw new \RuntimeException('Settlement has no items');

        // Group by member
        $memberTotals = [];
        foreach ($items as $item) {
            $mid = $item['member_id'];
            if (!isset($memberTotals[$mid])) {
                $memberTotals[$mid] = ['amount_cents' => 0, 'member_id' => $mid];
            }
            $memberTotals[$mid]['amount_cents'] += (int) $item['amount_cents'];
        }

        // Build SEPA XML using digitick/sepa-xml
        $groupHeader = new \Digitick\Sepa\GroupHeader(
            $settlement['sepa_message_id'] ?? 'MSG-' . $settlement['id'],
            $this->sanitizeName($config['creditor_name'])
        );

        $sepaFile = new \Digitick\Sepa\PaymentInformation(
            'PMT-' . $settlement['id'],
            $this->sanitizeIban($config['creditor_iban']),
            'BIC',
            $this->sanitizeName($config['creditor_name'])
        );

        $directDebit = \Digitick\Sepa\TransferFile\Factory\TransferFileFacadeFactory::createDirectDebit(
            $groupHeader,
            'pain.008.001.09'
        );

        $directDebit->addPaymentInfo(
            'PMT-' . $settlement['id'],
            [
                'id' => 'PMT-' . $settlement['id'],
                'creditorName' => $this->sanitizeName($config['creditor_name']),
                'creditorAccountIBAN' => $this->sanitizeIban($config['creditor_iban']),
                'creditorAgentBIC' => 'NOTPROVIDED',
                'seqType' => \Digitick\Sepa\PaymentInformation::S_RECURRING,
                'creditorId' => $config['creditor_id'],
                'requestedCollectionDate' => $settlement['execution_date'],
            ]
        );

        foreach ($memberTotals as $entry) {
            $member = $this->membersRepository->findById($entry['member_id']);
            if (!$member || empty($member['iban']) || empty($member['mandate_reference'])) continue;

            $amountEur = abs($entry['amount_cents']) / 100;
            if ($amountEur <= 0) continue;

            $directDebit->addTransfer(
                'PMT-' . $settlement['id'],
                [
                    'amount' => $amountEur,
                    'debtorIban' => $this->sanitizeIban($member['iban']),
                    'debtorBic' => 'NOTPROVIDED',
                    'debtorName' => $this->sanitizeName($member['account_holder_name'] ?? ($member['first_name'] . ' ' . $member['last_name'])),
                    'debtorMandate' => $member['mandate_reference'],
                    'debtorMandateSignDate' => $member['mandate_signed_at'] ?? $settlement['settlement_date'],
                    'remittanceInformation' => 'Settlement ' . $settlement['settlement_date'],
                ]
            );
        }

        $xml = $directDebit->asXML();
        $this->validateSepaXml($xml);

        return $xml;
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
            throw new \RuntimeException('Generated SEPA XML is malformed');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pain', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.09');

        if (!$xpath->query('//pain:GrpHdr')->length) {
            throw new \RuntimeException('SEPA XML missing GrpHdr element');
        }
    }
}
