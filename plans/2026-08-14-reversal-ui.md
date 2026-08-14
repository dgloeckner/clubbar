# Reversal UI — record a bank return, undo a submitted collection

**Epic**: [#433](https://github.com/dgloeckner/clubbar/issues/433) · **ADR**: [0032](../adr/0032-settlement-lifecycle.md) · **Branch**: `claude/issue-433-9yv0re`

Status legend: `[ ]` not started · `[~]` in progress · `[x]` passed (test verified) · `[!]` failed (reason documented)

## Why

Once a settlement is submitted, reversal is the **only** remedy — cancellation is closed off by design (ruling #142, ADR-0032). The backend for it shipped with [#196](https://github.com/dgloeckner/clubbar/issues/196): endpoint, gate, per-member granularity, collection holds, audit trail. None of it was reachable from the admin panel.

So a treasurer whose bank returned a direct debit had nowhere to record it, and one who submitted a wrong file had no way to undo it. [#81](https://github.com/dgloeckner/clubbar/issues/81)'s complaint was that "reverse it instead" must never point at a door that refuses to open; it pointed at no door at all.

Prerequisite [#377](https://github.com/dgloeckner/clubbar/issues/377) Phase 1 shipped in [#440](https://github.com/dgloeckner/clubbar/pull/440) — the row now carries `status`, `is_reversible` and `reversal_blocked_reason` from the server, so this epic never re-derives a gate.

### The one deviation from the epic's plan

The epic states "**No backend work is required by this epic**". That holds for the reversal *mechanism* — nothing about `POST /settlements/{id}/reverse` changed. It does not hold for §3's lookup: substring-matching a reference against `settlement_items.end_to_end_id` and `mandates.reference` is a query no endpoint exposed, and the epic's own API test list ("a reference resolves to exactly one member's collection in one run") presumes one. `GET /admin/settlements/reversal-candidates` is that endpoint and the only backend addition here.

## Milestones

### P0 — the reference lookup endpoint

- [x] `SettlementsRepository::findCollectionsByReference()` — one row per member per settlement, LIKE metacharacters escaped, `mandates` matched through `EXISTS` so ended mandates cannot multiply rows
- [x] `SettlementReversalService::findCandidates()` — normalises the typed reference (trim, case, `E2E-`/`EREF+`/`MREF+` label), refuses under 3 characters with a 422, and builds each candidate's status and gate answer from `SettlementDto::fromRow` rather than re-deriving either
- [x] `ReversalCandidateDto`, `GET /admin/settlements/reversal-candidates`, `api/admin.yaml` (`ReversalCandidate`), orval regenerated
- [x] `Settlement` schema corrected while regenerating: it declared a `members[]` the API has never returned and omitted `items[]`, `reversals[]`, `status`, `is_reversible`, `reversed_member_count` — P3 reads all of those
- Verify: **passed** — `settlement-reversal.spec.ts` L1–L7 (exact resolution, mandate reference across runs, an unexported run resolving to nothing, a member name resolving nothing, imperfect references, an already-reversed candidate still returned, the short-reference 422)

### P1 — reversal becomes reachable (§2, §5, §6)

- [x] `settlementRowUndoAction()` — the row's single slot, reading the backend's two flags. Cancel *xor* reverse for every live settlement; `none` only for a cancelled one
- [x] `ReverseSettlementDialog` — states that a reversal cannot be taken back (every time), names the collection hold for a bank return only, no step-up (reversal decrypts nothing, and friction on the remedy is how people avoid recording what happened)
- [x] Member picker as a disclosure defaulted to the whole run: the default call omits `member_ids` entirely, and the list is fetched only when the disclosure opens. Already-reversed members are shown disabled, not hidden
- [x] Post-commit behaviour: the row updates in place, no navigation, a banner naming the outcome — and for a bank return the hold, with a link to it
- Verify: **passed** — `settlement-reversal-ui.spec.ts` (whole-run undo, single-member undo, the cancel/reverse slot across both gate states, the irreversibility statement) plus `settlementReversal.test.ts`, the one-slot invariant over the seven row shapes `ReversalGateTest` pins

### P2 — recording a bank return (§3, §4)

- [x] `RecordBankReturnDialog` — a page-level action beside "Neue Abrechnung", always visible: a row action would presume the one fact the treasurer is missing, and a missing button explains nothing to someone holding a real statement
- [x] One free-text field, debounced, matching references only. A member name resolves nothing — extending the search to names needs an ADR-0032 §8 amendment
- [x] Candidates carry member, amount, execution date and status; a single match still confirms rather than acting; unactionable candidates are shown disabled with the backend's own reason; zero matches names the two likely causes (a run predating persisted identifiers, or a pain.002 reject that never books to a statement)
- [x] `bank_reference` prefilled from the resolved identifier, editable, never required
- Verify: **passed** — `settlement-reversal-ui.spec.ts` (record a return end to end through to the exclusion page and back, imperfect references, mandate reference, an already-recorded return, a run that never moved money, an unrecognised reference, a member name, confirm-before-acting, the always-present button)

### P3 — reversal history (§7)

- [x] Expandable settlement row fetching `getSettlement` lazily; the detail route stays deleted — what failed to justify a page can still justify a disclosure
- [x] Full per-member breakdown (`settlementMemberLines`), reversed members marked with amount, reason, bank reference, author and date. Not the reversals alone: without the other members the denominator is gone
- [x] `reversed_member_count` on the collapsed row as "1 von 40 zurückgebucht"
- Verify: **passed** — `settlement-reversal-ui.spec.ts` (expanding a partly reversed run, a run with nothing reversed, the collapsed count appearing only once something came back)

### i18n

- [x] New `settlements.*` keys in `de.json` and `en.json`; `locales.test.ts` pins that both files declare the same keys and that every key used in the source resolves

## Known gaps, recorded rather than left implicit

| Gap | Why it is not here |
|---|---|
| Partial-amount reversal | Not expressible in the API, by design (`SettlementReversalService.php:80`) — a member comes back whole or not at all |
| Correcting a reversal | Needs a schema change and an ADR-0032 amendment. Stated in the dialog instead, where the treasurer acts |
| Member-name search in the lookup | Would need an ADR-0032 §8 amendment |
| Amount + due-date filtering | Sanctioned by §8 but only earns its place if the statement carries no usable reference — [#183](https://github.com/dgloeckner/clubbar/issues/183) |
| `club_error` leaves no trace on the member | The honest fix is a member-level event history, a larger surface than this epic; worth its own issue |

**Open dependency**: [#183](https://github.com/dgloeckner/clubbar/issues/183) — the lookup assumes the bank books returns individually (German GVC 109). If a day's returns are booked collectively, per-member detail exists only in camt files and manual entry stops working.
