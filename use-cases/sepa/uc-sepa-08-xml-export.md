# UC-SEPA-08: SEPA XML Export

**Implementation Status**: Implemented

**Category**: Export
**Format**: ISO 20022 pain.008.001.02

## Summary

Admin exports finalized settlement as SEPA Direct Debit XML file for bank upload.

## Actors

- **Admin**: Generates XML export

## Preconditions

1. Settlement is finalized
2. SEPA configuration complete
3. At least one member eligible for SEPA export
4. Admin is logged in

## Member Eligibility for XML

| Requirement | Check |
|-------------|-------|
| Valid IBAN | Mod-97 checksum passes |
| Mandate reference | Non-empty |
| Non-zero balance | Total > €0.00 |
| Active member | is_active = true |
| Not anonymized | deleted_at is NULL |

## XML Structure (pain.008.001.02)

### Group Header
| Element | Value |
|---------|-------|
| MsgId | Settlement message ID (SET-YYYY-NNN) |
| CreDtTm | Export timestamp (ISO 8601) |
| NbOfTxs | Number of transactions |
| CtrlSum | Total amount |
| InitgPty/Nm | Organization name |

### Payment Information
| Element | Value |
|---------|-------|
| PmtInfId | Same as MsgId |
| PmtMtd | DD (Direct Debit) |
| NbOfTxs | Transaction count |
| CtrlSum | Total amount |
| PmtTpInf/SvcLvl/Cd | SEPA |
| PmtTpInf/LclInstrm/Cd | CORE |
| PmtTpInf/SeqTp | RCUR (always recurring) |
| ReqdColltnDt | Execution date |
| Cdtr/Nm | Organization name |
| Cdtr/PstlAdr/Ctry | Country code |
| CdtrAcct/IBAN | Organization IBAN |
| CdtrSchmeId | Gläubiger-ID |

### Per Transaction
| Element | Value |
|---------|-------|
| EndToEndId | SET-YYYY-NNN-NNNN (padded sequence) |
| InstdAmt | Amount in EUR |
| DrctDbtTx/MndtRltdInf/MndtId | Mandate reference |
| Dbtr/Nm | Member full name |
| DbtrAcct/IBAN | Member IBAN |
| RmtInf/Ustrd | Purpose text |

## Main Flow

1. Admin opens finalized settlement
2. Admin clicks "Export SEPA XML"
3. System validates SEPA prerequisites
4. System generates XML using pain.008.001.02 format
5. System validates XML against XSD schema
6. System creates audit log entry
7. System downloads file to admin browser

## File Naming

Format: `sepa-{settlement-id}-{date}.xml`
Example: `sepa-SET-2025-001-20250123.xml`

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `export` |
| entity_type | `settlement` |
| entity_id | Settlement UUID |
| new_values | `{ "export_type": "sepa_xml", "format": "pain.008.001.02", "transaction_count": 42 }` |

## Alternative Flows

### AF1: No eligible members
- Step 3: System shows "No members eligible for SEPA export"
- Export blocked

### AF2: SEPA config incomplete
- Step 3: System shows missing config fields
- Admin must complete config first

### AF3: XML validation fails
- Step 5: System shows validation errors
- Should not occur with valid data; indicates bug

## Postconditions

- XML file downloaded
- Audit log records export
- Settlement data unchanged
- Can re-export multiple times

## Bank Upload (Outside System)

1. Admin logs into online banking
2. Admin navigates to SEPA Direct Debit upload
3. Admin uploads XML file
4. Bank validates and displays summary
5. Admin authorizes with TAN
6. Bank schedules execution for specified date

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Export with all valid members | XML generated |
| T02 | Export with no eligible members | Error message |
| T03 | Export with incomplete SEPA config | Error with missing fields |
| T04 | XML validates against XSD | No validation errors |
| T05 | MsgId matches settlement ID | Correct message ID |
| T06 | NbOfTxs correct | Matches eligible member count |
| T07 | CtrlSum correct | Matches total amount |
| T08 | SeqTp always RCUR | RCUR in XML |
| T09 | ReqdColltnDt matches execution date | Correct date in XML |
| T10 | Member IBAN in XML | Full IBAN (unmasked) |
| T11 | Mandate reference in XML | Correct reference per member |
| T12 | Re-export same settlement | Same XML generated |
| T13 | EndToEndId format | SET-YYYY-NNN-NNNN format |
| T14 | Audit log entry | Contains export details |
| T15 | Export without authentication | Access denied (401) |
