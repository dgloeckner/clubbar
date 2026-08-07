# Critical Remediation: money semantics, sync contract, auth

**Source:** [Map: lock money semantics before fixing the 11 critical issues](https://github.com/dgloeckner/clubbar/issues/139)
**Status:** Not started. ⚠️ **Decisions are no longer all locked** — see "Reopened" below.
**Created:** 2026-08-06 · **Last reconciled:** 2026-08-07

> **This file is an index, not a specification.** Every actionable detail lives on the GitHub issues, where an implementing agent will read it: each carries its governing ruling and its acceptance criteria. Sequencing is enforced by GitHub issue dependencies, which `scripts/work-on-issue.sh` already respects — a blocked issue never reaches the frontier. Do not restate issue content here; it will drift.

## The rulings

Eight decisions, each with full reasoning on its ticket:

| Ruling | Governs |
|---|---|
| [Refund obligation research](https://github.com/dgloeckner/clubbar/issues/140) | § 812 BGB makes over-collection a debt; credit must be representable |
| [Exclude-and-flag](https://github.com/dgloeckner/clubbar/issues/141) | settle on total unsettled position; `>0` collect, `=0` close out, `<0` exclude |
| [Settlement cancellation](https://github.com/dgloeckner/clubbar/issues/142) | cancellable only while not submitted and execution date not passed |
| [Sync accept/reject contract](https://github.com/dgloeckner/clubbar/issues/143) | reject only the unstorable; business failures are flags |
| [Timestamp authority](https://github.com/dgloeckner/clubbar/issues/144) | `occurred_at` (terminal) + `received_at` (server); flag, never rewrite |
| [Auth lockout policy](https://github.com/dgloeckner/clubbar/issues/145) | per-IP **and** per-account; MFA failures persist |
| [Test fixture strategy](https://github.com/dgloeckner/clubbar/issues/146) | per-test factory; lint-enforced skips; patch-coverage gate |
| [Settlement reversal](https://github.com/dgloeckner/clubbar/issues/148) | append-only reversal events; collection hold on bank return |
| [Bank return reporting research](https://github.com/dgloeckner/clubbar/issues/149) | manual entry holds; needs persisted EndToEndId + lookup UI |

**Standing constraints:** pre-launch (breaking migrations free, nothing to reconcile); the terminal moves in lockstep with the backend.

## Reopened (2026-08-07)

This plan was written when the map recorded its destination as reached. A [money-path audit](https://github.com/dgloeckner/ruderbar/issues/160) and a [bookkeeping-law research ticket](https://github.com/dgloeckner/ruderbar/issues/159) have since reopened it. **Do not treat the phase table below as complete until the open tickets close.**

**Two rulings had no issue carrying them into the sequence**, so this plan reported them as handled when they were not:

| Ruling | Was mapped to | Actually needed |
|---|---|---|
| [Exclude-and-flag](https://github.com/dgloeckner/ruderbar/issues/141), settlement layer | [#80](https://github.com/dgloeckner/ruderbar/issues/80) — scoped to one `abs()` line | [#161](https://github.com/dgloeckner/ruderbar/issues/161) (Phase 2) |
| [Sync accept/reject](https://github.com/dgloeckner/ruderbar/issues/143), `sepa_invalid` removal | nothing | [#162](https://github.com/dgloeckner/ruderbar/issues/162) (Phase 4) |

**Open decisions**, all on the [map](https://github.com/dgloeckner/ruderbar/issues/139): [#158](https://github.com/dgloeckner/ruderbar/issues/158) correction/reversal/payout entry contract · [#163](https://github.com/dgloeckner/ruderbar/issues/163) manual settlements · [#164](https://github.com/dgloeckner/ruderbar/issues/164) mandate validity · [#165](https://github.com/dgloeckner/ruderbar/issues/165) banking-data immutability and the erasure window · [#166](https://github.com/dgloeckner/ruderbar/issues/166) coverage/ruling collision.

⚠️ **[#166](https://github.com/dgloeckner/ruderbar/issues/166) is time-sensitive.** [`2026-08-07-backend-test-coverage.md`](./2026-08-07-backend-test-coverage.md) pins *current* behaviour by design, and its M2–M6 run straight through code these rulings govern — M2.4 is written to test the very limiter [#145](https://github.com/dgloeckner/ruderbar/issues/145) replaced. Every milestone that lands before the fixes writes tests that must later be deleted. Decide #166 before M2 starts.

**Newly established as binding** (from [#159](https://github.com/dgloeckner/ruderbar/issues/159)): GoBD Rz. 64 makes reversal→original linkage a legal requirement, so `related_transaction_id` cannot stay optional on true reversals. And the system's exit from the KassenSichV/TSE regime depends on the member tab being **post-paid** — a prepaid top-up balance would plausibly pull it in. Terminal-side top-up must be ruled out by design.

## Sequence

Two things gate everything, for reasons the dependency graph alone doesn't explain:

**[Phase 0: consolidated schema migration](https://github.com/dgloeckner/clubbar/issues/151)** ships as one migration rather than each fix inventing its own — otherwise parallel agents produce conflicting migrations.

**[Phase 1: test infrastructure](https://github.com/dgloeckner/clubbar/issues/98)** comes before the fixes because CLAUDE.md mandates TDD, and the money fixes cannot be tested at all without the settlement factory.

| Phase | Work | Gated by |
|---|---|---|
| 0 | [Schema migration](https://github.com/dgloeckner/clubbar/issues/151) | — |
| 1 | [Test fixtures](https://github.com/dgloeckner/ruderbar/issues/98) · ~~[coverage gate](https://github.com/dgloeckner/ruderbar/issues/103)~~ **done, merged in [#154](https://github.com/dgloeckner/ruderbar/pull/154)** | — |
| 2 | [Balance definition](https://github.com/dgloeckner/clubbar/issues/83) · [credit exclusion](https://github.com/dgloeckner/clubbar/issues/80) · **[settlement-layer exclusion](https://github.com/dgloeckner/ruderbar/issues/161)** · [export reporting](https://github.com/dgloeckner/clubbar/issues/114) | Phase 0 |
| 3 | [Cancellation](https://github.com/dgloeckner/clubbar/issues/81) · [atomicity](https://github.com/dgloeckner/clubbar/issues/86) · [EndToEndId](https://github.com/dgloeckner/clubbar/issues/150) · [lead time](https://github.com/dgloeckner/clubbar/issues/113) · [undo UX](https://github.com/dgloeckner/clubbar/issues/127) · [tests](https://github.com/dgloeckner/clubbar/issues/100) | Phases 0–2 |
| 4 | [Sync contract](https://github.com/dgloeckner/clubbar/issues/82) · [field authority](https://github.com/dgloeckner/clubbar/issues/79) · **[`sepa_invalid` removal](https://github.com/dgloeckner/ruderbar/issues/162)** · [idempotency tests](https://github.com/dgloeckner/clubbar/issues/99) | Phase 0 |
| 5 | [Auth lockout](https://github.com/dgloeckner/clubbar/issues/78) · [auth tests](https://github.com/dgloeckner/clubbar/issues/101) | — |
| 6 | [Categories modal](https://github.com/dgloeckner/clubbar/issues/88) · [PeriodPicker](https://github.com/dgloeckner/clubbar/issues/89) | — |
| 7 | [Terminal client](https://github.com/dgloeckner/clubbar/issues/152) | Phase 0, ships **with** Phase 4 |
| — | [ADRs and pattern corrections](https://github.com/dgloeckner/clubbar/issues/153) | — |

Phases 4, 5, 6 and the ADRs are independent and can run in parallel with the settlement track.

## Issues that collapse

Recorded here because a closed or absorbed issue leaves no trace otherwise:

| Issue | Disposition |
|---|---|
| [#87 Net-negative settlement total](https://github.com/dgloeckner/clubbar/issues/87) | **Closed, superseded.** The exclusion rule makes the total provably non-negative; `UNSIGNED` stays as a fail-loud assertion |
| [#86 cancelSettlement non-atomic](https://github.com/dgloeckner/clubbar/issues/86) | Same code path as [#81](https://github.com/dgloeckner/clubbar/issues/81); fix together |
| [#114 export silently omits](https://github.com/dgloeckner/clubbar/issues/114) | Splits across Phase 2 (reporting) and Phase 3 (cancelled-settlement guard) |
| [#83 balance definition](https://github.com/dgloeckner/clubbar/issues/83) | Splits: backend in Phase 2, terminal display in [#152](https://github.com/dgloeckner/clubbar/issues/152) |
| [#118 repository bypasses](https://github.com/dgloeckner/clubbar/issues/118) | `LoginAttemptsRepository` half folds into [#78](https://github.com/dgloeckner/clubbar/issues/78); Dashboard/Reports half stays separate |
| [#128 Journal selection](https://github.com/dgloeckner/clubbar/issues/128) | **Reshaped** — selection now picks members. Fix after Phase 2, not before |

## Out of scope

- **Automated bank return-file ingestion** (camt.053 / pain.002) — a new capability, not a remediation. Returns are recorded manually; `bank_reference` leaves the door open. If a bank books returns collectively, the fallback is a **manual camt.053 upload**, not EBICS.
- The ~50 non-critical issues beyond those folded in above.

## Still in fog

Three design questions the map deliberately left unspecified, none blocking:

- The Journal selection screen, once selecting transactions means selecting members.
- Settlement list and detail displays across draft / exported / submitted / cancelled / partly-reversed.
- The treasurer's "needs attention" queue — now fed by credit balances, missing mandates **and** collection holds. Probably one screen, not three.
