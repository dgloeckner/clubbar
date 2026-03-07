# UC-SEPA-05: Settlement Creation

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: Settlements are created in one step with preview, not via a two-step draft-then-finalize workflow. Confirmed acceptable by stakeholder.

**Category**: Settlement

## Summary

Admin creates a new settlement period to collect outstanding member balances.

## Actors

- **Admin**: Creates settlement

## Preconditions

1. Admin is logged in
2. SEPA configuration is complete (UC-SEPA-01)
3. Unsettled transactions exist

## Trigger

Organization decides to collect outstanding balances (monthly, quarterly, etc.).

## Main Flow

1. Admin navigates to Settlements
2. Admin clicks "New Settlement"
3. System displays settlement creation form
4. Admin selects period start date
5. Admin selects period end date
6. System calculates included transactions
7. System shows preview (see UC-SEPA-06)
8. Admin reviews preview
9. Admin clicks "Create Settlement"
10. System creates settlement record (status: draft)
11. System displays settlement details

## Settlement Record

| Field | Value |
|-------|-------|
| id | Generated UUID |
| period_start | Selected start date |
| period_end | Selected end date |
| status | draft |
| created_at | Current timestamp |
| created_by_admin_id | Current admin |

## Transaction Selection

Transactions included if:
- `created_at` within period (start ≤ date ≤ end)
- `settlement_id` is NULL (not previously settled)
- Member is active (`is_active = true`)
- Member is not anonymized (`deleted_at` is NULL)

## Alternative Flows

### AF1: No unsettled transactions
- Step 6: System shows "No transactions for selected period"
- Settlement creation blocked

### AF2: SEPA config incomplete
- Step 3: System shows warning "SEPA configuration incomplete"
- Admin redirected to complete config first

### AF3: Period overlaps existing settlement
- Step 6: System shows warning about overlap
- Admin adjusts dates or proceeds with acknowledgment

## Postconditions

- Settlement record created (draft status)
- Transactions NOT yet marked as settled
- Can proceed to preview and finalization

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Create with valid period and transactions | Settlement created |
| T02 | Create with no transactions in period | Error or warning |
| T03 | Create with incomplete SEPA config | Warning shown |
| T04 | Period start after period end | Validation error |
| T05 | Create without authentication | Access denied (401) |
| T06 | Settlement ID is UUID | Valid UUID format |
| T07 | Multiple settlements same period | Allowed (transactions not yet assigned) |
| T08 | Inactive members excluded | Only active members in preview |
| T09 | Anonymized members excluded | Anonymized members not included |
| T10 | Draft status after creation | Status = draft, not finalized |
