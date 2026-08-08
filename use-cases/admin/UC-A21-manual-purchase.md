# UC-A21: Manual Purchase — ~~Not built~~

**Implementation Status**: **Rejected 2026-08-08** — will not be built. This use case is kept as a tombstone so the reasoning survives and the idea is not re-proposed.

> **Rejected 2026-08-08.** Manual purchase was the last surviving shape of the free-amount booking. [#170](https://github.com/dgloeckner/ruderbar/issues/170) kept it on the strength of a single scenario — *drinks served where the terminal could not reach* — and [#169](https://github.com/dgloeckner/ruderbar/issues/169) was to build it. On review that scenario does not survive contact with what the terminal actually is. See **Why this was rejected** below.
>
> This reverses the "✅ Manual purchase" row of #170's resolution table. It does not reopen anything else that ruling decided: storno is built ([UC-A23](./UC-A23-storno.md)), goodwill credit stays rejected, cash stays forbidden, payout stays absorbed into offboarding.

## What it would have been

An admin books a charge against a member with no terminal involved: a `purchase` with `product_id = NULL`, a typed positive amount and a reason. The trigger was a club event or a party on the terrace where someone kept a paper tally, transcribed days later.

## Why this was rejected

**1. "The terminal could not reach" is not a connectivity problem — it is already solved.**

The terminal is offline-first by construction ([ADR-0012](../../adr/0012-eventual-consistency-frontend-caching.md)). Card scanning, member lookup, product selection and transaction recording are all listed as *fully functional offline*; transactions queue locally and sync when connectivity returns. The system warns after 1 hour offline and prominently after 24, and monitoring merely *alerts* on terminals offline longer than that — continued operation is expected, not exceptional. A terminal at a party with no network still books drinks correctly.

**2. Bar service away from the fridge is a deployment question, and the deployment already exists.**

Multi-terminal is a first-class concept, not a future one: ADR-0012 ("Multiple terminals may operate simultaneously"), [ADR-0015](../../adr/0015-authentication-and-authorization-strategy.md), and [UC-A51](./UC-A51-create-terminal.md), which registers each terminal with its own name, device ID and token. Terminals are architecturally identical — there is no primary/secondary distinction to prevent a second one. The terminal is a Raspberry Pi with a touchscreen and a USB RFID reader; it needs power and a surface, not a network. For a club event, the answer is a terminal there — using the evidence-grade path — rather than a typed amount entered later.

**3. It would have been the only place in the system where a human types a money amount.**

This use case said so itself, and treated it as a reason for guardrails. It is better read as a reason not to have the path: everything else in the system *derives* its amount. A storno negates its original exactly. A settlement sums the transactions it covers. Manual settlement covers exactly one member's whole position ([#163](https://github.com/dgloeckner/ruderbar/issues/163) — "no picker, no typed amount"). Removing the last typed amount is what makes that a property of the system rather than a coincidence.

**4. It is the unlinked booking, in the form that disqualified goodwill credit.**

#170 rejected goodwill because it is "the unlinked adjustment in its purest form — nothing to point at". A manual purchase is equally unlinked: no product, no terminal, no prior booking, nothing for an auditor to trace it to. GoBD Rz. 64 does not reach either of them — both are new Geschäftsvorfälle rather than Stornobuchungen — so unlinkedness was the whole objection to goodwill, and it applies here identically. What separated them was direction (a charge, not a credit) and § 55 AO. That is a real distinction for *tax* purposes, but not one about evidence.

**5. It digitises the Strichliste, which raises the compliance burden rather than lowering it.**

[ADR-0028](../../adr/0028-legal-constraints-on-money-handling.md) and [the legal requirements map](../../docs/legal-requirements-and-how-we-meet-them.md) both flag this explicitly: born-digital records must stay electronic and machine-evaluable and cannot be printed and purged (GoBD Rz. 119, 129, 157) — *"the paper tally this system replaces carried none of those duties."* Transcribing a tally into the journal days later creates the weakest-evidence rows in the system, resting on somebody's handwriting, and then binds them to duties the paper never had.

## What to do instead

| Situation | Answer |
|---|---|
| Club event, party on the terrace | Put a terminal there. It works offline; a second one is a registered terminal ([UC-A51](./UC-A51-create-terminal.md)) |
| The drink sold there is not in the catalogue | Add the product ([UC-A12](./UC-A12-create-product.md)); the terminal then books it like any other |
| A booking is wrong | [UC-A23: Storno](./UC-A23-storno.md) — the only way to correct a booking |
| A departing member owes or is owed money | Offboarding: write-off or payout ([#173](https://github.com/dgloeckner/ruderbar/issues/173)) |

## If this is ever revisited

The bar to clear is not "is the terrace case real" — it is **why a terminal cannot be there**. A recurring event where a terminal is genuinely impossible, and where the drinks sold are not in the catalogue, would be a new argument. Volume alone would not be: the more often it happens, the stronger the case for a terminal rather than for typing amounts.

It must not return under the name *correction*. That word now means a storno against a named transaction, and the conflation of the two is what [#158](https://github.com/dgloeckner/ruderbar/issues/158) and #169 exist to undo.

## Related
- [UC-A23: Storno](./UC-A23-storno.md) — the only supported way to correct a booking
- [UC-A20: View Tab](./UC-A20-view-tab.md)
- [ADR-0004: Immutable Transaction Storage](../../adr/0004-immutable-transaction-storage.md) — amended for storno-only
- [ADR-0012: Eventual Consistency and Frontend Caching](../../adr/0012-eventual-consistency-frontend-caching.md) — the offline guarantees this rejection rests on
- [ADR-0028: Legal Constraints on Money Handling](../../adr/0028-legal-constraints-on-money-handling.md) §4
