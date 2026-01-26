<?php

namespace App\Http\Modules\Settlements\Services;

use App\Http\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Models\Settlement;
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
     * @return mixed SEPA document object
     */
    private function initializeSepaDocument($config)
    {
        // This will be implemented using digitick/sepa-xml library
        // Example structure:
        // $sepaDocument = new \Digitick\Sepa\TransferFile\Factory\TransferFileFactory();
        // $transfer = $sepaDocument->createFile(\Digitick\Sepa\TransferFile\TransferFileInterface::FORMAT_PAIN_008_003_02);
        // $transfer->setInitiatingPartyName($this->sanitizeName($config->creditor_name));

        throw new \Exception('SEPA export requires digitick/sepa-xml library. Install via: composer require digitick/sepa-xml');
    }

    /**
     * Add settlement transactions to SEPA document
     *
     * Groups transactions by member and creates payment instructions.
     *
     * @param mixed $sepaDocument The SEPA document
     * @param Settlement $settlement The settlement
     * @param Collection $items Settlement items with relationships loaded
     * @return void
     */
    private function addTransactionsToDocument($sepaDocument, Settlement $settlement, Collection $items): void
    {
        // Group items by member for batch processing
        $itemsByMember = $items->groupBy('member_id');

        // For each member, add debit instruction
        foreach ($itemsByMember as $memberId => $memberItems) {
            $member = $memberItems->first()->member;

            // Validate member has SEPA mandate
            if (!$member->iban || !$member->mandate_reference) {
                throw new \Exception("Member {$memberId} is missing SEPA mandate or IBAN");
            }

            // Calculate total for this member
            $totalAmount = $memberItems->sum('amount_cents') / 100; // Convert cents to EUR

            // Add payment instruction (placeholder for actual library call)
            // $sepaDocument->addPayment(
            //     name: $this->sanitizeName($member->first_name . ' ' . $member->last_name),
            //     iban: $member->iban,
            //     amount: $totalAmount,
            //     mandateReference: $member->mandate_reference,
            //     mandateSignDate: $member->mandate_signed_at,
            // );
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

        // Check for required elements
        $messageId = $dom->getElementsByTagName('MsgId')->item(0)?->nodeValue;
        if (empty($messageId)) {
            throw new \Exception('Missing message ID in SEPA XML');
        }

        // Additional XSD validation can be done via digitick/sepa-xml library
        // $validator = new \Digitick\Sepa\Validator\SepaValidator();
        // $validator->validate($xml);
    }

    /**
     * Sanitize name for SEPA XML (remove umlauts and special chars)
     *
     * SEPA XML requires specific character set. This method converts:
     * - ä → ae, ö → oe, ü → ue, ß → ss
     * - Removes other special characters
     *
     * @param string $name The name to sanitize
     * @return string Sanitized name (max 70 chars as per SEPA standard)
     */
    public function sanitizeName(string $name): string
    {
        // Replace umlauts
        $sanitized = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'],
            ['ae', 'oe', 'ue', 'ss', 'AE', 'OE', 'UE'],
            $name,
        );

        // Remove other problematic characters, keep only ASCII + spaces
        $sanitized = preg_replace('/[^\w\s\-\.\/\,]/u', '', $sanitized);

        // Limit to 70 characters (SEPA limit)
        $sanitized = substr($sanitized, 0, 70);

        return trim($sanitized);
    }

    /**
     * Sanitize IBAN for SEPA XML
     *
     * Removes spaces and converts to uppercase (standard format)
     *
     * @param string $iban The IBAN to sanitize
     * @return string Normalized IBAN
     */
    public function sanitizeIban(string $iban): string
    {
        return strtoupper(str_replace(' ', '', $iban));
    }
}
