<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Members\Domain\MandateCompleteness;
use App\Modules\Settlements\Domain\EndToEndId;
use App\Modules\Settlements\Domain\SettlementReference;
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
use App\Shared\Exceptions\BusinessRuleReason;
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
            throw new BusinessRuleException(
                BusinessRuleReason::SETTLEMENT_CANCELLED_NOT_EXPORTABLE,
                sprintf('Settlement %s was cancelled and cannot be exported to SEPA', $settlementId),
                ['settlement_id' => $settlementId],
            );
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
            throw new BusinessRuleException(
                BusinessRuleReason::SETTLEMENT_HAS_REVERSALS,
                sprintf(
                    'Settlement %s has %d reversed collection(s) and cannot be exported to SEPA again; '
                    . 'the reversed members are collected by the next settlement run',
                    $settlementId,
                    count($reversedMemberIds)
                ),
                ['settlement_id' => $settlementId, 'reversed_count' => count($reversedMemberIds)],
            );
        }

        // #163: only direct_debit settlements were ever collected through the
        // SEPA mandate. Exporting a bank_transfer/write_off settlement would
        // double-collect the balance from the member's bank account (e.g. a
        // member pays by bank transfer, the treasurer records it as such, and
        // someone later exports it anyway — the bank would still debit them).
        $method = SettlementMethod::tryFrom($settlement['method'] ?? '') ?? SettlementMethod::DIRECT_DEBIT;
        if (!$method->isSepaExportable()) {
            throw new BusinessRuleException(
                BusinessRuleReason::SETTLEMENT_METHOD_NOT_EXPORTABLE,
                sprintf(
                    'Settlement %s uses method "%s" and cannot be exported to SEPA; '
                    . 'only direct_debit settlements are exportable',
                    $settlementId,
                    $method->value
                ),
                ['settlement_id' => $settlementId, 'method' => $method->value],
            );
        }

        $config = $this->sepaConfigRepository->getConfig();
        if (
            !$config
            || empty($config['creditor_id'])
            || empty($config['creditor_name'])
            || empty($config['creditor_iban'])
            // #360/#456: the blank mandate form moved out of the app to an
            // externally hosted registration form; a member has nothing to
            // sign until the club has told the system where it lives.
            || empty($config['mandate_template_url'])
        ) {
            throw new BusinessRuleException(
                BusinessRuleReason::SEPA_CONFIG_INCOMPLETE,
                'SEPA configuration incomplete',
            );
        }

        // Settlements created before the business-day rule existed (issue #11) can
        // still hold a weekend or TARGET2 closing date. Refuse rather than emit an
        // invalid ReqdColltnDt that a bank portal would reject.
        if (!BankingCalendar::isBusinessDay($settlement['execution_date'])) {
            throw new BusinessRuleException(
                BusinessRuleReason::EXECUTION_DATE_NOT_BUSINESS_DAY,
                sprintf(
                    'Settlement execution date %s is not a bank business day '
                    . '(Mon-Fri, excluding TARGET2 closing days); '
                    . 'cancel the settlement and recreate it with a valid date',
                    $settlement['execution_date']
                ),
                ['execution_date' => (string) $settlement['execution_date']],
            );
        }

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        if (empty($items)) {
            throw new BusinessRuleException(
                BusinessRuleReason::SETTLEMENT_HAS_NO_ITEMS,
                'Settlement has no items',
            );
        }

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
        //
        // One identifier, not three. MsgId used to be a random `SEPA-<12 hex>`
        // stored on the row and PmtInfId the first 16 hex digits of the UUID,
        // so a single settlement went to the bank under two names, neither of
        // which was the one in the admin panel. Both are now the canonical
        // reference, which is 32 characters against the ISO 20022 cap of 35.
        $reference = SettlementReference::of($settlement['id']);
        $messageId = $reference;
        $paymentId = $reference;
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
            //
            // All three parts are asked for, through the one predicate that
            // defines them (ADR-0020, #164) rather than a conjunction spelled
            // out again here — this loop used to hold one of the five copies
            // that had drifted, and the part it had dropped was the signature
            // date. That omission was not cosmetic: `debtorMandateSignDate`
            // fell back to `$settlement['settlement_date']`, writing the day
            // the treasurer pressed *export* into the bank file as the day the
            // member signed. #164 §3 answers whether it may ever fall back
            // with "never", because the alternative is asserting a mandate to
            // a bank on a member's behalf that the member never gave — and a
            // collection made without a valid mandate is not an authorised
            // direct debit at all. It is reclaimable for **13 months**
            // (§ 676b Abs. 2 BGB, ADR-0028 §3), not the eight weeks the
            // reversal model is built around.
            //
            // Deferring the collection costs the club a month; inventing the
            // date costs it the defence. The reason names which of the two
            // remedies applies, because they are not the same size: a missing
            // date is one field typed off a signed form, while missing bank
            // details are a member to chase.
            $missingMandateParts = MandateCompleteness::missingParts($member);
            if ($missingMandateParts !== []) {
                $excludedMembers[] = ExcludedMemberDto::fromMember(
                    $entry['member_id'],
                    $member,
                    $amountCents,
                    $missingMandateParts === ['mandate_signed_at']
                        ? SepaExclusionReason::NO_MANDATE_DATE
                        : SepaExclusionReason::NO_ACTIVE_MANDATE,
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
                    // No `??`: the gate above refuses a member without a
                    // signature date, so no branch remains that could invent
                    // one (#164 §3).
                    'debtorMandateSignDate' => $member['mandate_signed_at'],
                    'remittanceInformation' => $this->buildRemittanceInfo($config, $settlement, $reference),
                ]
            );
        }

        $settlementAmountCents = (int) ($settlement['total_amount_cents'] ?? 0);

        // #372: pain.008 cannot say "collect nothing". A PmtInf has to carry at
        // least one DrctDbtTxInf, so with no collection left the library still
        // emits a file — one with an empty payment block that a bank portal
        // rejects, and that the caller would meanwhile stamp onto the
        // settlement as exported. Refuse instead, and say which of the two
        // reasons applies: the settlement nets to zero, or every member in it
        // dropped out between creation and now (#114's exclusions, all at once).
        if ($sentEndToEndIds === []) {
            // Logged before the refusal, not instead of it: #114 is about a
            // divergence nobody could see afterwards, and an export that failed
            // is no reason to stop recording why.
            $this->warnAboutShortfall($settlementId, $excludedMembers, $settlementAmountCents, 0);

            // Two refusals, not one with an English fragment spliced in: the
            // panel has to say *which* case in the admin's language, and a
            // param carrying "every member in it owes 0.00 EUR" would put an
            // English clause inside a German sentence (#757).
            throw new BusinessRuleException(
                $excludedMembers === []
                    ? BusinessRuleReason::SEPA_EXPORT_NOTHING_OWED
                    : BusinessRuleReason::SEPA_EXPORT_EVERY_MEMBER_EXCLUDED,
                sprintf(
                    'Settlement %s has no collection left to export — %s. '
                    . 'A SEPA file needs at least one direct debit.',
                    $settlementId,
                    $this->describeEmptyFile($excludedMembers),
                ),
                ['settlement_id' => $settlementId, 'excluded_count' => count($excludedMembers)],
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
            settlementAmountCents: $settlementAmountCents,
            collectedMemberCount: count($sentEndToEndIds),
            // Date first so a folder of these sorts chronologically, then the
            // canonical reference so the file says which run it is. Every one
            // of them used to be `sepa-<raw uuid>.xml`.
            downloadName: sprintf('sepa-%s-%s.xml', $settlement['settlement_date'], $reference),
        );

        $this->warnAboutShortfall($settlementId, $excludedMembers, $settlementAmountCents, $collectedAmountCents);

        return $result;
    }

    /**
     * Why the file came out with nothing in it.
     *
     * Reasons and counts, never names or ids: this text becomes an exception
     * message, and every exception message is written to the application log,
     * which no erasure reaches (#115). The members themselves are on the
     * settlement, which is where the treasurer goes next.
     *
     * @param list<ExcludedMemberDto> $excludedMembers
     */
    private function describeEmptyFile(array $excludedMembers): string
    {
        if ($excludedMembers === []) {
            // No exclusions and no collection means every member in the run
            // owes exactly nothing — the storno-cancels-the-sale case (#372).
            return 'every member in it owes 0.00 EUR';
        }

        $counts = [];
        foreach ($excludedMembers as $member) {
            $label = $member->reason->label();
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $reasons = [];
        foreach ($counts as $label => $count) {
            $reasons[] = $count . ' × ' . $label;
        }

        return 'every member in it is excluded (' . implode('; ', $reasons) . ')';
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
     *
     * Takes the pieces rather than the result DTO because the refusal path of
     * #372 has no file to put in one, and its exclusions still have to be
     * recorded.
     *
     * @param list<ExcludedMemberDto> $excludedMembers
     */
    private function warnAboutShortfall(
        string $settlementId,
        array $excludedMembers,
        int $settlementAmountCents,
        int $collectedAmountCents,
    ): void {
        $uncollectable = array_values(array_filter(
            $excludedMembers,
            static fn(ExcludedMemberDto $member): bool => $member->reason->isShortfall(),
        ));
        if ($uncollectable === []) {
            return;
        }

        $this->logger->warning('SEPA export collects less than the settlement records', [
            'settlement_id' => $settlementId,
            'settlement_amount_cents' => $settlementAmountCents,
            'collected_amount_cents' => $collectedAmountCents,
            'shortfall_amount_cents' => array_sum(array_map(
                static fn(ExcludedMemberDto $member): int => $member->amountCents,
                $uncollectable,
            )),
            'excluded_members' => array_map(
                static fn(ExcludedMemberDto $member): array => $member->toAuditArray(),
                $uncollectable,
            ),
        ]);
    }

    /**
     * The Verwendungszweck — the only text about this collection that a member
     * ever reads, on their own bank statement.
     *
     * It used to be `<prefix> <settlement_date>`: no identifier at all, and the
     * date the run was created rather than the period the drinks were bought
     * in. A member asking "what was this?" had nothing to recognise and nothing
     * to quote, and the Kassenwart had nothing to look the question up by.
     *
     * Three things now, in the order a member reads them: what it is, what it
     * covers, and which run collected it.
     *
     * The 140-character budget is spent tail-first. The period and the
     * reference are fixed-width and load-bearing, so the **prefix** is what
     * gets truncated — the club's own name is the part a member can afford to
     * see abbreviated. Previously nothing capped the result at all; only the
     * prefix was sanitised, and with `sanitizeName`, whose limit is 70 rather
     * than the 140 this field allows.
     *
     * @param array<string,mixed> $config    The `sepa_config` row.
     * @param array<string,mixed> $settlement The settlement row.
     * @param string $reference The canonical reference, already in SEPA charset.
     */
    private function buildRemittanceInfo(array $config, array $settlement, string $reference): string
    {
        $tail = trim(self::describePeriod($settlement) . ' ' . $reference);

        // A leading space would survive into the field, and a negative budget
        // would make substr() cut from the right-hand end.
        $budget = SepaSanitizer::MAX_REMITTANCE - strlen($tail) - 1;
        $prefix = $budget > 0
            ? SepaSanitizer::sanitize((string) ($config['payment_reference_prefix'] ?? '') ?: 'Settlement', $budget)
            : '';

        return SepaSanitizer::sanitizeRemittance(trim($prefix . ' ' . $tail));
    }

    /**
     * What the collection covers, for the member's statement.
     *
     * `period_start` and `period_end` are nullable and, per ADR-0032 §2, are
     * descriptive labels rather than a boundary on the run's contents — a run
     * can sweep a transaction from outside them. They are still the closest
     * thing to "what you are paying for"; with neither recorded, the run's own
     * date is all there is to say.
     *
     * @param array<string,mixed> $settlement
     */
    private static function describePeriod(array $settlement): string
    {
        $start = $settlement['period_start'] ?? null;
        $end = $settlement['period_end'] ?? null;

        if ($start && $end) {
            return $start . ' - ' . $end;
        }

        return (string) ($start ?? $end ?? $settlement['settlement_date']);
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
            throw new BusinessRuleException(
                BusinessRuleReason::MANDATE_VANISHED_DURING_EXPORT,
                sprintf('Active mandate for member %s vanished during export', $memberId),
                ['member_id' => $memberId],
            );
        }

        if ($openIban === null) {
            throw new BusinessRuleException(
                BusinessRuleReason::IBAN_KEY_UNAVAILABLE,
                'Every stored IBAN is sealed; the SEPA export requires the club\'s private key (ADR-0036).',
            );
        }

        return $openIban($sealed['iban_ciphertext']);
    }

    private function validateSepaXml(string $xml): void
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            throw new BusinessRuleException(
                BusinessRuleReason::SEPA_XML_MALFORMED,
                'Generated SEPA XML is malformed',
            );
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('pain', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

        if (!$xpath->query('//pain:GrpHdr')->length) {
            throw new BusinessRuleException(
                BusinessRuleReason::SEPA_XML_MALFORMED,
                'SEPA XML missing GrpHdr element',
            );
        }
    }
}
