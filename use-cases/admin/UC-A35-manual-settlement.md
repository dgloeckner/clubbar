# UC-A35: Manual Settlement

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- At least one unsettled transaction exists

## Trigger
- Admin clicks "Manual Settlement" in Settlements menu, OR
- Admin clicks "Manual Settlement" on member detail, OR
- Admin clicks "Manual Settlement" from SEPA Issues report

## Overview

Marks selected transactions as settled **without SEPA export**. Used for:
- Members without valid SEPA data (IBAN or mandate missing)
- Payments received via alternative methods (cash, bank transfer, PayPal, etc.)
- Balance write-offs (uncollectable debt, goodwill, etc.)

Works on a list of selected transactions. Admin can settle any combination of transactions from any members.

## Main Flow

1. Admin opens Manual Settlement
2. System displays transaction selection view:
   - All open transactions (grouped by member)
   - Pre-filtered based on entry point (see Entry Points below)
3. Admin selects transactions to settle
4. Admin clicks "Continue"
5. System displays settlement summary:
   - Selected transaction count
   - Total amount
   - Member count
6. Admin selects settlement reason
7. Admin enters comment (required, min 10 characters)
8. Admin confirms
9. System creates settlement record (type: `manual`)
10. System marks selected transactions as settled
11. System displays confirmation

## Entry Points

| Entry Point | Default Selection |
|-------------|-------------------|
| Settlements menu | None (all transactions shown, none selected) |
| Member detail | That member's transactions pre-selected |
| SEPA Issues report | Selected members' transactions pre-selected |
| UC-A30 "Manual Settlement" link | Clicked member's transactions pre-selected |

## Transaction Selection View

| Column | Description |
|--------|-------------|
| ☑ | Checkbox (transaction-level) |
| Member | Member name (grouped header) |
| SEPA Status | Valid / Invalid (indicator) |
| Date | Transaction date |
| Product | Product name or "Manual entry" |
| Amount | Transaction amount |
| Member Total | Sum of selected transactions for member |

**Selection Controls:**
- "Select All" → selects all transactions
- "Select None" → deselects all
- Member row checkbox → toggles all transactions for that member
- Individual transaction checkbox → toggles single transaction

**Note:** Unlike SEPA settlement, manual settlement can include transactions from ANY member (SEPA-valid or SEPA-invalid).

## Filters

| Filter | Options |
|--------|---------|
| SEPA Status | All, SEPA Valid, SEPA Invalid |
| Date range | From date, To date |
| Member | Search by name |
| Amount | Min, Max |

## Settlement Reasons

> **Rewritten 2026-08-07** ([#163](https://github.com/dgloeckner/ruderbar/issues/163)). `settlement_type` + `manual_reason` are replaced by a **single method field**, and four of the seven reasons below are removed.

A settlement carries one **method**:

| Method | Members | Produces a file | Money |
|--------|---------|-----------------|-------|
| `direct_debit` | many | yes, pain.008 | collected by the bank |
| `bank_transfer` | **exactly one** | no | already arrived |
| `write_off` | **exactly one** | no | never arrives |

**Only `direct_debit` settlements may be exported.** That is what prevents a settlement recorded as already-paid from being sent to the bank and collected a second time.

### Removed methods

| Removed | Why |
|---|---|
| `cash_payment` | The club takes **no cash**, as policy — this is what holds up the KassenSichV position in [ADR-0028 §6](../../adr/0028-legal-constraints-on-money-handling.md) |
| `goodwill` | Ruled out entirely ([#170](https://github.com/dgloeckner/ruderbar/issues/170)) — it was value from nothing, with no linked event. Never existed in the backend; it was spec drift |
| `correction` | Corrections are stornos on individual transactions ([UC-A23](./UC-A23-storno.md)), never settlements |
| `other_payment`, `other` | With cash gone and SEPA-only, there is no fourth way money moves. Needing `other` would mean something is undesigned |

### Scope and cancellation

`bank_transfer` and `write_off` cover **exactly one member and that member's whole unsettled position**. There is no transaction picker and no typed amount, so a partial payment cannot be expressed: if a member transfers less than they owe, no settlement is recorded and the tab stands.

Cancellation follows one rule — **a settlement can be cancelled while no money has moved**:

| Method | Cancellable |
|---|---|
| `direct_debit` | until submitted, execution date as backstop |
| `bank_transfer` | **never** — the money already arrived |
| `write_off` | yes |

⚠️ **`write_off` is reachable only through member offboarding** ([#173](https://github.com/dgloeckner/ruderbar/issues/173)). It cannot be initiated standalone: under SEPA-only a member who cannot pay is locked out of the bar, so their debt stops growing and does not need forgiving while they remain a member.

## Comment Requirements

- Minimum 10 characters
- Should explain the circumstances
- Stored in settlement record for audit trail

## Settlement Summary

| Field | Description |
|-------|-------------|
| Transactions | Count of selected transactions |
| Members | Count of unique members |
| Total Amount | Sum of selected transactions |
| Reason | Selected reason |
| Comment | Admin's explanation |

## Postconditions

- Settlement record created (type: `manual`)
- Selected transactions marked as settled
- Member balances updated (for selected transactions only)
- Unselected transactions remain open
- Audit log entry with full details
- No SEPA XML generated

## Error Cases

### E1: No Transactions Selected
- Display "Select at least one transaction"

### E2: Comment Too Short
- Display "Comment must be at least 10 characters"

### E3: No Reason Selected
- Display "Select a settlement reason"

## Test Derivation

**Selection:**
- Select individual: single transaction settled
- Select by member: all member's transactions settled
- Mixed selection: transactions from multiple members
- Filter by SEPA status: only matching transactions shown
- Filter by date: only matching transactions shown

**Entry Points:**
- From menu: no pre-selection
- From member detail: member's transactions pre-selected
- From SEPA Issues: selected members' transactions pre-selected

**Reasons and Comments:**
- All reasons: each can be selected
- Comment required: cannot submit without comment
- Comment minimum: reject < 10 characters

**Mixed SEPA Status:**
- SEPA-valid included: can settle SEPA-valid members manually
- SEPA-invalid included: can settle SEPA-invalid members
- Both together: can mix in same settlement

**Audit:**
- Full details logged: transactions, members, reason, comment

## Related

- [UC-A30: Create Settlement (SEPA)](./UC-A30-create-settlement.md) - SEPA settlements
- [UC-A82: SEPA Issues Report](./UC-A82-sepa-invalid-report.md) - Members needing manual settlement
- [UC-A23: Storno](./UC-A23-storno.md) - Reverse an individual transaction
- [UC-A21: Manual Purchase](./UC-A21-manual-purchase.md) - Book a charge with no terminal involved
