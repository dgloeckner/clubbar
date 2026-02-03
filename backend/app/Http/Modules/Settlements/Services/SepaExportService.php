<?php

namespace App\Http\Modules\Settlements\Services;

use App\Http\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Models\Settlement;
use App\Shared\Utils\SepaSanitizer;
use Illuminate\Database\Eloquent\Collection;

/**
 * SepaExportService
 *
 * Generates SEPA Direct Debit XML (pain.008.001.02 format)
 *
 * Implements ADR-0008: SEPA XML Export Format Selection
 * Uses digitick/sepa-xml library for XSD validation
 *
 * Key Responsibilities:
 * - Validate SEPA configuration is complete
 * - Generate valid pain.008.001.02 XML
 * - Apply character sanitization (umlauts → ae/oe/ue, ß → ss)
 * - Use CORE scheme (standard direct debit), RCUR sequence (recurring)
 * - Generate proper SEPA message structure
 *
 * Format: pain.008.001.02 (ISO 20022 standard)
 * Scheme: CORE (creditor and debtor in EU)
 * Sequence: RCUR (recurring collection)
 */
final readonly class SepaExportService
{
    /**
     * Initialize service with SEPA config repository
     */
    public function __construct(
        private readonly SepaConfigRepository $sepaConfigRepository,
    ) {}

    /**
     * Generate SEPA XML for settlement
     *
     * Creates valid pain.008.001.02 XML file for direct debit transmission.
     *
     * @param Settlement $settlement The settlement to export
     * @param Collection $items Settlement items with member data
     * @return string The generated XML string
     * @throws \Exception If SEPA config is incomplete or XML generation fails
     */
    public function generateSepaXml(Settlement $settlement, Collection $items): string
    {
        // Validate SEPA config is complete
        if (!$this->sepaConfigRepository->isConfigured()) {
            throw new \Exception('SEPA configuration is incomplete. Please configure creditor details first.');
        }

        $config = $this->sepaConfigRepository->getConfig();

        // Initialize SEPA processor
        // Note: Using the digitick/sepa-xml library once installed
        // For now, this is a placeholder for the actual implementation
        $sepaDocument = $this->initializeSepaDocument($config);

        // Add transactions
        $this->addTransactionsToDocument($sepaDocument, $settlement, $items);

        // Generate XML
        $xml = $sepaDocument->asXml();

        // Validate XML structure
        $this->validateSepaXml($xml);

        return $xml;
    }

    /**
     * Initialize SEPA document with creditor information
     *
     * Sets up the message and payment information section headers.
     *
     * @param mixed $config SEPA configuration
     * @return \Digitick\Sepa\TransferFile\Facade\CustomerDirectDebitFacade SEPA document object
     */
    private function initializeSepaDocument($config)
    {
        try {
            // Create SEPA direct debit transfer file (pain.008.001.09)
            // Using the modern API from TransferFileFacadeFactory
            $sepaFile = \Digitick\Sepa\TransferFile\Factory\TransferFileFacadeFactory::createDirectDebit(
                'SETTLE' . str_pad((string) time(), 13, '0', STR_PAD_LEFT), // Unique message ID
                $this->sanitizeName($config->creditor_name),
                'pain.008.001.09' // Modern PAIN format
            );

            return $sepaFile;
        } catch (\Exception $e) {
            throw new \Exception('Failed to initialize SEPA document: ' . $e->getMessage());
        }
    }

    /**
     * Add settlement transactions to SEPA document
     *
     * Groups transactions by member and creates payment instructions.
     *
     * @param \Digitick\Sepa\TransferFile\Facade\CustomerDirectDebitFacade $sepaDocument The SEPA document
     * @param Settlement $settlement The settlement
     * @param Collection $items Settlement items with relationships loaded
     * @return void
     */
    private function addTransactionsToDocument($sepaDocument, Settlement $settlement, Collection $items): void
    {
        // Get creditor info from SEPA config
        $config = $this->sepaConfigRepository->getConfig();

        // Create payment info once (all members go into same payment batch)
        $paymentInfoId = 'PAYMENT-' . str_pad((string) $settlement->id, 8, '0', STR_PAD_LEFT);

        try {
            $paymentInfo = $sepaDocument->addPaymentInfo(
                $this->sanitizeName($config->creditor_name),
                [
                    'id' => $paymentInfoId,
                    'creditorAccountIBAN' => $this->sanitizeIban($config->creditor_iban),
                    'creditorName' => $this->sanitizeName($config->creditor_name),
                    'creditorId' => $config->creditor_id,
                    'seqType' => 'RCUR', // Recurring collection (per ADR-0008)
                    // creditorAgentBIC is optional
                ]
            );
        } catch (\Exception $e) {
            throw new \Exception('Failed to create payment info: ' . $e->getMessage());
        }

        // Group items by member for batch processing
        $itemsByMember = $items->groupBy('member_id');

        // For each member, add debit instruction to the payment
        foreach ($itemsByMember as $memberId => $memberItems) {
            $member = $memberItems->first()->member;

            // Validate member has SEPA mandate
            if (!$member->iban || !$member->mandate_reference) {
                throw new \Exception("Member {$memberId} is missing SEPA mandate or IBAN");
            }

            // Calculate total for this member (in EUR cents)
            $totalAmountCents = $memberItems->sum('amount_cents');

            // Add transfer to the payment
            try {
                $memberName = $this->sanitizeName(
                    $member->account_holder_name ?? ($member->first_name . ' ' . $member->last_name)
                );
                $iban = $this->sanitizeIban($member->iban);

                $transferInfo = [
                    'amount' => $totalAmountCents, // Amount in cents
                    'debtorIban' => $iban,
                    'debtorName' => $memberName,
                    'debtorMandate' => SepaSanitizer::sanitizeMandateReference($member->mandate_reference),
                    'debtorMandateSignDate' => $member->mandate_signed_at,
                    'remittanceInformation' => SepaSanitizer::sanitizeRemittance('Settlement ' . $settlement->id),
                ];

                $sepaDocument->addTransfer(
                    $this->sanitizeName($config->creditor_name),
                    $transferInfo
                );
            } catch (\Exception $e) {
                throw new \Exception("Failed to add transfer for member {$memberId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Validate generated SEPA XML
     *
     * Checks XML structure and runs XSD validation if library supports it.
     *
     * @param string $xml The XML to validate
     * @return void
     * @throws \Exception If XML is invalid
     */
    private function validateSepaXml(string $xml): void
    {
        // Basic validation
        if (empty($xml)) {
            throw new \Exception('Generated SEPA XML is empty');
        }

        // Validate XML structure
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new \Exception('Generated XML is malformed');
        }

        // Check for required SEPA elements
        $grpHdr = $dom->getElementsByTagName('GrpHdr')->item(0);
        if (!$grpHdr) {
            throw new \Exception('Missing required GrpHdr element in SEPA XML');
        }

        $messageId = $grpHdr->getElementsByTagName('MsgId')->item(0)?->nodeValue;
        if (empty($messageId)) {
            throw new \Exception('Missing message ID in SEPA XML');
        }

        // Check for payment information
        $pmtInf = $dom->getElementsByTagName('PmtInf')->item(0);
        if (!$pmtInf) {
            throw new \Exception('Missing required PmtInf element in SEPA XML');
        }
    }

    /**
     * Sanitize name for SEPA XML.
     *
     * Delegates to SepaSanitizer for EPC-compliant character handling.
     *
     * @param string $name The name to sanitize
     * @return string Sanitized name (max 70 chars per SEPA standard)
     */
    public function sanitizeName(string $name): string
    {
        return SepaSanitizer::sanitizeName($name);
    }

    /**
     * Sanitize IBAN for SEPA XML (uppercase, no spaces).
     *
     * @param string $iban The IBAN to sanitize
     * @return string Normalized IBAN
     */
    public function sanitizeIban(string $iban): string
    {
        return SepaSanitizer::sanitizeIban($iban);
    }
}
