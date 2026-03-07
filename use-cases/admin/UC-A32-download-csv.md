# UC-A32: Download CSV

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Settlement exists

## Trigger
Admin clicks "Download CSV" on settlement

## Main Flow
1. Admin opens settlement details
2. Admin clicks "Download CSV"
3. System generates CSV
4. Browser downloads file

## CSV Format

```
member_name;iban;mandate_reference;amount;sepa_included
Max Mustermann;DE89370400440532013000;ABC123;45.50;yes
Erika Musterfrau;;;12.00;no
```

| Column | Content |
|--------|---------|
| member_name | Full name |
| iban | Member IBAN (if set) |
| mandate_reference | SEPA reference (if set) |
| amount | Settlement amount |
| sepa_included | yes/no (included in XML) |

## Filename Format
`frgs-abrechnung-YYYY-MM-DD.csv`

## Postconditions
- CSV file downloaded
- Audit log entry for download

## Use Cases
- Manual verification before bank upload
- Backup/archive
- Non-SEPA collection tracking (manual bank transfer, cash)

## Test Derivation
- Download CSV: valid file downloaded
- All members included: SEPA and non-SEPA
- Amounts correct: match settlement
- IBAN masked: last 4 digits visible only (or full, depending on policy)
- Audit log: download logged
