# UC-SEPA-06: Settlement Preview

**Implementation Status**: Implemented

**Category**: Settlement

## Summary

Admin reviews settlement details before finalization, including member totals and validation issues.

## Actors

- **Admin**: Reviews settlement

## Preconditions

1. Settlement exists (draft status)
2. Admin is logged in

## Trigger

Admin views settlement details or proceeds from creation.

## Preview Information

### Summary Section

| Field | Description |
|-------|-------------|
| Settlement ID | UUID |
| Period | Start date - End date |
| Total Amount | Sum of all member balances |
| Member Count | Number of members with balance |
| Transaction Count | Total transactions included |

### Member List

| Column | Description |
|--------|-------------|
| Member Name | First + Last name |
| Transaction Count | Number of transactions |
| Total Amount | Sum of member's transactions |
| IBAN Status | Valid / Missing / Invalid |
| Mandate Status | Present / Missing |

### Validation Warnings

| Warning | Condition |
|---------|-----------|
| Missing IBAN | Member has no IBAN |
| Invalid IBAN | IBAN fails checksum |
| Missing Mandate | No mandate reference |
| Zero Balance | Member total = €0.00 |
| Negative Balance | Member total < €0.00 |

## Main Flow

1. Admin opens settlement details
2. System displays summary section
3. System displays member list with totals
4. System highlights validation issues
5. Admin reviews each warning
6. Admin decides to proceed or resolve issues

## Alternative Flows

### AF1: Members with missing SEPA data
- Step 4: Members flagged with warning icon
- Admin can exclude members or update their data first

### AF2: All members have issues
- Step 4: "No valid members for SEPA export" warning
- Finalization possible but SEPA export will be empty

### AF3: Zero total amount
- Step 2: Warning "Settlement total is €0.00"
- Admin may cancel or proceed

## Postconditions

- No data modified (preview is read-only)
- Admin informed of all issues before finalization

## Export Eligibility

Member eligible for SEPA XML export if:
- Has valid IBAN (checksum passes)
- Has mandate reference (non-empty)
- Has non-zero balance
- Is active and not anonymized

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Preview with all valid members | No warnings |
| T02 | Preview with missing IBAN | Warning shown for member |
| T03 | Preview with invalid IBAN | Warning shown for member |
| T04 | Preview with missing mandate | Warning shown for member |
| T05 | Preview with zero balance member | Warning or auto-exclusion |
| T06 | Preview with negative balance | Warning shown |
| T07 | Total amount calculation | Correct sum displayed |
| T08 | Member count accuracy | Correct count displayed |
| T09 | Transaction count accuracy | Correct count displayed |
| T10 | Preview without authentication | Access denied (401) |
| T11 | Anonymized member in period | Not shown in preview |
