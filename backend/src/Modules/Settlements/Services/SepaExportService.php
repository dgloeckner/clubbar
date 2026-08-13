<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\Domain\EndToEndId;
use App\Modules\Settlements\DTOs\ExcludedMemberDto;
use App\Modules\Settlements\DTOs\SepaExportResultDto;
use App\Modules\Settlements\Enums\SepaExclusionReason;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Logging\Logger;
use App\Shared\Utils\BankingCalendar;
use App\Shared\Utils\SepaSanitizer;

class SepaExportService
{
    public function __construct(
        private SepaConfigRepository $sepaConfigRepository,
        private MembersRepository $membersRepository,
        private SettlementsRepository $settlementsRepository,
        private SettlementReversalsRepository $reversalsRepository,
        private Logger $logger,
    ) {}

    /**
     * Build the pain.008 file for a settlement, together with the members the
     * file deliberately omits. Callers get both or neither: the omissions are
     * not recoverable from the XML, and #114 records what happens when they
     * are dropped on the floor.
     *
     * $openIban opens a sealed IBAN ciphertext with the temporarily supplied
     * private key (ADR-0036): `fn(string $ciphertext): string`. It is invoked
     * per row, right where the plaintext goes into the XML segment, so no
     * plaintext collection ever exists. Every stored IBAN is sealed, so without
     * the callable no file can be built at all — a hard error, never a silent
     * exclusion, which would ship a short file that reads as complete.
     */
    public function export(string $settlementId, ?callable $openIban = null): SepaExportResultDto
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

        // The other half of the same rule (#114, ruling #142 as extended by
        // #148). A reversal is only reachable once money has moved, so a
        // settlement carrying one has already been collected; re-exporting it
        // would hand the bank a file that debits *every* member again — the
        // ones whose collection stood as much as the one that bounced. The
        // reversed member's position went back to unsettled and belongs to the
        // next run, which is where it gets collected from.
        $reversedMemberIds = $this->reversalsRepository->findReversedMemberIds($settlementId);
        if ($reversedMemberIds !== []) {
            throw new BusinessRuleException(sprintf(
                'Settlement %s has %d reversed collection(s) and cannot be exported to SEPA again; '
                . 'the reversed members are collected by the next settlement run',
                $settlementId,
                count($reversedMemberIds)
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

        /** @var list<ExcludedMemberDto> every member the file leaves out, with the reason */
        $excludedMembers = [];
        $collectedAmountCents = 0;
        /** @var array<string, string> member id => the EndToEndId their collection went out under */
        $sentEndToEndIds = [];
        foreach ($memberTotals as $entry) {
            // Deleted members included: the row is read to *report* the
            // exclusion, not to collect from it, and an omission reported as a
            // bare UUID is not something a treasurer can act on.
            $member = $this->membersRepository->findByIdIncludingDeleted($entry['member_id']);
            $amountCents = (int) $entry['amount_cents'];

            // #80 / ruling #141 (exclude-and-flag): guard the *signed* total.
            // abs() ran before the guard, so a net credit of -1500 became a
            // +1500 debit — collecting money the club owes the member
            // (§ 812 BGB). A credit gets no file line; it carries forward and
            // is refunded by hand. Tested first, as everywhere else: what the
            // club owes does not depend on the member's bank details.
            if ($amountCents < 0) {
                $excludedMembers[] = ExcludedMemberDto::fromMember(
                    $entry['member_id'],
                    $member,
                    $amountCents,
                    SepaExclusionReason::CREDIT_BALANCE,
                );
                continue;
            }

            // Only zero remains: it closes the rows out without a collection
            // instruction, and is not an exclusion — nothing is owed either
            // way, so it can neither be collected nor fall short.
            if ($amountCents === 0) continue;

            // Everything below here is money the settlement records as owed.
            // Each of these used to be a bare `continue` (#114): the file came
            // out short, the settlement kept its full total, and nobody was
            // told which member the difference belonged to.
            if ($member === null || !empty($member['deleted_at'])) {
                $excludedMembers[] = ExcludedMemberDto::fromMember(
                    $entry['member_id'],
                    $member,
                    $amountCents,
                    SepaExclusionReason::MEMBER_DELETED,
                );
                continue;
            }

            // The mandate is read again here, not trusted from settlement
            // creation: it can be revoked or its IBAN cleared in between, and
            // a direct debit without a live mandate is one the bank returns.
            if (empty($member['has_iban']) || empty($member['mandate_reference'])) {
                $excludedMembers[] = ExcludedMemberDto::fromMember(
                    $entry['member_id'],
                    $member,
                    $amountCents,
                    SepaExclusionReason::NO_ACTIVE_MANDATE,
                );
                continue;
            }

            $plainIban = $this->resolvePlainIban($entry['member_id'], $openIban);

            $collectedAmountCents += $amountCents;
            $endToEndId = EndToEndId::forCollection($settlement['id'], $entry['member_id']);
            $sentEndToEndIds[$entry['member_id']] = $endToEndId;

            $directDebit->addTransfer(
                $paymentId,
                [
                    'amount' => $amountCents,
                    'endToEndId' => $endToEndId,
                    'debtorIban' => $this->sanitizeIban($plainIban),
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

        // Only once the file is known to be well-formed: an export that threw
        // sent nothing, and must leave no record claiming otherwise. From here
        // on a return quoting `EREF+` resolves to these rows (#150).
        $this->settlementsRepository->storeEndToEndIds($settlementId, $sentEndToEndIds);

        $result = new SepaExportResultDto(
            xml: $xml,
            excludedMembers: $excludedMembers,
            collectedAmountCents: $collectedAmountCents,
            settlementAmountCents: (int) ($settlement['total_amount_cents'] ?? 0),
            collectedMemberCount: count($sentEndToEndIds),
        );

        $this->warnAboutShortfall($settlementId, $result);

        return $result;
    }

    /**
     * A shortfall means the bank file and the books disagree, and the club is
     * about to collect less than it recorded. The caller reports it to the
     * treasurer and the export's audit entry keeps it permanently; this puts
     * it in the application log too, because the one thing #114 describes is a
     * divergence nobody could see afterwards.
     *
     * Ids only, for the reason the audit summary gives: no erasure reaches a
     * rotated log file, so a name written here is a name kept forever (#115).
     */
    private function warnAboutShortfall(string $settlementId, SepaExportResultDto $result): void
    {
        $uncollectable = $result->uncollectableMembers();
        if ($uncollectable === []) {
            return;
        }

        $this->logger->warning('SEPA export collects less than the settlement records', [
            'settlement_id' => $settlementId,
            'settlement_amount_cents' => $result->settlementAmountCents,
            'collected_amount_cents' => $result->collectedAmountCents,
            'shortfall_amount_cents' => $result->shortfallAmountCents(),
            'excluded_members' => array_map(
                static fn(ExcludedMemberDto $member): array => $member->toAuditArray(),
                $uncollectable,
            ),
        ]);
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

    /**
     * The plaintext IBAN for one member, alive only for the XML segment being
     * built: legacy rows read it directly, sealed rows go through $openIban
     * with the private key the caller supplied for this request (ADR-0036).
     */
    private function resolvePlainIban(string $memberId, ?callable $openIban): string
    {
        $sealed = $this->membersRepository->findSealedIban($memberId);

        if ($sealed === null) {
            // has_iban said a mandate exists; losing it between the two reads
            // is a race worth failing loudly on, not exporting around.
            throw new BusinessRuleException(sprintf('Active mandate for member %s vanished during export', $memberId));
        }

        if ($openIban === null) {
            throw new BusinessRuleException(
                'Every stored IBAN is sealed; the SEPA export requires the club\'s private key (ADR-0036).'
            );
        }

        return $openIban($sealed['iban_ciphertext']);
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
