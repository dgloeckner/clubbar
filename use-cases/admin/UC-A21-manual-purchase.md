# UC-A21: Manual Purchase

**Implementation Status**: Not implemented — replaces the former "Manual Booking"

> **Renamed and narrowed 2026-08-07.** This use case previously described a free-amount booking of *any* sign, covering four different operations at once: fixing a terminal error, crediting a member, charging for drinks served away from the terminal, and adjusting a balance. Those have been separated ([#170](https://github.com/dgloeckner/ruderbar/issues/170)):
>
> | Was | Now |
> |---|---|
> | Correction: fix terminal error | [UC-A23: Storno](./UC-A23-storno.md) |
> | Credit: refund, compensation | payout, inside member offboarding |
> | **Manual charge: event without terminal** | **this use case** |
> | Balance adjustment: reconciliation | **removed** — see below |
>
> A standalone balance adjustment is no longer supported. Every real instance turned out to be a storno, a drink never booked, a manual purchase, or an offboarding payout. See [ADR-0004](../../adr/0004-immutable-transaction-storage.md) (amended) and [ADR-0028 §4](../../adr/0028-legal-constraints-on-money-handling.md).

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists

## Trigger
Drinks were served where the terminal could not reach — a club event, a party on the terrace — and someone kept a paper tally.

## Main Flow
1. Admin opens the member
2. Admin clicks "Manual Purchase"
3. Admin enters amount and reason
4. Admin submits
5. System creates a `purchase` transaction with `product_id = NULL`
6. System writes an audit entry
7. Member's Deckel increases

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Amount | Yes | **Positive**, max 2 decimal places, non-zero |
| Reason | Yes | Non-empty, max 500 chars |

## Amount Convention

**Positive only.** A manual purchase is a charge — money the member owes. There is no negative form: a member is never credited by this route.

To undo a manual purchase, storno it like any other transaction ([UC-A23](./UC-A23-storno.md)).

## Notes

This is the **only remaining place** in the system where an admin types a money amount. Everything else derives its amount: a storno negates its original exactly, a settlement sums the transactions it covers. That makes this path worth guarding — the audit entry is not optional, and the confirmation should restate member, amount and reason before committing.

It is deliberately *not* a new transaction type. A drink served at a club party is a purchase; the only thing distinguishing it is that no product record and no terminal were involved. Calling it a correction — the word that now means a storno against a named transaction — was the original conflation this rewrite undoes.

## Postconditions
- Transaction created: `transaction_type = 'purchase'`, `product_id = NULL`, `created_by_admin_id` set
- Member's unsettled balance increased
- Audit log entry recording admin, member, amount and reason

## Error Cases

### E1: Zero or negative amount
- Display "Amount must be greater than zero"

### E2: Missing reason
- Display "Reason is required"

## Test Derivation
- Positive amount: balance increases by exactly that amount
- Zero amount: validation error
- Negative amount: validation error
- Missing reason: validation error
- Transaction appears in the member's history and in the Journal
- Audit log contains the booking with its reason
- The created transaction can subsequently be stornoed

## Related
- [UC-A23: Storno](./UC-A23-storno.md) — the only way to undo this
- [UC-A20: View Tab](./UC-A20-view-tab.md)
- [ADR-0004: Immutable Transaction Storage](../../adr/0004-immutable-transaction-storage.md)
