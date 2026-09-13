# ADR-0042: Colour Semantics for Monetary Values

**Status**: Accepted

**Date**: 2026-08-16

**Amended**: 2026-09-13 — the warn threshold is now the member's own credit-limit warning band, not a per-app €20.00 constant (see *Amendment* below and [#926](https://github.com/dgloeckner/clubbar/issues/926))

**Deciders**: Architecture Team

---

## Context

Both frontends colour money. Neither had a written rule, and the two apps ended up contradicting each other on the same number.

The admin's dashboard card "Letzte Buchungen" rendered an ordinary €2.00 beer in `theme.colors.semantic.danger` — the token the design system declares as *"Red - danger, errors"*. Nothing about that booking is an error: it is the single most normal event the system records. Red on it costs twice. It tells the reader something is wrong when nothing is, and it spends the one colour the page needs for the things that genuinely are wrong — a SEPA-incomplete member, an offline till, a member past their credit limit — all of which sit on the same screen.

The terminal had already worked through this in [#28](https://github.com/dgloeckner/clubbar/issues/28) and landed on the opposite convention, documented as a comment on `AppMoney` (since removed — see the amendment below) in `terminal-frontend/lib/utils/design_tokens.dart` and pinned by `test/utils/money_semantics_test.dart`: green is reserved for actual credit, and a charge is neutral because it is not an error.

The admin never picked that rule up. It drifted independently three times:

| | Issue | What was wrong | How it was resolved |
|---|---|---|---|
| 1 | [#28](https://github.com/dgloeckner/clubbar/issues/28) | Terminal showed debt green and a €0.00 balance in warning-red | The rule now in `design_tokens.dart` — the correct one, terminal only |
| 2 | [#93](https://github.com/dgloeckner/clubbar/issues/93) | Admin's `getAmountColor` returned Tailwind class names into an inline `style` — colouring was silently dead | Returned CSS colours instead |
| 3 | [#376](https://github.com/dgloeckner/clubbar/issues/376) | Four admin pages coloured amounts in four contradictory ways | Unified onto one helper — but onto red-for-charges, without noticing #28 |

The third round is the instructive one. It correctly identified that the admin needed *one* rule and consolidated four into one, which is why the fix looked complete. It just chose the rule that contradicts the terminal, because the terminal's rule lived in a Dart doc comment that a person working in the React app has no reason to read. A convention that exists only inside one implementation will be re-decided by the next person to touch the other.

### Amendment (2026-09-13)

The original decision set the warn threshold to a flat €20.00 in both apps and
said so deliberately: a *reading cue*, not the credit limit. That was wrong in
practice, and this ADR predicted how — see the first bullet under Negative
below, which described a members column turning amber in bulk until the cue
dulled. The terminal hit the same thing one member at a time: a €23.00 tab was
shown in warning colour under a club that warns at €80.00, and a colour that
fires on an ordinary evening is one people learn to stop reading.

The threshold is now **the member's own credit-limit warning band**
([ADR-0047](./0047-configurable-credit-limits.md)) — the line the terminal's
`CreditLimitBanner` and the dashboard's near-limit panel already hold. One
threshold, one meaning. Everything else below is unchanged: the sign
convention, the ban on the danger colour, the rule for a single transaction
amount, and the principle that colour is never load-bearing.

## Decision

**One colour rule for monetary values across both frontends, recorded here rather than in either implementation. Sign-based colour never uses the danger colour: a charge is neutral, green means money in the member's favour, and amber marks only a tab inside the member's own credit-limit warning band.**

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
| zero, or positive below the member's warning band | primary text | settled or a normal open tab: the everyday case |
| at or above the member's warning band | warning amber | they are close to being refused at the bar |

**A single transaction amount** (dashboard recent bookings, journal, terminal booking history):

| Value | Colour | Rationale |
|---|---|---|
| negative | success green | a storno or refund — money back |
| zero or positive | primary text | a charge is not an error, so it is neither red nor amber |

**The warn threshold *is* the credit limit's warning band** — the member's own ceiling where they have one and the club's where they do not, times the club's `warn_threshold_percent` ([ADR-0047](./0047-configurable-credit-limits.md)). It is not a second number, and neither app may invent one.

Three consequences of that, which both implementations encode:

- **The boundary is `>=`**, the same one `CreditLimitCheck.status` and PHP's `CreditLimit::status()` use, so the amount and the credit-limit banner flip on the same cent. A member told "you are close" by a banner is never shown an ordinary-looking number beside it.
- **No enforced ceiling means never amber.** A member whose effective ceiling is `0` is unlimited (ADR-0047 rule 2: `NULL` inherits, `0` is deliberate), so there is no line for them to approach. That is carried as `null`, never as a band of `0` — which would put every tab at or above its band.
- **Debt is required, not merely a band.** `warn_threshold_percent` may be as low as 1, so a small ceiling rounds its band down to zero; a settled account rendered in warning colour is issue #28 returning by another route.

A tab *past* the ceiling stays amber rather than turning red. That is colour-by-state, and it belongs to the surfaces named under Scope — the terminal's banner and checkout button, the dashboard's near-limit panel — not to the amount, which only ever says "in the band".

**How each app learns the band.** The terminal resolves it itself, through `CreditLimitPolicy`: it decides at checkout with nothing reachable, which is the reason ADR-0047 tolerates a Dart copy of the resolution rule at all. The admin panel does **not** — it is online on every render, so the band arrives per row as `credit_limit_warn_at_cents`, derived by the backend. That is the reasoning `/sync/config` already applies to its own `warn_at_cents`: a boundary cent the two sides round differently is a member one of them warns and the other does not.

### Scope

The rule governs colour applied **because of an amount's sign**. It is encoded once per app and never re-derived at a call site:

| App | Encoded in |
|---|---|
| Admin (React) | `admin-frontend/src/utils/transactions.ts` — `getBalanceColor`, `getTransactionAmountColor`. The band is a parameter, received as `credit_limit_warn_at_cents` on the roster row |
| Terminal (Flutter) | `terminal-frontend/lib/utils/design_tokens.dart` — `balanceColor`, `transactionAmountColor`. The band is a parameter, resolved by `CreditLimitPolicy.warnAtCentsFor()` |
| Backend (PHP) | `App\Modules\CreditLimits\Domain\CreditLimit` — `warnAtCents()`, the one place the band is computed for everything that is not the offline terminal |

Out of scope, and deliberately still colourful: amounts coloured by a **state** rather than by a sign. A balance past the credit limit, the categories on the "excluded from collection" page (credit / held / no mandate), report summary cards, and transaction-type badges all colour a classification that the number alone does not express. Those keep their colours — including red where a state genuinely is a problem — because there the colour is the information, not decoration on a number that already states its own sign.

A page may render an amount *more* muted than the rule requires when a list needs settled rows to recede — the members table greys a zero balance for exactly that reason. That is a local refinement of "neutral", applied at the call site, not a competing rule.

## Consequences

**Positive**

- The danger colour regains its meaning in the admin. On the dashboard, alerts and the near-limit panel are now the only red things on the page, so red once more means "look here".
- The same `amount_cents` looks the same in both apps. A treasurer cross-checking the admin against a till no longer sees one screen call a booking a problem and the other call it routine.
- Colour is no longer load-bearing. Every distinction the colour makes is also made by the sign and, on the terminal, by the wording ("Offener Betrag" / "Guthaben").
- One documented rule and two mirrored test suites replace an oral tradition, which is what let this drift three times.

**Negative**

- ~~The admin's members list applies the €20 amber threshold, and it shows a whole column at once where the terminal shows one member. In a club where large tabs are common, much of that column turns amber and the cue dulls.~~ **Resolved by the 2026-09-13 amendment** — this is what happened, on both apps, and the threshold is now per member. The remedy guessed at here (raising the constant) would only have moved the problem; the fault was having a constant at all.
- **Upgrade day is visibly different.** Every terminal and panel moves from "amber above €20.01" to "amber from the member's band" at once — with the shipped 10 000 / 80 policy, from €80.00. Mid-size tabs that were amber for months stop being amber, which will read as a bug to anyone who has not been told. A club that genuinely wants a lower cue lowers its ceiling or its `warn_threshold_percent`, which changes what the terminal enforces too — deliberately, since that is what makes the colour mean something.
- **A club with no ceiling gets no cue at all.** Where `default_limit_cents` is `0` and nobody holds an override, no balance is ever amber. That is correct — there is no line to approach — but it does remove a signal such a club used to have. The remedy is to set a ceiling, which is the setting that was always meant to carry this.
- Losing red removes a fast visual scan for "who owes money" in the admin. That scan was misleading — it flagged every member with any open tab, which in a club bar is most of them — but somebody used to it will miss it.
- Two apps still hold two copies of the *colour* rule, in two languages. The mirrored unit tests make a divergence fail loudly rather than pass quietly, but they cannot make it impossible. The *band* is no longer duplicated that way: PHP computes it for the panel, Dart for the offline terminal, and ADR-0047 already accounts for exactly those two.

## References

- [ADR-0001](./0001-monetary-values-as-integer-cents.md) — monetary values as integer cents
- [ADR-0004](./0004-immutable-transaction-storage.md) — the sign convention for `amount_cents`
- [#28](https://github.com/dgloeckner/clubbar/issues/28), [#93](https://github.com/dgloeckner/clubbar/issues/93), [#376](https://github.com/dgloeckner/clubbar/issues/376) — the three rounds of drift this ADR closes
- [#478](https://github.com/dgloeckner/clubbar/issues/478) — the report that prompted this ADR
- [ADR-0047](./0047-configurable-credit-limits.md) — the club default, the per-member override, and the warning band this rule now uses
- [#926](https://github.com/dgloeckner/clubbar/issues/926) — a €23.00 tab shown in warning colour under an €80.00 band: the report behind the 2026-09-13 amendment
- `admin-frontend/patterns/components.md` — "Colouring a monetary value", the call-site guidance
