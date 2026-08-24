# ADR-0008: SEPA XML Export Format Selection

**Status**: Accepted (amended 2026-08-04: format raised from pain.008.001.02 to pain.008.001.08; amended 2026-08-05: IBAN-only agents documented as `Othr/Id = NOTPROVIDED`, see issue #12; amended 2026-08-23: the identifier scheme is consolidated on the settlement reference and the remittance content is documented, see [#680](https://github.com/dgloeckner/clubbar/pull/680))

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

The Club Bar system needs to export settlement data in SEPA Direct Debit format for bank processing. Two primary export formats exist:

1. **CSV**: Human-readable, manual control, import into bank tools
2. **SEPA XML**: Direct upload to online banking, automated processing

For SEPA XML, multiple ISO 20022 versions exist:

| Version | Format | Use Case | Status |
|---------|--------|----------|--------|
| **pain.008.001.02** | SEPA Core Direct Debit (ISO 20022 2009) | Legacy EU SEPA | Superseded (EPC rulebooks until 2023) |
| **pain.008.002.02** / **pain.008.003.02** | German DK variants | Legacy German banking | Superseded |
| **pain.008.001.08** | SEPA Core Direct Debit (ISO 20022 2019) | Standard EU SEPA | ✅ Current standard (EPC 2023 rulebook, DK/EBICS since Nov 2025) |
| **pain.008.001.09+** | SEPA Core Direct Debit (later ISO releases) | Not EPC/DK-selected | May be rejected by banks |

Additionally, SEPA Direct Debit has two schemes:

- **CORE**: Standard scheme (most common, all EU banks support)
- **COR1**: Express scheme (1 business day, limited bank support)

### Bank Support Reality

- **EPC 2023 rulebook / Deutsche Kreditwirtschaft (EBICS)**: pain.008.001.08 + CORE is the required customer-to-bank format since November 2025
- **Most EU banks**: accept pain.008.001.08 + CORE; legacy pain.008.001.02 acceptance is being phased out

---

## Decision

**Use ISO 20022 pain.008.001.08 (SEPA Core Direct Debit, ISO 20022 2019 release) for XML export. Always use RCUR (recurring) sequence type for all collections. CORE scheme (2 business day lead time) with RCUR is pragmatic for small organizations; most banks accept RCUR for initial collections. Exports are generated on-demand, validated against XSD schema, and include comprehensive error reporting.**

### Core Principles

1. **pain.008.001.08 standard**: Required by EPC 2023 rulebook and Deutsche Kreditwirtschaft (EBICS) since November 2025
2. **CORE scheme only**: Meets standard requirements; COR1 can be added later
3. **Always RCUR sequence type**: Simplified; all collections treated as recurring
4. **Minimal debtor information**: Only debtor name and IBAN; no address data (privacy-first)
5. **IBAN-only agent identification**: Club Bar collects no agent BICs. `CdtrAgt` and `DbtrAgt` are both mandatory (1..1) in pain.008.001.08 and therefore cannot be omitted; the missing BIC is encoded as `Othr/Id = NOTPROVIDED` per the EPC/DK implementation guidelines. It must **not** be written to `BICFI`: the literal satisfies the `BICFIDec2014Identifier` pattern by accident (`NOTP`-`RO`-`VI`-`DED`), so it passes XSD validation while declaring a non-existent Romanian institution — which bank-side validators that resolve BICs against a directory will reject
6. **XSD validation**: All generated XML validated against official SEPA schema
7. **One identifier, derived from the settlement**: every id in the file is the settlement's **reference** — its UUID with the hyphens removed, 32 lowercase hex characters, inside the ISO 20022 35-character maximum without truncation
   - Message ID = the reference (e.g., `401f7c9dbf504925a3ea1c9c330a9333`)
   - Payment Info ID = the reference
   - End-to-End ID = the one exception, `E2E-` + 12 hex of the settlement + 12 hex of the member id (e.g., `E2E-401f7c9dbf50-7c1d55a09e34`)
8. **Quotable IDs**: the same string appears in the Verwendungszweck the member reads on their bank statement, in the Vorabankündigung, and in the admin panel — so a member can quote a collection and the Kassenwart can find it. Thirty-two hex characters are not "readable" in the sense of being memorable; the property that matters is that they **match by paste**, character for character
9. **Comprehensive error handling**: Detailed validation errors before export
10. **UTF-8 encoding**: Mandatory for XML; charset declared in header

### XML Structure (pain.008.001.08)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.08"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="urn:iso:std:iso:20022:tech:xsd:pain.008.001.08
                              pain.008.001.08.xsd">
  <CstmrDrctDbtInitn>
    <!-- Group Header (applies to entire file) -->
    <GrpHdr>
      <MsgId>401f7c9dbf504925a3ea1c9c330a9333</MsgId>      <!-- = the settlement reference -->
      <CreDtTm>2025-01-23T14:30:00Z</CreDtTm>                <!-- Creation timestamp (UTC) -->
      <NbOfTxs>2</NbOfTxs>                                   <!-- Total transaction count -->
      <CtrlSum>37.50</CtrlSum>                                <!-- Total amount in EUR -->
      <InitgPty>                                              <!-- Initiating Party (Organization) -->
        <Nm>Sportverein Beispiel e.V.</Nm>
      </InitgPty>
    </GrpHdr>

    <!-- Payment Information (can have multiple, but Club Bar uses single PmtInf) -->
    <PmtInf>
      <PmtInfId>401f7c9dbf504925a3ea1c9c330a9333</PmtInfId>   <!-- the same reference -->
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
        <Nm>Sportverein Beispiel e.V.</Nm>
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

      <!-- Creditor Agent (Organization's Bank) - IBAN-only, no BIC collected -->
      <CdtrAgt>
        <FinInstnId>
          <Othr>
            <Id>NOTPROVIDED</Id>                              <!-- IBAN-only: bank derives the agent from the IBAN -->
          </Othr>
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
          <EndToEndId>E2E-401f7c9dbf50-7c1d55a09e34</EndToEndId>  <!-- 12 hex of the settlement + 12 hex of the MEMBER id (not the mandate below); the one id that is not the plain reference -->
        </PmtId>

        <InstdAmt Ccy="EUR">25.50</InstdAmt>                 <!-- Instruction Amount -->

        <!-- Direct Debit Transaction -->
        <DrctDbtTx>
          <MndtRltdInf>
            <MndtId>550e8400e29b41d4a716446655440000</MndtId><!-- Mandate Reference (from member record) -->
          </MndtRltdInf>
        </DrctDbtTx>

        <!-- Debtor Agent (Member's Bank) - mandatory (1..1), IBAN-only -->
        <DbtrAgt>
          <FinInstnId>
            <Othr>
              <Id>NOTPROVIDED</Id>
            </Othr>
          </FinInstnId>
        </DbtrAgt>

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
          <Ustrd>Sportverein Beispiel Getraenke 2025-01-01 - 2025-01-31 401f7c9dbf504925a3ea1c9c330a9333</Ustrd>  <!-- prefix + period + reference; see ID Generation Strategy -->
        </RmtInf>
      </DrctDbtTxInf>

      <!-- Second transaction (another member) -->
      <DrctDbtTxInf>
        <PmtId>
          <EndToEndId>E2E-401f7c9dbf50-b93e2f7a0c18</EndToEndId>  <!-- same run, so the same first half; a different member, so a different second -->
        </PmtId>

        <InstdAmt Ccy="EUR">12.00</InstdAmt>

        <DrctDbtTx>
          <MndtRltdInf>
            <MndtId>8a1c9012f45c48d9b872336e50f41c8e</MndtId>
          </MndtRltdInf>
        </DrctDbtTx>

        <DbtrAgt>
          <FinInstnId>
            <Othr>
              <Id>NOTPROVIDED</Id>
            </Othr>
          </FinInstnId>
        </DbtrAgt>

        <Dbtr>
          <Nm>Erika Müller</Nm>
        </Dbtr>

        <DbtrAcct>
          <Id>
            <IBAN>DE89370400440532013002</IBAN>
          </Id>
        </DbtrAcct>

        <RmtInf>
          <Ustrd>Sportverein Beispiel Getraenke 2025-01-01 - 2025-01-31 401f7c9dbf504925a3ea1c9c330a9333</Ustrd>
        </RmtInf>
      </DrctDbtTxInf>
    </PmtInf>
  </CstmrDrctDbtInitn>
</Document>
```

### Data Flow Diagram: SEPA XML Export

The following sequence shows how settlement data flows through validation and XML generation:

```mermaid
sequenceDiagram
    participant Admin as Admin UI
    participant API as Backend API
    participant Validator as Validator
    participant Exporter as SEPA Exporter
    participant Digitick as digitick/sepa-xml
    participant Bank as Bank Portal

    Admin->>API: POST /settlements/{id}/export-xml
    API->>Validator: Validate SEPA readiness
    Validator->>Validator: Check creditor config<br/>(ID, IBAN, name)
    Validator->>Validator: Check execution date<br/>(>= TODAY + 7 days)
    Validator->>Validator: Check each member<br/>(IBAN, mandate_reference)
    alt Validation fails
        Validator-->>API: Return errors
        API-->>Admin: Show validation errors
    else Validation succeeds
        Validator-->>API: Validation OK
        API->>Exporter: Build SEPA XML
        Exporter->>Exporter: Map settlement to pain.008<br/>structure (Group Header,<br/>Payment Info, Transactions)
        Exporter->>Exporter: Use IDs:<br/>MsgId = settlement reference<br/>PmtInfId = settlement reference<br/>EndToEndId = E2E- + settlement half<br/>+ member half (persisted)
        Exporter->>Digitick: Generate XML<br/>(digitick handles encoding,<br/>formatting, XSD validation)
        Digitick-->>Exporter: Valid SEPA XML
        Exporter-->>API: XML ready
        API->>API: Log to audit_trail
        API-->>Admin: Download XML file
        Admin->>Bank: Upload to bank portal
    end
```

### Backend Architecture

**Library**: Use `digitick/sepa-xml` Composer package for SEPA XML generation (pain.008.001.08 format).

**Key responsibility areas**:
- **Validation**: Check SEPA config completeness, member IBAN/mandate data, execution date constraints
- **ID Mapping**: Derive every SEPA identifier from the settlement's own id (simple, traceable, and the same string the member sees)
- **Data Transformation**: Map transaction data to pain.008 XML elements (creditor, debtor, amounts, dates)
- **XSD Validation**: Leverage digitick library's built-in validation against pain.008.001.08 schema

### ID Generation Strategy

**A settlement has one identifier, and it is its own id.** The *settlement
reference* is `settlements.id` with the hyphens removed: 32 lowercase hex
characters, three inside the ISO 20022 maximum of 35, so nothing has to be
truncated to fit. It is derived on read by `Settlements\Domain\SettlementReference`
and stored nowhere — a column holding it would be a copy of the primary key, which
is the duplication [ADR-0032](./0032-settlement-lifecycle.md) §1 refuses for
`status`.

| Field | Value | Example |
|---|---|---|
| **MsgId** | the reference | `401f7c9dbf504925a3ea1c9c330a9333` |
| **PmtInfId** | the reference | `401f7c9dbf504925a3ea1c9c330a9333` |
| **EndToEndId** | `E2E-` + 12 hex of the settlement + 12 hex of the **member id** | `E2E-401f7c9dbf50-7c1d55a09e34` |
| **MndtId** | the member's mandate reference, unchanged | `550e8400e29b41d4a716446655440000` |
| **Ustrd** | configured prefix + period + reference | `Sportverein Beispiel Getraenke 2025-01-01 - 2025-01-31 401f7c9dbf504925a3ea1c9c330a9333` |

**EndToEndId is the one exception, for a structural reason.** It has to name a
*member* as well as a run and still fit 35 characters — and two references are 64.
So both halves are truncated. It is also the only identifier that is **persisted**
(`settlement_items.end_to_end_id`) rather than derived: German banks are required
by DK *DFÜ-Abkommen Anlage 3* to quote it back as `EREF+` on a return booking, and
a return arriving months later must resolve against the exact string that was sent
([#150](https://github.com/dgloeckner/clubbar/issues/150),
[ADR-0032](./0032-settlement-lifecycle.md) §8). It must never be a loop index: the
earlier `PMT-<settlement>-<n>` form counted the members that survived the export's
skip rules, so a cleared IBAN between two exports silently renumbered everyone
after it.

**The Verwendungszweck is the only text about the collection a member ever reads,**
so it names three things: what it is (the club's configured
`sepa_config.payment_reference_prefix`, or the literal `Settlement` where a club
has not set one), what it covers (the settlement's period,
falling back to its own date when `period_start`/`period_end` are unset), and which
run took it (the reference). The field allows 140 characters and the prefix column
allows 100, so the budget is spent tail-first: the period and the reference are
load-bearing and the **prefix** is what gets truncated to fit.

**Accepted: the MsgId is stable across re-exports.** It is a property of the
settlement, not of the export event, so exporting the same settlement twice
produces the same `MsgId`. This is deliberate — a re-export is the same collection
instruction, not a new one, and it is the same identity the treasurer sees
everywhere else. The consequence is worth knowing: a bank portal that runs a
duplicate-file check may refuse the second upload, and the remedy is to submit the
file that was already generated rather than to generate a new one. The behaviour
predates this amendment; the removed `sepa_message_id` was assigned at settlement
*creation*, never per export, so nothing about it changed here.

### Pre-Export Validation Checklist

Before generating SEPA XML, validate:

**SEPA Configuration**:
- ✓ Creditor ID (Gläubiger-ID) is set
- ✓ Creditor IBAN is set and valid
- ✓ Creditor name is set
- ✓ Organization country/address is set

**Settlement Data**:
- ✓ Execution date is set
- ✓ Execution date is in future (>= TODAY + 7 calendar days)
- ✓ Settlement has at least one transaction

**Per Member**:
- ✓ IBAN is present
- ✓ IBAN is valid (mod-97 checksum)
- ✓ Mandate reference is present
- ✓ Member name is present and non-empty

**Character Encoding**:
- ✓ Member names contain only SEPA-allowed characters (Latin-1)
- ✓ Sanitize umlauts/accents where necessary

---

## Consequences

### Positive

✅ **Industry standard**: pain.008.001.08 widely supported by all EU banks
✅ **CORE scheme**: Meets standard 2-business-day requirement with RCUR
✅ **Simplified RCUR**: Always use RCUR (no FRST/RCUR logic); pragmatic for small orgs
✅ **Library-based**: digitick/sepa-xml handles XSD validation automatically
✅ **Tested & maintained**: Community-maintained library, proven in production
✅ **Reduced custom code**: No need to implement XML generation from scratch
✅ **Reduced complexity**: No mandate date tracking; only mandate_reference needed
✅ **One identifier**: every id in the file is the settlement's own, so the file, the admin panel, the announcement mail and the audit entry all name the run the same way. This is what the ADR always claimed; until 2026-08-23 the code did not deliver it, generating a random `MsgId` and a truncated `PmtInfId` that matched neither each other nor the settlement
✅ **A member can quote a collection**: the reference is in the Verwendungszweck on their bank statement, so "what was this debit?" has an answer the Kassenwart can look up
✅ **Easy audit trail**: settlement references directly link transactions to settlement exports
✅ **UTF-8 support**: Library handles proper character encoding
✅ **No schema management**: Library includes and validates against XSD

### Negative

❌ **External dependency**: Requires digitick/sepa-xml package (must be maintained in composer.json)
❌ **XML verbosity**: Large file format (verbose compared to CSV)
❌ **Character handling**: Must sanitize member names for Latin-only SEPA compliance
❌ **A 32-character hex string is not memorable**: it is quotable and paste-matchable, not something a member reads out over the phone. A shorter running number was considered and rejected — see Alternative 6
❌ **Re-exports reuse the MsgId**: accepted, with the bank-side caveat spelled out in the ID Generation Strategy

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
- No functional advantage for Club Bar

**Rejected**: pain.008.001.08 has broader support.

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

### Alternative 6: A short per-year running number (`A-2026-0007`)

Give each settlement a sequential, human-sized number — prefix, calendar year, and
a counter that resets annually — and use that everywhere the reference is used now.

**Pros**:
- Short enough to read aloud, write on paper, and retype without error
- Sorts and reads chronologically at a glance
- Gapless, which is the *Vollständigkeitskontrolle* a GoBD Belegnummer exists for (GoBD Rz. 77 lists "eindeutige Belegnummer" as mandatory, though it accepts a Dokumenten-ID as one)

**Cons**:
- A counter is state: allocating it needs a lock or a counter table, and it can be allocated twice under two concurrent runs — the exact class of bug the settlement design avoids elsewhere by deriving rather than storing
- It is a *second* identity for a row that already has one. The admin URL, the audit entry and the API would still carry the UUID, so the system would name a settlement two ways rather than one — a smaller version of the problem this amendment exists to remove
- It cannot name a member, so `EndToEndId` would still need its own truncated form; the exception does not go away

**Rejected**: consistency beats brevity here. The whole value of the reference is
that one string matches across the bank statement, the mail and the admin panel;
a second, prettier identifier alongside the first would reintroduce the divergence.
A member never needs to *recite* a reference — they paste it, or the Kassenwart
searches for it. Revisit if the club finds itself reading references over the
telephone.

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Settlement workflow
- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - Mandate reference in XML
- [ADR-0007: Organization SEPA Configuration](./0007-organization-sepa-configuration-storage.md) - Creditor info in XML
- [ADR-0009: Settlement Lead Times](./0009-settlement-lead-times-bank-working-days.md) - Execution date validation
- [ADR-0005: IBAN Storage and Validation](./0005-iban-storage-and-validation.md) - Member IBAN validation
- [ADR-0032: Settlement Lifecycle](./0032-settlement-lifecycle.md) - why the reference is derived rather than stored (§1), and why the EndToEndId is persisted (§8)

---

## References

- **SEPA Standards**:
  - [EPC SEPA Rulebook](https://www.europeanpaymentscouncil.eu/) - Official specification
  - [pain.008.001.08 XSD Schema](https://www.iso20022.org/) - XML schema (obtain from EPC)

- **ISO 20022**:
  - [ISO 20022 Standard](https://www.iso20022.org/) - XML message format

- **Implementation Guides**:
  - [German Banking Association Guide](https://www.die-deutsche-boerse.de/) - Bank-specific requirements
  - [SEPA Direct Debit Implementation](https://www.ecb.europa.eu/paym/retpaym/governance/shared/pdf/recommendations_sepa_direct_debit.pdf)

---

## Post-Implementation Monitoring

- [ ] Track XML generation errors (validation failures)
- [ ] Monitor bank acceptance (any rejections?) — in particular, confirm a real bank file check accepts the `Othr/Id = NOTPROVIDED` agent encoding (issue #12)
- [ ] Verify XSD validation catches errors before export
- [ ] Check SEPA character encoding (names with umlauts)
- [ ] Verify RCUR sequence type consistently used in all exports
- [ ] Verify ID patterns correct (Message/Payment Info = Settlement ID, End-to-End = Settlement ID + sequence)
- [ ] Verify End-to-End IDs sequential and unique per settlement
- [ ] Bank processing success rate
- [ ] Audit trail: Link transactions to settlement ID via End-to-End ID
- [ ] User feedback: Is XML export reliable?
- [ ] Audit log: Track all XML exports with settlement IDs
