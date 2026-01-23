# UC-DSGVO-04: Right to Data Portability (Art. 20)

**GDPR Article**: Art. 20 - Right to data portability
**Response Deadline**: 1 month

## Summary

A member requests their data in a structured, machine-readable format for transfer to another service.

## Distinction from Art. 15 (Access)

| Aspect | Art. 15 (Access) | Art. 20 (Portability) |
|--------|------------------|----------------------|
| Data scope | All data about the person | Only data provided BY the person |
| Format | Any (PDF, human-readable) | Machine-readable (JSON, CSV) |
| Purpose | Transparency | Transfer to another controller |

## Actors

- **Member**: Requests portable data
- **Admin**: Generates export

## Preconditions

1. Member exists in system (not anonymized)
2. Admin has verified member identity
3. Admin has `admin` role

## Trigger

Member requests data portability (verbal, written, or email - outside system).

## Main Flow

1. Admin navigates to member detail page
2. Admin selects "GDPR Export" action
3. Admin selects "Portability (JSON)" format
4. System compiles portable data
5. System generates JSON file
6. System creates audit log entry
7. Admin downloads and provides to member

## Data Included (Provided by Member)

| Category | Fields | Included |
|----------|--------|----------|
| Personal data | first_name, last_name | Yes |
| Contact data | (if stored) | Yes |
| RFID card | card_uid | Yes |
| Banking | iban, mandate_reference | Yes |
| Transactions | All purchase records | Yes |

## Data Excluded (System-Generated)

| Category | Reason |
|----------|--------|
| UUID | Internal identifier |
| Calculated balance | Derived value |
| Audit log entries | System-generated |
| created_at, updated_at | System timestamps |
| Settlement summaries | Aggregated data |

## Export Structure

```
{
  "export_info": {
    "export_date": "ISO 8601 timestamp",
    "export_type": "gdpr_portability",
    "format_version": "1.0"
  },
  "personal_data": {
    "first_name": "...",
    "last_name": "...",
    "card_uid": "...",
    "iban": "...",
    "mandate_reference": "...",
    "member_since": "ISO 8601 date"
  },
  "transactions": [
    {
      "date": "ISO 8601 timestamp",
      "type": "consumption|correction",
      "product": "Product name",
      "quantity": 1,
      "unit_price_cents": 350,
      "total_cents": 350
    }
  ]
}
```

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `export` |
| entity_type | `member` |
| entity_id | Member UUID |
| new_values | `{ "export_type": "gdpr_portability", "format": "json" }` |

## Alternative Flows

### AF1: Member is anonymized
- Step 2: System shows "Member has been anonymized"
- No data available for export

## Postconditions

- JSON file generated and downloaded
- Audit log records export action
- No data modified

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Export for member with transactions | JSON contains personal data and transactions |
| T02 | Export for member without transactions | JSON contains personal data, empty transactions array |
| T03 | JSON structure validation | Valid JSON, matches schema |
| T04 | IBAN in export | Full IBAN (unmasked) |
| T05 | UUID not in export | personal_data does not contain id field |
| T06 | Balance not in export | No calculated_balance or similar field |
| T07 | Export for anonymized member | Export not available or error |
| T08 | Audit log entry | action=export, export_type=gdpr_portability |
| T09 | Monetary values | Stored as integer cents |
| T10 | Timestamps | ISO 8601 format |
