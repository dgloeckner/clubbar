# UC-DSGVO-01: Right to Access (Art. 15)

**GDPR Article**: Art. 15 - Right of access by the data subject
**Response Deadline**: 1 month (extendable by 2 months for complex cases)

## Summary

A member requests a complete overview of all personal data stored about them.

## Actors

- **Member**: Requests their data
- **Admin**: Processes the request and generates export

## Preconditions

1. Member exists in system (not anonymized)
2. Admin has verified member identity (out of system scope)
3. Admin has `admin` role

## Trigger

Member submits data access request (verbal, written, or email - outside system).

## Main Flow

1. Admin navigates to member detail page
2. Admin selects "GDPR Export" action
3. System displays export options (JSON, PDF, or both)
4. Admin confirms export generation
5. System compiles all member data
6. System generates export file(s)
7. System creates audit log entry
8. Admin downloads export and provides to member

## Data Included in Export

| Section | Fields |
|---------|--------|
| Personal data | first_name, last_name, card_uid, preferred_language, member_since, is_active |
| Banking data | iban (full, unmasked), mandate_reference |
| Transactions | date, product_name, quantity, unit_price, total_amount, type (consumption/correction), correction_reason |
| Settlements | period_start, period_end, total_amount, transaction_count, execution_date |
| Current balance | outstanding_amount, pending_transaction_count |

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `export` |
| entity_type | `member` |
| entity_id | Member UUID |
| new_values | `{ "export_type": "gdpr_access", "format": "json/pdf" }` |

## Alternative Flows

### AF1: Member is anonymized
- Step 2: System shows "Member has been anonymized"
- Export not possible; inform requester that data was deleted per prior request

### AF2: Export generation fails
- Step 6: System shows error message
- Admin retries or contacts support
- No audit log entry created

## Postconditions

- Export file(s) generated and downloaded
- Audit log records the export action
- No data modified

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Export for member with transactions | JSON/PDF contains all transactions |
| T02 | Export for member with no transactions | JSON/PDF contains personal data, empty transaction list |
| T03 | Export for member with settlements | JSON/PDF includes settlement records |
| T04 | Export for anonymized member | Export action not available or shows error |
| T05 | Export by viewer role | Access denied |
| T06 | Audit log entry created | Log contains action=export, correct member ID |
| T07 | IBAN in export | Full IBAN visible (not masked) |
