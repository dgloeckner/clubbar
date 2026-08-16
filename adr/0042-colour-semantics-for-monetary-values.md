# ADR-0042: Colour Semantics for Monetary Values

**Status**: Accepted

**Date**: 2026-08-16

**Deciders**: Architecture Team

---

## Context

Both frontends colour money. Neither had a written rule, and the two apps ended up contradicting each other on the same number.

The admin's dashboard card "Letzte Buchungen" rendered an ordinary €2.00 beer in `theme.colors.semantic.danger` — the token the design system declares as *"Red - danger, errors"*. Nothing about that booking is an error: it is the single most normal event the system records. Red on it costs twice. It tells the reader something is wrong when nothing is, and it spends the one colour the page needs for the things that genuinely are wrong — a SEPA-incomplete member, an offline till, a member past their credit limit — all of which sit on the same screen.

The terminal had already worked through this in [#28](https://github.com/dgloeckner/clubbar/issues/28) and landed on the opposite convention, documented as a comment on `AppMoney` in `terminal-frontend/lib/utils/design_tokens.dart` and pinned by `test/utils/money_semantics_test.dart`: green is reserved for actual credit, and a charge is neutral because it is not an error.

The admin never picked that rule up. It drifted independently three times:

| | Issue | What was wrong | How it was resolved |
|---|---|---|---|
| 1 | [#28](https://github.com/dgloeckner/clubbar/issues/28) | Terminal showed debt green and a €0.00 balance in warning-red | The rule now in `design_tokens.dart` — the correct one, terminal only |
| 2 | [#93](https://github.com/dgloeckner/clubbar/issues/93) | Admin's `getAmountColor` returned Tailwind class names into an inline `style` — colouring was silently dead | Returned CSS colours instead |
| 3 | [#376](https://github.com/dgloeckner/clubbar/issues/376) | Four admin pages coloured amounts in four contradictory ways | Unified onto one helper — but onto red-for-charges, without noticing #28 |

The third round is the instructive one. It correctly identified that the admin needed *one* rule and consolidated four into one, which is why the fix looked complete. It just chose the rule that contradicts the terminal, because the terminal's rule lived in a Dart doc comment that a person working in the React app has no reason to read. A convention that exists only inside one implementation will be re-decided by the next person to touch the other.

## Decision

**One colour rule for monetary values across both frontends, recorded here rather than in either implementation. Sign-based colour never uses the danger colour: a charge is neutral, green means money in the member's favour, and amber marks only a substantial open tab.**

### Sign convention

Unchanged, and inherited from [ADR-0004](./0004-immutable-transaction-storage.md) and [ADR-0001](./0001-monetary-values-as-integer-cents.md):

| Sign of `amount_cents` / `balance_cents` | Meaning |
|---|---|
| positive | the member owes money (open tab / Deckel) |
| zero | settled |
| negative | credit in the member's favour |

The sign itself is rendered by `Intl.NumberFormat` / the Dart formatter and is never prepended by hand. **The sign carries the meaning; colour only reinforces it.** That ordering matters for accessibility: red/green is not a distinction every reader can make, so no state may be conveyed by colour alone.

### Colour rule

Two cases, because a running balance and a single booking say different things.

**A balance** (the Deckel — members list, member bar, cart, confirmation):

| Value | Colour | Rationale |
|---|---|---|
| negative | success green | actual credit — the one genuinely good state |
| zero, or positive up to the warn threshold | primary text | settled or a normal open tab: the everyday case |
| above the warn threshold | warning amber | a reading cue that the tab has grown substantial |

**A single transaction amount** (dashboard recent bookings, journal, terminal booking history):

| Value | Colour | Rationale |
|---|---|---|
| negative | success green | a storno or refund — money back |
| zero or positive | primary text | a charge is not an error, so it is neither red nor amber |

The warn threshold is a *reading cue*, deliberately not the credit limit. The credit limit governs what a member may still buy and is surfaced separately — by the terminal's credit-limit banner and checkout button, and by the admin dashboard's near-limit panel. Both apps set the threshold to €20.00.

### Scope

The rule governs colour applied **because of an amount's sign**. It is encoded once per app and never re-derived at a call site:

| App | Encoded in |
|---|---|
| Admin (React) | `admin-frontend/src/utils/transactions.ts` — `getBalanceColor`, `getTransactionAmountColor`, `MONEY_WARN_ABOVE_CENTS` |
| Terminal (Flutter) | `terminal-frontend/lib/utils/design_tokens.dart` — `balanceColor`, `transactionAmountColor`, `AppMoney.warnAboveCents` |

Out of scope, and deliberately still colourful: amounts coloured by a **state** rather than by a sign. A balance past the credit limit, the categories on the "excluded from collection" page (credit / held / no mandate), report summary cards, and transaction-type badges all colour a classification that the number alone does not express. Those keep their colours — including red where a state genuinely is a problem — because there the colour is the information, not decoration on a number that already states its own sign.

A page may render an amount *more* muted than the rule requires when a list needs settled rows to recede — the members table greys a zero balance for exactly that reason. That is a local refinement of "neutral", applied at the call site, not a competing rule.

## Consequences

**Positive**

- The danger colour regains its meaning in the admin. On the dashboard, alerts and the near-limit panel are now the only red things on the page, so red once more means "look here".
- The same `amount_cents` looks the same in both apps. A treasurer cross-checking the admin against a till no longer sees one screen call a booking a problem and the other call it routine.
- Colour is no longer load-bearing. Every distinction the colour makes is also made by the sign and, on the terminal, by the wording ("Offener Betrag" / "Guthaben").
- One documented rule and two mirrored test suites replace an oral tradition, which is what let this drift three times.

**Negative**

- The admin's members list applies the €20 amber threshold, and it shows a whole column at once where the terminal shows one member. In a club where large tabs are common, much of that column turns amber and the cue dulls — the same fatigue this ADR removes elsewhere. The threshold is a single constant per app; if the column reads as noisy in practice, raising it or dropping the threshold for that list alone is the intended remedy.
- Losing red removes a fast visual scan for "who owes money" in the admin. That scan was misleading — it flagged every member with any open tab, which in a club bar is most of them — but somebody used to it will miss it.
- Two apps still hold two copies of one rule, in two languages. The mirrored unit tests make a divergence fail loudly rather than pass quietly, but they cannot make it impossible.

## References

- [ADR-0001](./0001-monetary-values-as-integer-cents.md) — monetary values as integer cents
- [ADR-0004](./0004-immutable-transaction-storage.md) — the sign convention for `amount_cents`
- [#28](https://github.com/dgloeckner/clubbar/issues/28), [#93](https://github.com/dgloeckner/clubbar/issues/93), [#376](https://github.com/dgloeckner/clubbar/issues/376) — the three rounds of drift this ADR closes
- [#478](https://github.com/dgloeckner/clubbar/issues/478) — the report that prompted this ADR
- `admin-frontend/patterns/components.md` — "Colouring a monetary value", the call-site guidance
