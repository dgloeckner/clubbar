# ADR-0008: SEPA XML Export Format Selection

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

The Member Bar system needs to export settlement data in SEPA Direct Debit format for bank processing. Two primary export formats exist:

1. **CSV**: Human-readable, manual control, import into bank tools
2. **SEPA XML**: Direct upload to online banking, automated processing

For SEPA XML, multiple ISO 20022 versions exist:

| Version | Format | Use Case | Status |
|---------|--------|----------|--------|
| **pain.008.001.02** | SEPA Core Direct Debit | Standard EU SEPA | ✅ Current standard |
| **pain.008.002.02** | SEPA Core Direct Debit (updated) | Newer EU variant | Supported by some banks |
| **pain.008.003.02** | SEPA Core Direct Debit (latest) | ISO 20022 2018 | Limited support |
| **pain.008.001.08** | SEPA Business-to-Business (B2B) | High-value B2B | Not applicable |

Additionally, SEPA Direct Debit has two schemes:

- **CORE**: Standard scheme (most common, all EU banks support)
- **COR1**: Express scheme (1 business day, limited bank support)

### Bank Support Reality

- **Frankfurter Sparkasse**: pain.008.001.02 + CORE ✅
- **Deutsche Bank**: pain.008.001.02 + CORE ✅, COR1 optional
- **Most EU banks**: pain.008.001.02 + CORE (standard)
- **Legacy banks**: May only accept 002.02

---

## Decision

**Use ISO 20022 pain.008.001.02 (SEPA Core Direct Debit) for XML export. Always use RCUR (recurring) sequence type for all collections. CORE scheme (2 business day lead time) with RCUR is pragmatic for small organizations; most banks accept RCUR for initial collections. Exports are generated on-demand, validated against XSD schema, and include comprehensive error reporting.**

### Core Principles

1. **pain.008.001.02 standard**: Widely supported, future-proof for current deployments
2. **CORE scheme only**: Meets standard requirements; COR1 can be added later
3. **Always RCUR sequence type**: Simplified; all collections treated as recurring
4. **Minimal debtor information**: Only debtor name and IBAN; no address data (privacy-first)
5. **XSD validation**: All generated XML validated against official SEPA schema
6. **Pragmatic ID generation**: Settlement ID used as base for all SEPA identifiers
   - Message ID = Settlement ID (e.g., `SET-2025-001`)
   - Payment Info ID = Settlement ID (e.g., `SET-2025-001`)
   - End-to-End ID = Settlement ID + sequence (e.g., `SET-2025-001-0001`, `SET-2025-001-0002`)
7. **Human-readable IDs**: Audit trail clarity; easy to trace transactions to settlements
8. **Comprehensive error handling**: Detailed validation errors before export
9. **UTF-8 encoding**: Mandatory for XML; charset declared in header

### XML Structure (pain.008.001.02)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02
                              pain.008.001.02.xsd">
  <CstmrDrctDbtInitn>
    <!-- Group Header (applies to entire file) -->
    <GrpHdr>
      <MsgId>SET-2025-001</MsgId>                            <!-- Message ID = Settlement ID -->
      <CreDtTm>2025-01-23T14:30:00Z</CreDtTm>                <!-- Creation timestamp (UTC) -->
      <NbOfTxs>2</NbOfTxs>                                   <!-- Total transaction count -->
      <CtrlSum>37.50</CtrlSum>                                <!-- Total amount in EUR -->
      <InitgPty>                                              <!-- Initiating Party (Organization) -->
        <Nm>FRGS Frankfurter Rudergesellschaft</Nm>
      </InitgPty>
    </GrpHdr>

    <!-- Payment Information (can have multiple, but FRGS uses single PmtInf) -->
    <PmtInf>
      <PmtInfId>SET-2025-001</PmtInfId>                      <!-- Payment Info ID = Settlement ID -->
      <PmtMtd>DD</PmtMtd>                                    <!-- Payment Method = Direct Debit -->
      <NbOfTxs>2</NbOfTxs>                                   <!-- Transaction count in this batch -->
      <CtrlSum>37.50</CtrlSum>                                <!-- Sum of transactions in batch -->

      <PmtTpInf>                                              <!-- Payment Type Info -->
        <SvcLvl>
          <Cd>SEPA</Cd>                                       <!-- Service Level = SEPA -->
        </SvcLvl>
        <LclInstrm>
          <Cd>CORE</Cd>                                       <!-- Local Instrument = CORE (standard) -->
        </LclInstrm>
        <SeqTp>RCUR</SeqTp>                                   <!-- Sequence Type: always RCUR -->
      </PmtTpInf>

      <ReqdColltnDt>2025-01-31</ReqdColltnDt>                <!-- Requested Collection Date (execution date) -->

      <!-- Creditor (Organization) -->
      <Cdtr>
        <Nm>FRGS Frankfurter Rudergesellschaft</Nm>
        <PstlAdr>
          <Ctry>DE</Ctry>
          <AdrLine>Mainufer 34, 60311 Frankfurt am Main</AdrLine>
        </PstlAdr>
      </Cdtr>

      <!-- Creditor Account -->
      <CdtrAcct>
        <Id>
          <IBAN>DE89370400440532013000</IBAN>
        </Id>
        <Ccy>EUR</Ccy>                                        <!-- Currency = EUR -->
      </CdtrAcct>

      <!-- Creditor Agent (Organization's Bank) -->
      <CdtrAgt>
        <FinInstnId>
          <BICFI>COBADEFFXXX</BICFI>                          <!-- BIC (derived from IBAN by bank) -->
        </FinInstnId>
      </CdtrAgt>

      <!-- Creditor Scheme Identification (Gläubiger-ID) -->
      <CdtrSchmeId>
        <Id>
          <PrvtId>
            <Othr>
              <Id>DE98ZZZ09999999999</Id>                     <!-- Gläubiger-ID -->
              <Issr>DE</Issr>
            </Othr>
          </PrvtId>
        </Id>
      </CdtrSchmeId>

      <!-- Direct Debit Transaction Information (repeated for each member) -->
      <DrctDbtTxInf>
        <PmtId>
          <EndToEndId>SET-2025-001-0001</EndToEndId>         <!-- End-to-End ID = Settlement ID + sequence -->
        </PmtId>

        <InstdAmt Ccy="EUR">25.50</InstdAmt>                 <!-- Instruction Amount -->

        <!-- Direct Debit Transaction -->
        <DrctDbtTx>
          <MndtRltdInf>
            <MndtId>550e8400e29b41d4a716446655440000</MndtId><!-- Mandate Reference (from member record) -->
          </MndtRltdInf>
        </DrctDbtTx>

        <!-- Debtor (Member) - Minimal information only -->
        <Dbtr>
          <Nm>Max Mustermann</Nm>
        </Dbtr>

        <!-- Debtor Account (Member's IBAN) -->
        <DbtrAcct>
          <Id>
            <IBAN>DE89370400440532013001</IBAN>               <!-- Member's IBAN -->
          </Id>
        </DbtrAcct>

        <!-- Remittance Information (Purpose) -->
        <RmtInf>
          <Ustrd>FRGS Bar Deckel Jan 2025</Ustrd>             <!-- Unstructured purpose text -->
        </RmtInf>
      </DrctDbtTxInf>

      <!-- Second transaction (another member) -->
      <DrctDbtTxInf>
        <PmtId>
          <EndToEndId>SET-2025-001-0002</EndToEndId>         <!-- End-to-End ID: incremented sequence -->
        </PmtId>

        <InstdAmt Ccy="EUR">12.00</InstdAmt>

        <DrctDbtTx>
          <MndtRltdInf>
            <MndtId>8a1c9012f45c48d9b872336e50f41c8e</MndtId>
          </MndtRltdInf>
        </DrctDbtTx>

        <Dbtr>
          <Nm>Erika Müller</Nm>
        </Dbtr>

        <DbtrAcct>
          <Id>
            <IBAN>DE89370400440532013002</IBAN>
          </Id>
        </DbtrAcct>

        <RmtInf>
          <Ustrd>FRGS Bar Deckel Jan 2025</Ustrd>
        </RmtInf>
      </DrctDbtTxInf>
    </PmtInf>
  </CstmrDrctDbtInitn>
</Document>
```

### Backend Implementation

#### Library: digitick/sepa-xml

Use the `digitick/sepa-xml` Composer package for SEPA XML generation:

```bash
composer require digitick/sepa-xml
```

**Advantages**:
- ✅ Tested, production-ready SEPA XML generation
- ✅ Automatic XSD validation built-in
- ✅ Handles pain.008.001.02 and 003.02 formats
- ✅ Proper handling of amounts, dates, character encoding
- ✅ Maintained by community; aligns with SEPA standards
- ✅ Reduces custom code; less maintenance burden

#### XML Generation Using digitick/sepa-xml

```php
<?php
require 'vendor/autoload.php';

use Digitick\Sepa\TransferFile\Factory\TransferFileFactory;
use Digitick\Sepa\TransferFile\TransferFileInterface;

class SEPAXmlExporter {
    private $config;  // Organization SEPA config
    private $settlement;
    private $transactions;

    public function __construct($settlementId) {
        $this->settlement = $db->selectOne('settlements', '*', ['id' => $settlementId]);
        $this->config = $this->loadSEPAConfig();
        $this->transactions = $this->loadSettlementTransactions($settlementId);
    }

    /**
     * Generate pain.008.001.02 XML using digitick/sepa-xml
     */
    public function generate() {
        // Validation before generation
        $this->validate();

        // Create transfer file (pain.008.001.02)
        $transferFile = TransferFileFactory::create('pain.008.001.02');

        // Use settlement ID for all SEPA identifiers
        $settlementId = $this->settlement['id'];

        // Set up payment information
        $payment = $transferFile->addPaymentInformation([
            'id' => generatePaymentInfId($settlementId),
            'executionDate' => new DateTime($this->settlement['sepa_execution_date']),
            'originName' => $this->config['creditor_name'],
            'originIban' => $this->config['creditor_iban'],
            'originCountry' => $this->config['creditor_address_country'],
            'originSchemeId' => [
                'id' => $this->config['creditor_id'],
                'issuer' => 'DE'
            ],
            'originAccount' => [
                'iban' => $this->config['creditor_iban'],
                'currency' => 'EUR'
            ]
        ]);

        // Always use RCUR (recurring) sequence type
        // Assumption: all mandates treated as previously used
        $payment->setSequenceType('RCUR');

        // Add transactions
        $totalAmount = 0;
        $transactionIndex = 0;
        foreach ($this->transactions as $txn) {
            $totalAmount += $txn['amount_cents'];

            $payment->addTransfer([
                'amount' => $txn['amount_cents'],  // In cents (digitick handles conversion)
                'debtorName' => $txn['first_name'] . ' ' . $txn['last_name'],
                'debtorIban' => $txn['iban'],
                'mandateId' => $txn['mandate_reference'],  // From member record
                'endToEndIdentification' => generateEndToEndId($settlementId, $transactionIndex),
                'remittanceInformation' => 'FRGS Bar Deckel',
                'instructedAmount' => $txn['amount_cents']  // In cents
            ]);

            $transactionIndex++;
        }

        // Generate XML (UTF-8, formatted)
        $xml = $transferFile->asXML();

        return $xml;
    }

    private function validate() {
        // Validation logic (see next section)
        // Check SEPA config, member data, execution dates, etc.
    }
}

// Usage
$exporter = new SEPAXmlExporter($settlementId);
$xml = $exporter->generate();

// Generate file
header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="settlement_sepa_' . date('Y-m-d') . '.xml"');
header('Content-Length: ' . strlen($xml));
echo $xml;
```

#### ID Generation Strategy

Pragmatic approach: Use settlement ID as base for all SEPA identifiers.

**Settlement ID format**: `SET-YYYY-NNN` (e.g., `SET-2025-001`)
- Can be auto-increment or date-based
- Human-readable for audit trail
- Max 35 chars (SEPA standard)

**Derived IDs**:
- **Message ID (MsgId)**: = Settlement ID (e.g., `SET-2025-001`)
- **Payment Info ID (PmtInfId)**: = Settlement ID (e.g., `SET-2025-001`)
- **End-to-End ID (EndToEndId)**: = Settlement ID + sequential number (e.g., `SET-2025-001-0001`, `SET-2025-001-0002`, ...)

#### Helper Functions

```php
<?php
/**
 * Message ID: Unique per settlement export
 * Pragmatic approach: use settlement ID as-is
 */
function generateMessageId($settlementId) {
    return $settlementId;  // e.g., "SET-2025-001"
}

/**
 * Payment Information ID: Identifies the batch
 * Pragmatic approach: use settlement ID as-is
 */
function generatePaymentInfId($settlementId) {
    return $settlementId;  // e.g., "SET-2025-001"
}

/**
 * End-to-End ID: Unique per transaction within file
 * Pragmatic approach: settlement ID + padded transaction sequence
 */
function generateEndToEndId($settlementId, $transactionIndex) {
    // $transactionIndex starts at 0
    $sequence = str_pad($transactionIndex + 1, 4, '0', STR_PAD_LEFT);
    return $settlementId . '-' . $sequence;  // e.g., "SET-2025-001-0001"
}

/**
 * Mandate Reference from member UUID
 */
function getMandateReference($memberId) {
    return str_replace('-', '', $memberId);
}
```

#### XSD Validation

digitick/sepa-xml handles XSD validation automatically during XML generation. If validation fails, an exception is thrown:

```php
<?php
try {
    $exporter = new SEPAXmlExporter($settlementId);
    $xml = $exporter->generate();
} catch (\Exception $e) {
    if (strpos($e->getMessage(), 'Invalid SEPA') !== false) {
        error_log('SEPA XML generation failed: ' . $e->getMessage());
        throw new ValidationException('Generated XML does not conform to SEPA standard',
            ['error' => $e->getMessage()]);
    }
    throw $e;
}
```

### Validation Checklist

Before generating XML:

```php
<?php
function validateForSEPAExport($settlement) {
    $errors = [];

    // SEPA Config
    $config = getSEPAConfig();
    if (!$config['creditor_id']) $errors[] = 'Creditor ID missing';
    if (!$config['creditor_iban']) $errors[] = 'Creditor IBAN missing';

    // Settlement data
    if (!$settlement['sepa_execution_date']) $errors[] = 'Execution date not set';
    if (strtotime($settlement['sepa_execution_date']) <= time()) $errors[] = 'Execution date must be in future';

    // Member data (per member)
    $members = getSettlementMembers($settlement['id']);
    foreach ($members as $member) {
        if (!$member['iban']) $errors[] = 'Member ' . $member['id'] . ' missing IBAN';
        if (!isValidIBAN($member['iban'])) $errors[] = 'Member ' . $member['id'] . ' invalid IBAN';
        if (!$member['mandate_reference']) $errors[] = 'Member ' . $member['id'] . ' missing mandate reference';
    }

    if (!empty($errors)) {
        throw new ValidationException('Cannot generate SEPA XML', $errors);
    }

    return true;
}
```

---

## Consequences

### Positive

✅ **Industry standard**: pain.008.001.02 widely supported by all EU banks
✅ **CORE scheme**: Meets standard 2-business-day requirement with RCUR
✅ **Simplified RCUR**: Always use RCUR (no FRST/RCUR logic); pragmatic for small orgs
✅ **Library-based**: digitick/sepa-xml handles XSD validation automatically
✅ **Tested & maintained**: Community-maintained library, proven in production
✅ **Reduced custom code**: No need to implement XML generation from scratch
✅ **Reduced complexity**: No mandate date tracking; only mandate_reference needed
✅ **Pragmatic ID strategy**: Settlement ID as base for all SEPA identifiers (simple, traceable, human-readable)
✅ **Easy audit trail**: Settlement IDs directly link transactions to settlement exports
✅ **UTF-8 support**: Library handles proper character encoding
✅ **No schema management**: Library includes and validates against XSD

### Negative

❌ **External dependency**: Requires digitick/sepa-xml package (must be maintained in composer.json)
❌ **XML verbosity**: Large file format (verbose compared to CSV)
❌ **Character handling**: Must sanitize member names for Latin-only SEPA compliance

### Mitigations

1. **Dependency management**: Use Composer for dependency tracking and updates
2. **Regular updates**: Keep digitick/sepa-xml updated with security patches
3. **Name sanitization**: Implement sanitization layer before passing to library
4. **Testing**: Comprehensive tests with digitick library to verify bank compatibility
5. **Error handling**: Catch and log digitick exceptions with actionable messages

---

## Alternatives Considered

### Alternative 1: Use pain.008.002.02

Newer version with updated format.

**Pros**: More recent standard, better spec
**Cons**:
- Limited bank support (especially German banks)
- Requires schema update
- Migration path unclear
- No functional advantage for FRGS

**Rejected**: pain.008.001.02 has broader support.

### Alternative 2: Use pain.008.003.02

Latest ISO 20022 2018 version.

**Pros**: ISO 20022 latest standard
**Cons**:
- Very limited bank support
- Few banks accept it yet
- May cause rejection

**Rejected**: Premature adoption; stick with proven standard.

### Alternative 3: Use DLV (Datei-Lieferdienste-Vektor)

German-specific format for bank uploads.

**Pros**: Some German banks prefer it
**Cons**:
- Less standardized than SEPA XML
- Limited to Germany
- Not portable to other EU banks
- More complex than SEPA

**Rejected**: SEPA XML more portable and standard.

### Alternative 4: Only CSV, No XML

Skip XML; generate only CSV for manual import.

**Pros**: Simpler, no schema management
**Cons**:
- Manual upload to bank portal (more error-prone)
- No direct banking integration
- Slower processing
- Higher manual effort

**Rejected**: XML automation is a key benefit for admins.

### Alternative 5: Custom XML Generation (SimpleXML/DOMDocument)

Build SEPA XML manually using PHP's SimpleXML or DOMDocument.

**Pros**:
- No external dependencies
- Full control over XML structure

**Cons**:
- Requires implementing SEPA XML generation from scratch
- Must handle XSD validation manually
- More code to maintain and test
- Higher risk of non-compliance with SEPA standards
- No community support for bug fixes
- Difficult to extend to other SEPA formats (COR1, pain.008.003.02)

**Rejected**: digitick/sepa-xml is tested, maintained, and proven. Custom XML generation introduces unnecessary complexity and maintenance burden.

---

## Implementation Checklist

### Backend

- [ ] Install digitick/sepa-xml via Composer: `composer require digitick/sepa-xml`
- [ ] Implement ID generation helper functions (generateMessageId, generatePaymentInfId, generateEndToEndId)
  - Message ID = Settlement ID
  - Payment Info ID = Settlement ID
  - End-to-End ID = Settlement ID + padded transaction sequence
- [ ] Implement SEPAXmlExporter class using digitick/sepa-xml library
- [ ] Pass transaction index to generateEndToEndId for each transaction
- [ ] Hard-code sequence type to RCUR (always)
- [ ] Implement pre-export validation (config, members, IBANs, mandate_reference) - before calling digitick
- [ ] Handle SEPA character encoding (Latin-1 + sanitization for names)
- [ ] Add error reporting with actionable messages (catch digitick exceptions)
- [ ] Add API endpoint: `GET /api/settlements/{id}/export-xml`
- [ ] Audit logging for XML generation (log which members included, amounts)
- [ ] Verify digitick library is handling XSD validation (automatic)

### Admin UI

- [ ] Add "Export XML" button alongside CSV export
- [ ] Show pre-export validation errors with fixes
- [ ] Allow viewing generated XML (for technical admins)
- [ ] Success message with file info (count, amount, execution date)

### Settlement Workflow

- [ ] Track sepa_message_id per settlement (already in schema)
- [ ] Track sepa_execution_date per settlement (already in schema)
- [ ] After export, log transaction in audit_trail

### Testing

- [ ] Valid XML generated (well-formed)
- [ ] XML validates against pain.008.001.02.xsd
- [ ] Message ID = Settlement ID (e.g., `SET-2025-001`)
- [ ] Payment Info ID = Settlement ID (e.g., `SET-2025-001`)
- [ ] End-to-End IDs follow pattern: Settlement ID + sequence (e.g., `SET-2025-001-0001`, `SET-2025-001-0002`)
- [ ] End-to-End IDs are unique and sequential within file
- [ ] Sequence type always RCUR in generated XML
- [ ] Control sums correct (NbOfTxs, CtrlSum)
- [ ] SEPA character encoding (no umlauts/accents in names)
- [ ] All transactions use RCUR (no FRST logic)
- [ ] Missing data (IBAN, mandate_reference) caught by validation
- [ ] Bank upload (test with actual bank, if possible)

### Documentation

- [ ] Update CLAUDE.md: Add SEPA XML export info
- [ ] Admin guide: SEPA XML export workflow
- [ ] Technical docs: XML generation, validation, XSD schema
- [ ] Troubleshooting: Common validation errors

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Settlement workflow
- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - Mandate reference in XML
- [ADR-0007: Organization SEPA Configuration](./0007-organization-sepa-configuration-storage.md) - Creditor info in XML
- [ADR-0009: Settlement Lead Times](./0009-settlement-lead-times-bank-working-days.md) - Execution date validation
- [ADR-0005: IBAN Storage and Validation](./0005-iban-storage-and-validation.md) - Member IBAN validation

---

## References

- **SEPA Standards**:
  - [EPC SEPA Rulebook](https://www.europeanpaymentscouncil.eu/) - Official specification
  - [pain.008.001.02 XSD Schema](https://www.iso20022.org/) - XML schema (obtain from EPC)

- **ISO 20022**:
  - [ISO 20022 Standard](https://www.iso20022.org/) - XML message format

- **Implementation Guides**:
  - [German Banking Association Guide](https://www.die-deutsche-boerse.de/) - Bank-specific requirements
  - [SEPA Direct Debit Implementation](https://www.ecb.europa.eu/paym/retpaym/governance/shared/pdf/recommendations_sepa_direct_debit.pdf)

---

## Approval

- **Decided by**: Architecture Team
- **Rationale**: pain.008.001.02 is industry standard; XSD validation ensures compliance; extensible for future enhancements
- **Implementation start**: Phase 2 (SEPA settlement)
- **Review date**: 2025-04-23 (after first bank upload)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - Payment Processing Lead: _________________ Date: _______
  - QA Lead: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] Track XML generation errors (validation failures)
- [ ] Monitor bank acceptance (any rejections?)
- [ ] Verify XSD validation catches errors before export
- [ ] Check SEPA character encoding (names with umlauts)
- [ ] Verify RCUR sequence type consistently used in all exports
- [ ] Verify ID patterns correct (Message/Payment Info = Settlement ID, End-to-End = Settlement ID + sequence)
- [ ] Verify End-to-End IDs sequential and unique per settlement
- [ ] Bank processing success rate
- [ ] Audit trail: Link transactions to settlement ID via End-to-End ID
- [ ] User feedback: Is XML export reliable?
- [ ] Audit log: Track all XML exports with settlement IDs
