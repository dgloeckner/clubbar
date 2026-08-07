# UC-A23: Storno

**Implementation Status**: Not implemented

Reversing one specific transaction, in full. This is the **only** way to correct a booking — see [#158](https://github.com/dgloeckner/ruderbar/issues/158) and the `Storno` entry in [CONTEXT.md](../../CONTEXT.md).

## Actor
Admin

## Preconditions
- Admin is logged in
- The transaction exists and has not already been stornoed
- The transaction is not itself a storno

## Trigger

A booking is wrong. The characteristic case in an unstaffed self-service bar is mis-attribution:

> Anna leaves her card on the bar during the regatta. Bernd picks it up by mistake and scans four beers. €16.80 lands on Anna's Deckel.

## Main Flow
1. Admin finds the transaction in the Journal (or in the member's history)
2. Admin clicks **Storno** on that row
3. System shows a confirmation stating **what is being reversed** — member, product, amount, date
4. Admin enters a reason and confirms
5. System creates a storno transaction: `transaction_type = 'storno'`, `related_transaction_id` = the original, amount = **exact negation of the original**
6. System writes an audit entry
7. Journal shows the original and its storno, visibly linked

If a replacement booking is needed — the member was charged €5.00 but owed €3.50 — storno the original and then book the correct amount as a new purchase. **There is no partial storno.**

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Reason | Yes | Non-empty, max 500 chars |

**There is no amount field.** The amount is derived as the exact negation of the referenced transaction and is never supplied by the caller. This is what removes the sign-convention and zero-amount error classes entirely.

## Rules

| Rule | Behaviour |
|---|---|
| A transaction may be stornoed **at most once** | Second attempt → 409. Enforced by a unique index, not only in the service |
| A storno may **not** itself be stornoed | → 422. Book a new purchase instead |
| A **settled** transaction may be stornoed | **Allowed and expected.** It puts the member in credit, which is legal ([ADR-0028 §1](../../adr/0028-legal-constraints-on-money-handling.md)); carry-forward or offboarding payout resolves it |
| A member with no valid mandate may be stornoed | **Allowed.** A storno *reduces* what the member owes — gating a debt reduction on the ability to collect would be inverted |
| `related_transaction_id` | **Never null.** Traceability to the corrected booking is a legal requirement, not a convention ([ADR-0028 §4](../../adr/0028-legal-constraints-on-money-handling.md), GoBD Rz. 64) |

## Not a settlement reversal

Stornoing a transaction (*we booked the wrong thing*) is distinct from reversing a settlement (*the bank returned the collection*). Different events, different evidence, different mechanisms. See [UC-A35](./UC-A35-manual-settlement.md) and the settlement reversal design.

## Postconditions
- Storno transaction created, linked to the original
- Original transaction **unchanged** — append-only ([ADR-0004](../../adr/0004-immutable-transaction-storage.md))
- Member balance reduced by exactly the original amount
- Audit log entry recording admin, target transaction and reason

## Error Cases

### E1: Already stornoed
- Display "This transaction has already been stornoed", linking to the existing storno

### E2: Target is a storno
- Display "A storno cannot itself be stornoed. Book a new purchase instead."

### E3: Missing reason
- Display "Reason is required"

## Test Derivation
- Storno negates exactly the original amount, with no amount supplied by the caller
- Second storno of the same transaction is refused **by the database**, not merely by the service
- Storno of a storno is refused
- A member with no active mandate can still be stornoed
- Stornoing a settled transaction succeeds and leaves the member in credit
- The original row is byte-identical afterwards
- Audit log names admin, target transaction and reason
- Journal displays original and storno as linked

## Related
- [UC-A20: View Tab](./UC-A20-view-tab.md)
- [UC-A21: Manual Purchase](./UC-A21-manual-purchase.md)
- [ADR-0004: Immutable Transaction Storage](../../adr/0004-immutable-transaction-storage.md) — amended for storno-only
- [ADR-0028: Legal Constraints on Money Handling](../../adr/0028-legal-constraints-on-money-handling.md) §4
