# UC-SEPA-09: CSV Export

**Category**: Export

## Summary

Admin exports settlement as CSV file for manual processing or bank tool import.

## Actors

- **Admin**: Generates CSV export

## Preconditions

1. Settlement is finalized
2. Admin has `admin` role

## CSV Format

| Property | Value |
|----------|-------|
| Encoding | UTF-8 with BOM |
| Delimiter | Semicolon (;) |
| Line ending | CRLF |
| Decimal separator | Period (.) |
| Text qualifier | Double quotes (") |

## CSV Columns

| Column | Description | Example |
|--------|-------------|---------|
| Name | First + Last name | Max Mustermann |
| IBAN | Member IBAN | DE89370400440532013000 |
| Betrag | Amount in EUR | 42.50 |
| Verwendungszweck | Purpose text | Vereinsbar Abrechnung Jan 2025 |
| Mandatsreferenz | Mandate reference | 550e8400e29b41d4a716446655440000 |
| Mandatsdatum | Mandate signature date | (empty - not tracked) |
| Sequenztyp | FRST/RCUR/OOFF/FNAL | RCUR |

## Purpose Text Format

`{Organization Name} Abrechnung {Period}`
Example: `Vereinsbar Abrechnung Jan 2025`

## Main Flow

1. Admin opens finalized settlement
2. Admin clicks "Export CSV"
3. System generates CSV content
4. System creates audit log entry
5. System downloads file to admin browser

## File Naming

Format: `abrechnung-{settlement-id}-{date}.csv`
Example: `abrechnung-SET-2025-001-20250123.csv`

## Included Records

All members with:
- Non-zero balance in settlement
- Active status (is_active = true)
- Not anonymized

Note: Unlike SEPA XML, CSV may include members without IBAN (for reference/manual processing).

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `export` |
| entity_type | `settlement` |
| entity_id | Settlement UUID |
| new_values | `{ "export_type": "csv", "record_count": 42 }` |

## Alternative Flows

### AF1: No members in settlement
- Step 3: CSV contains only header row
- Warning shown to admin

### AF2: Member without IBAN
- Step 3: IBAN column empty for that member
- Admin must handle manually

## Postconditions

- CSV file downloaded
- Audit log records export
- Settlement data unchanged
- Can re-export multiple times

## Use Cases for CSV

| Scenario | Usage |
|----------|-------|
| Bank tool import | Some banks accept CSV for batch payments |
| Manual verification | Review before SEPA XML upload |
| Accounting export | Import into accounting software |
| Member notification | Basis for balance notifications |
| Archive | Human-readable settlement record |

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Export with members | CSV with data rows |
| T02 | Export with no members | CSV with header only + warning |
| T03 | UTF-8 BOM present | File starts with BOM bytes |
| T04 | Semicolon delimiter | Fields separated by ; |
| T05 | Amount format | Decimal with period (42.50) |
| T06 | Member without IBAN | Empty IBAN cell |
| T07 | Name with special chars | Quoted and escaped |
| T08 | Re-export same settlement | Same CSV generated |
| T09 | Audit log entry | Contains export details |
| T10 | Export by viewer role | Access denied |
| T11 | Purpose text format | Contains org name and period |
| T12 | Sequenztyp column | Always RCUR |
