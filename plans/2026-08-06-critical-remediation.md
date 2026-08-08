# Critical Remediation: money semantics, sync contract, auth

**Source:** [Map: lock money semantics before fixing the 11 critical issues](https://github.com/dgloeckner/ruderbar/issues/139)
**Status:** **Decisions substantially complete — verification not started.** One decision open. No code written.
**Created:** 2026-08-06 · **Last reconciled:** 2026-08-07

> **This file is an index, not a specification.** Every actionable detail lives on the GitHub issues, where an implementing agent will read it: each carries its governing ruling and its acceptance criteria. Sequencing is enforced by GitHub issue dependencies, which `scripts/work-on-issue.sh` already respects. Do not restate issue content here; it will drift.

---

## Where this stands

All 11 original critical issues are resolved or carry a ruling — `priority: critical` returns **zero open**. Twenty-one decisions are closed. **But the map is not complete**, and it says so by its own test rather than by assertion.

### Completion test

Written into the map's Destination after "destination reached" was declared once already ([#147](https://github.com/dgloeckner/ruderbar/issues/147)) and proved wrong — [the money-path audit](https://github.com/dgloeckner/ruderbar/issues/160) found two rulings that no issue carried and five paths nobody had examined.

> **Every line of money code has been executed by a test, and each of those tests either asserts a ruling or is explicitly recorded as not needing one.**

| Condition | Status |
|---|---|
| Frontier empty | ❌ one decision open: [onboarding + Datenschutz](https://github.com/dgloeckner/ruderbar/issues/175). Two ruled items open but decided, so execution only: [#204](https://github.com/dgloeckner/ruderbar/issues/204), [#205](https://github.com/dgloeckner/ruderbar/issues/205) |
| Ruled-surfaces table has no `skip` rows | ❌ **the table does not exist** — [#168](https://github.com/dgloeckner/ruderbar/issues/168) unimplemented |
| Money modules fully covered | ❌ **26.64%**, M1 only |

Nothing between here and the destination requires a *decision*. It is all execution.

---

## The rulings

Twenty-one, each with full reasoning on its ticket. Grouped by what they govern.

**Money semantics**
| Ruling | Governs |
|---|---|
| [Refund obligation](https://github.com/dgloeckner/ruderbar/issues/140) *(research)* | § 812 BGB makes over-collection a debt; credit must be representable |
| [Exclude-and-flag](https://github.com/dgloeckner/ruderbar/issues/141) | settle on total unsettled position; `>0` collect, `=0` close out, `<0` exclude |
| [Which operations the club needs](https://github.com/dgloeckner/ruderbar/issues/170) | storno · write-off — **no** goodwill, **no** cash, **no manual purchase** (amended 2026-08-08, see #169), payout absorbed into offboarding |
| [What is a correction](https://github.com/dgloeckner/ruderbar/issues/158) | a correction **is** a storno; amount derived, link mandatory |
| [Manual settlements](https://github.com/dgloeckner/ruderbar/issues/163) | one method field: `direct_debit` / `bank_transfer` / `write_off` |
| [Settlement cancellation](https://github.com/dgloeckner/ruderbar/issues/142) | generalised: cancellable **while no money has moved** |
| [Settlement reversal](https://github.com/dgloeckner/ruderbar/issues/148) | append-only reversal events; collection hold on bank return |

**Members, mandates, privacy**
| Ruling | Governs |
|---|---|
| [Mandate validity](https://github.com/dgloeckner/ruderbar/issues/164) | a mandate is **one append-only record**; at most one active |
| [SEPA-only](https://github.com/dgloeckner/ruderbar/issues/171) | no valid mandate, no bar access — terminal blocks, server never rejects |
| [Erasure window](https://github.com/dgloeckner/ruderbar/issues/165) | erasure deletes the **person**, not the **record**; two tiers + retention expiry |
| [Offboarding](https://github.com/dgloeckner/ruderbar/issues/173) | one atomic action; forces balance resolution; `deleted_at` **is** "gone" |
| ⏳ [Onboarding + Datenschutz](https://github.com/dgloeckner/ruderbar/issues/175) | **OPEN** — how a mandate is obtained; Art. 13 notice; consent scoping |

**Sync and auth**
| Ruling | Governs |
|---|---|
| [Sync accept/reject](https://github.com/dgloeckner/ruderbar/issues/143) | reject only the unstorable; business failures are flags |
| [Timestamp authority](https://github.com/dgloeckner/ruderbar/issues/144) | `occurred_at` (terminal) + `received_at` (server); flag, never rewrite |
| [Auth lockout](https://github.com/dgloeckner/ruderbar/issues/145) | per-IP **and** per-account; MFA failures persist |

**Legal grounding** *(research — external constraints, recorded in [ADR-0028](../adr/0028-legal-constraints-on-money-handling.md))*
| Ruling | Governs |
|---|---|
| [Correction bookkeeping](https://github.com/dgloeckner/ruderbar/issues/159) | GoBD Rz. 64 — reversal→original linkage is a legal requirement |
| [Bank return reporting](https://github.com/dgloeckner/ruderbar/issues/149) | manual entry holds; needs persisted EndToEndId + lookup UI |
| [Bookkeeping obligations](https://github.com/dgloeckner/ruderbar/issues/174) | the bar **is** a taxable wGB; § 63 Abs. 3 AO binds; per-drink records retained **10 years** |

**Process**
| Ruling | Governs |
|---|---|
| [Test fixture strategy](https://github.com/dgloeckner/ruderbar/issues/146) | per-test factory; lint-enforced skips; patch-coverage gate |
| [Coverage/ruling protocol](https://github.com/dgloeckner/ruderbar/issues/166) | coverage yields to rulings; never-decrease floor |
| [Money-path audit](https://github.com/dgloeckner/ruderbar/issues/160) *(task)* | found the two uncarried rulings |

**Standing constraints:** pre-launch (breaking migrations free, nothing to reconcile) · the terminal moves in lockstep with the backend · **no cash, as policy** · SEPA is the only collection rail.

---

## Sequence

Two things gate everything:

**[Phase 0: consolidated schema migration](https://github.com/dgloeckner/ruderbar/issues/151)** ships as one migration rather than each fix inventing its own. It has grown considerably: `mandates` table · `transaction_type` → `purchase`/`storno`/`payout` · `related_transaction_id` NOT NULL + UNIQUE · settlement method field · retention-expiry column.

**[Phase 1: test infrastructure](https://github.com/dgloeckner/ruderbar/issues/98)** comes before the fixes because CLAUDE.md mandates TDD and the money fixes cannot be tested without the settlement factory.

| Phase | Work | Gated by |
|---|---|---|
| 0 | ~~[Schema migration](https://github.com/dgloeckner/ruderbar/issues/151)~~ **shipped** — `007_critical_remediation.sql` | — |
| 1 | ~~[Test fixtures](https://github.com/dgloeckner/ruderbar/issues/98)~~ **shipped** — per-test `settlementFactory`, no data-dependent skips left in `settlements.spec.ts` · ~~coverage gate (#103)~~ **merged in [#154](https://github.com/dgloeckner/ruderbar/pull/154)** · ~~[coverage/ruling protocol](https://github.com/dgloeckner/ruderbar/issues/168)~~ **shipped** | — |
| 2 | [Balance definition](https://github.com/dgloeckner/ruderbar/issues/83) · [credit exclusion](https://github.com/dgloeckner/ruderbar/issues/80) · **[settlement-layer exclusion](https://github.com/dgloeckner/ruderbar/issues/161)** · [export reporting](https://github.com/dgloeckner/ruderbar/issues/114) | Phase 0 |
| 3 | ~~[Cancellation](https://github.com/dgloeckner/ruderbar/issues/81)~~ · ~~[atomicity](https://github.com/dgloeckner/ruderbar/issues/86)~~ **shipped** — gate is `CancellationGate` (submitted state + execution-date backstop, generalised per method), items retained via `active_transaction_id`, cancellation wrapped in a DB transaction, export refuses a cancelled run · ~~[EndToEndId](https://github.com/dgloeckner/ruderbar/issues/150)~~ **shipped** — derived from settlement + member (`EndToEndId::forCollection`), never a loop index, and stored on every item of the collection at export · [lead time](https://github.com/dgloeckner/ruderbar/issues/113) · [undo UX](https://github.com/dgloeckner/ruderbar/issues/127) · [tests](https://github.com/dgloeckner/ruderbar/issues/100) | Phases 0–2 |
| 4 | [Sync contract](https://github.com/dgloeckner/ruderbar/issues/82) · ~~[field authority](https://github.com/dgloeckner/ruderbar/issues/79)~~ **shipped** — `TerminalTransactionAllowlist` rebuilds every uploaded row from named fields, forcing `transaction_type = purchase` and NULLing `related_transaction_id` (the denial-of-correction guard: a forged link consumed #169's single reversal slot) and `created_by_admin_id`; `occurred_at` stays exactly as sent and is flagged when implausible, never clamped or rejected (ruling #144 §2); `additionalProperties: false` on the terminal `Transaction` schema as defence in depth behind it. Two items of ruling #144 remain, now carried: [price-divergence flag](https://github.com/dgloeckner/ruderbar/issues/204) (§3 item 7) and [OAS request validation enforcing in test](https://github.com/dgloeckner/ruderbar/issues/205) (§4 item 9) · ~~[`sepa_invalid` removal](https://github.com/dgloeckner/ruderbar/issues/162)~~ **shipped** — sync stores and flags; the flag is the settlement preview's `ineligible_members` bucket · ~~[idempotency tests](https://github.com/dgloeckner/ruderbar/issues/99)~~ **shipped** — the ADR-0004 retry guarantee is now pinned at the sync endpoint: a whole batch resent, a partial-overlap retry (3 sent, 2 repeated plus 1 new, 4 rows after), a repeat inside one batch, and a replay arriving after the sale was stornoed. The refusal case is pinned as *survives the retry* — a row the database refuses must be refused identically each time and never decay into a "duplicate", which is the shape that made `INSERT IGNORE` lose sales. Each test was checked against two mutations of `TransactionsRepository` (restored `INSERT IGNORE`; `isDuplicateKey` forced false) to confirm it fails when the guarantee is removed | Phase 0 |
| 5 | ~~[Auth lockout](https://github.com/dgloeckner/ruderbar/issues/78)~~ **shipped** — `/api/auth/mfa` now carries the limiter it never had; counting is per IP **and** per account (5 / 15 min, windowed) on both auth routes, with the account resolved from the request body at the password step and from the MFA-pending session at the code step. Five wrong codes destroy the pending session, and every wrong code is **persisted** to `login_attempts` — the persistence is what closes the re-mint loop, since a session cap alone is defeated by re-authenticating. Clearing moved to after *full* authentication, scoped `WHERE email = :email`, which also closes the previously unfiled IP-wide reset hole. `LoginAttemptsRepository` extracted (the #118 half). Slim harness (task 6.1) built here · [auth tests](https://github.com/dgloeckner/ruderbar/issues/101) | — |
| 6 | [Categories modal](https://github.com/dgloeckner/ruderbar/issues/88) · ~~[PeriodPicker](https://github.com/dgloeckner/ruderbar/issues/89)~~ **shipped** — the picker announces its range from the button click instead of from a `useEffect`, and each page seeds its initial range from `getPeriodRange()` (`admin-frontend/src/utils/periods.ts`) rather than waiting to be told. Both consumers memoize their handler. Journal and Settlements can be paged again, pinned by an E2E test per page | — |
| 7 | ~~[Terminal client](https://github.com/dgloeckner/ruderbar/issues/152)~~ **shipped** — a per-item rejection is permanent and **quarantines** the row (schema 9: `quarantined_at`, `quarantine_reason`), so it leaves the sync queue without being lost; a transient whole-request failure still retries the batch unchanged. A quarantined sale raises an undismissable staff banner opening a failed-sales list (member, amount, time). SEPA-only block at scan, `occurred_at`, Guthaben and the storno/payout labels were already in place and are now pinned by tests | Phase 0, ships **with** Phase 4 |
| 8 | ~~[Storno in code, specs and UI](https://github.com/dgloeckner/ruderbar/issues/169)~~ **shipped** — `POST /admin/transactions/{id}/storno {reason}` replaces the three free-amount correction routes; amount derived as the exact negation, SEPA gate removed, audit entry written (`transaction_storno`, migration 009), 409 arbitrated by the unique index rather than the service, storno-of-storno refused. Admin UI is a Journal **row action** with a confirmation naming member/product/amount/date, and the linkage is shown in both directions. **Manual purchase cut** (UC-A21 rejected 2026-08-08), so the system now has **no free-amount input anywhere** | Phase 0 |
| — | **[Specs, use-cases and ADRs](https://github.com/dgloeckner/ruderbar/issues/172)** — ADRs and use-cases **done**; specs, client and ERM ship with #169/#151 | — |

Phases 4, 5, 6 and the docs work are independent and can run in parallel with the settlement track.

⚠️ **[#168](https://github.com/dgloeckner/ruderbar/issues/168) is time-sensitive.** [`2026-08-07-backend-test-coverage.md`](./2026-08-07-backend-test-coverage.md) pins *current* behaviour by design, and its M2–M6 run straight through code these rulings govern — M2.4 is written to test the exact limiter [#145](https://github.com/dgloeckner/ruderbar/issues/145) replaced. Every milestone landing before the fixes writes tests that must later be deleted.

---

## Issues that collapse

| Issue | Disposition |
|---|---|
| [#87 Net-negative settlement total](https://github.com/dgloeckner/ruderbar/issues/87) | **Closed, superseded** by the exclusion rule |
| [#155 cancelSettlement state guard](https://github.com/dgloeckner/ruderbar/issues/155) | **Closed, duplicate** of #81/#86; already answered by the cancellation ruling |
| [#156 recordCorrection](https://github.com/dgloeckner/ruderbar/issues/156) | **Mostly dissolved** — no caller-supplied amount means no sign convention and no zero to reject. Only the missing audit entry survives, carried into #169 |
| [#86 cancelSettlement non-atomic](https://github.com/dgloeckner/ruderbar/issues/86) | Same code path as [#81](https://github.com/dgloeckner/ruderbar/issues/81); fix together |
| [#114 export silently omits](https://github.com/dgloeckner/ruderbar/issues/114) | Splits across Phase 2 (reporting) and Phase 3 (cancelled-settlement guard) |
| [#83 balance definition](https://github.com/dgloeckner/ruderbar/issues/83) | Splits: backend Phase 2, terminal display in [#152](https://github.com/dgloeckner/ruderbar/issues/152) |
| [#118 repository bypasses](https://github.com/dgloeckner/ruderbar/issues/118) | `LoginAttemptsRepository` half **shipped with [#78](https://github.com/dgloeckner/ruderbar/issues/78)** — `AuthController` and `RateLimitMiddleware` no longer run raw SQL. `TerminalTokenAuth`'s inline insert and the Dashboard controller's raw SQL remain |
| [#128 Journal selection](https://github.com/dgloeckner/ruderbar/issues/128) | **Reshaped** — selection now picks members. Fix after Phase 2 |
| [#97](https://github.com/dgloeckner/ruderbar/issues/97), [#93](https://github.com/dgloeckner/ruderbar/issues/93) | **Closed** — fixed in #154 but not referenced by it |

---

## Out of scope

- **Automated bank return-file ingestion** (camt.053 / pain.002) — a new capability, not a remediation. Returns are recorded manually.
- **Whether the bar should sell at a margin.** ⚠️ [The bookkeeping research](https://github.com/dgloeckner/ruderbar/issues/174) found at-cost pricing is a larger **Gemeinnützigkeit** exposure than the wGB classification: absorbing electricity, hardware, cleaning and fees while selling at purchase price engineers a structural loss covered from Mitgliedsbeiträgen, which AEAO zu § 55 Nr. 4 forbids. A small margin is **safer**. Steuerberater question; the software handles either identically.
- The club's wider GDPR posture — Datenschutzbeauftragter, TOMs, Auftragsverarbeitung.
- The ~50 non-critical issues beyond those folded in above.

---

## Method note

Three premises in this effort turned out wrong when tested: that the mandate defects were defects (they were [ADR-0006](../adr/0006-sepa-mandate-reference-strategy.md) and [ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md) working as written), that ADR-0028's applicability was established (it was assumed, in a research brief the agent wrote itself), and — surviving the test — that the bar carries bookkeeping obligations.

**Read `adr/README.md` before filing or resolving anything; do not grep for a keyword.** ADR-0006 and ADR-0020 were the two most affected by the storno work and mention corrections nowhere. When writing a research ticket, state the premise as a **question**, never as background.
