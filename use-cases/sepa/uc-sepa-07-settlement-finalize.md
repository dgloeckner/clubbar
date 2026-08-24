# UC-SEPA-07: Settlement Finalization

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: No separate finalize endpoint. Settlements are created in final state with execution_date validated at creation time. The preview step (UC-SEPA-06) serves as the review before creation. Confirmed acceptable by stakeholder.

**Category**: Settlement

## Summary

Admin finalizes a settlement, locking transactions and enabling export.

## Actors

- **Admin**: Finalizes settlement

## Preconditions

1. Settlement exists (draft status)
2. Admin is logged in
3. Execution date selected (see execution date rules)

## Execution Date Rules

| Rule | Value |
|------|-------|
| Minimum | TODAY + 7 calendar days, rolled to the next bank business day |
| Business day | Mon–Fri, excluding the six TARGET2 closing days |
| Format | Date (YYYY-MM-DD) |
| Purpose | Bank processing lead time; SEPA requires `ReqdColltnDt` to be a settlement day |

The lead time is counted in calendar days, but the resulting date must itself be
a business day — a weekend or TARGET2 closing day is rejected with 422 (ADR-0009,
issue #11). Clients obtain the earliest valid date from
`GET /admin/settlements/execution-date-info` rather than computing it.

## Main Flow

1. Admin opens settlement (draft)
2. Admin clicks "Finalize"
3. System displays finalization dialog
4. System shows minimum execution date
5. Admin selects execution date (≥ minimum)
6. System validates execution date
7. Admin confirms finalization
8. System updates settlement status
9. System assigns settlement_id to transactions
10. System generates SEPA message ID
11. System creates audit log entry
12. System displays confirmation

## Settlement Update

| Field | Before | After |
|-------|--------|-------|
| status | draft | finalized |
| sepa_execution_date | NULL | Selected date |
| sepa_message_id | NULL | Generated ID |
| finalized_at | NULL | Current timestamp |

## Transaction Update

All included transactions:
- `settlement_id` set to this settlement's UUID

## Settlement Reference

A settlement is named by **one** string everywhere: its own id with the hyphens
removed, 32 lowercase hex digits.

- Example: `3f9c2d1e7b4a4c8d9e2f1a5b6c7d8e9f`
- Derived from `settlements.id`; never stored, never allocated
- Used as the pain.008 `MsgId` and `PmtInfId`, in the Verwendungszweck the
  member reads on their bank statement, in the Vorabankündigung, in the CSV,
  and in every download's filename

> **Superseded.** This section previously specified `SET-{YYYY}-{NNN}` — a
> per-year running number that was never implemented. The code allocated a
> random `SEPA-<12 hex>` instead, and derived two *further* forms for
> `PmtInfId` and `EndToEndId`, so one settlement reached the bank under names
> that matched neither each other nor the admin panel. Consolidating on the id
> itself was chosen over building the running number: 32 characters fit the
> 35-character ISO 20022 fields without truncation, and the same string a
> member reads off a bank statement pastes into the admin lookup and matches.

`EndToEndId` is the one exception and keeps `E2E-<12 hex settlement>-<12 hex
member>`: it must name a member as well as a run *and* fit 35 characters, and
two 32-character references are 64.

## Alternative Flows

### AF1: Execution date too early
- Step 6: System shows "Execution date must be at least {date}"
- Admin selects valid date

### AF2: Settlement already finalized
- Step 2: "Finalize" button not available
- Cannot re-finalize

### AF3: No valid members for export
- Step 7: Warning "No members eligible for SEPA export"
- Admin can proceed (CSV-only) or cancel

## Postconditions

- Settlement status = finalized
- Transactions linked to settlement (immutable)
- SEPA XML export now available
- Cannot add/remove transactions

## Irreversibility

Finalization is **permanent**:
- Transactions cannot be un-settled
- Execution date cannot be changed
- To fix errors: storno the affected transactions ([UC-A23](../admin/UC-A23-storno.md)) and settle again — the storno derives its amount from the transaction it reverses, so nothing is typed in

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `settlement_finalize` |
| entity_type | `settlement` |
| entity_id | Settlement UUID |
| new_values | `{ "status": "finalized", "execution_date": "2025-02-01", "reference": "3f9c2d1e7b4a4c8d9e2f1a5b6c7d8e9f" }` |

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Finalize with valid date | Settlement finalized |
| T02 | Finalize with date < TODAY + 7 | Validation error |
| T03 | Finalize with date = TODAY + 7 | Settlement finalized |
| T04 | Finalize already finalized | Action not available |
| T05 | Finalize without authentication | Access denied (401) |
| T06 | Transactions get settlement_id | All included transactions updated |
| T07 | SEPA message ID generated | Valid format (SET-YYYY-NNN) |
| T08 | Audit log entry | Contains status, date, message_id |
| T09 | Finalize with no valid SEPA members | Warning, can proceed |
| T10 | Re-finalize after export | Not possible |
