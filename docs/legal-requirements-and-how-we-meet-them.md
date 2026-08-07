# Legal Requirements and How We Meet Them

**Last reviewed:** 2026-08-07

Every legal constraint established by research during the money-semantics work, and the specific mechanism that satisfies it. The constraints themselves live in [ADR-0028](../adr/0028-legal-constraints-on-money-handling.md) and [ADR-0029](../adr/0029-two-tier-retention-and-erasure.md); this page is the **mapping**, so that a change to any mechanism can be traced back to what it was there for.

> ⚠️ **Not legal advice.** Items marked ⚠️ need confirmation from the club's Steuerberater or bank; they are flagged where research found no authority, not smoothed over. Sources: [#140](https://github.com/dgloeckner/ruderbar/issues/140), [#149](https://github.com/dgloeckner/ruderbar/issues/149), [#159](https://github.com/dgloeckner/ruderbar/issues/159), [#174](https://github.com/dgloeckner/ruderbar/issues/174), with full working in `research/`.

---

## 1. Tax and bookkeeping

| # | Requirement | Source | How we meet it |
|---|---|---|---|
| 1.1 | The bar is a **taxable wirtschaftlicher Geschäftsbetrieb** — members-only and zero-margin change nothing | AEAO zu § 67a Nr. 10 · BFH I R 13/13 · § 14 S. 2 AO | Nothing to build. It is why everything below applies |
| 1.2 | **Ordnungsmäßige Aufzeichnungen** of income and expenses — *threshold-free*, no exemption at any size | § 63 Abs. 3 AO | Every drink is an immutable transaction row; settlements aggregate them |
| 1.3 | **Einzelaufzeichnung** — no escape available to us | § 146 Abs. 1 S. 1 AO | Per-drink rows. The S. 3 escape needs *Barzahlung* **and** *unknown persons*; we have neither |
| 1.4 | Aggregated booking is allowed **only if** the underlying records are retained | GoBD Rz. 99 | The monthly settlement is the Buchung; per-drink rows are retained as the Vorsystem |
| 1.5 | **Unveränderbarkeit** — entries may not be altered so the original is unrecoverable | § 146 Abs. 4 AO | Append-only transactions; no UPDATE or DELETE exists in `src` ([ADR-0004](../adr/0004-immutable-transaction-storage.md)) |
| 1.6 | A correction must be **traceable to the booking it corrects** | GoBD Rz. 64 | A storno names its original: `related_transaction_id` **NOT NULL**, amount derived, UNIQUE so it happens once ([UC-A23](../use-cases/admin/UC-A23-storno.md)) |
| 1.7 | Retention: **10 years** journal, **8 years** Belege, from 31.12. of the year of last entry, suspended while the Festsetzungsfrist runs | § 147 Abs. 1 Nr. 1 / Nr. 4, Abs. 3 S. 5, Abs. 4 AO | `retention_expires_at` stamped at offboarding + [the annual deletion procedure](./retention-deletion-procedure.md). ⚠️ The 8-vs-10 split has no authority post-BEG IV |
| 1.8 | Born-digital records must **stay electronic** and machine-evaluable — cannot be printed and purged | GoBD Rz. 119, 129, 157 | No print-and-delete path exists. ⚠️ **Digitising the Strichliste raises the burden**; the paper tally carried none of this |

**Not applicable, and why it matters:** the **KassenSichV/TSE** regime needs *at least partly* cash payment (§ 1 Abs. 1 KassenSichV), and § 1 Abs. 2 excludes Waren- und Dienstleistungsautomaten. We take **no cash, as policy** — that policy is load-bearing, and re-admitting cash reopens the analysis ([ADR-0028](../adr/0028-legal-constraints-on-money-handling.md) §6).

⚠️ **Unresolved:** AEAO zu § 146a Nr. 1.2 extends Kassenfunktion to *„virtuelle (Kunden-)Konten"*. A **prepaid** balance plausibly falls in scope; our post-paid tab is the better reading but **no authority was found either way**. Mitigation: terminal-side top-up is ruled out **by design**, not merely unbuilt.

---

## 2. SEPA and payments

| # | Requirement | Source | How we meet it |
|---|---|---|---|
| 2.1 | A collection line must be **strictly positive** | pain.008 `InstdAmt minInclusive="0"` | Members at ≤ 0 are excluded from the file; the invariant sits at settlement, never at data entry |
| 2.2 | A collection needs a **valid mandate with a real signature date** | pain.008 mandate block | `mandates.signed_at` **NOT NULL**. The old `?? settlement_date` fallback — which told the bank a member signed on a day they did not — is removed |
| 2.3 | **8 weeks** to reclaim an authorised collection, no reason needed | § 675x Abs. 4 BGB | Settlement reversal ([#148](https://github.com/dgloeckner/ruderbar/issues/148)); a `bank_return` places the member on collection hold so the next sweep cannot re-debit the disputed amount |
| 2.4 | **13 months** where no valid mandate existed — an unauthorised transaction | § 676b Abs. 2 BGB | Largely *prevented*: SEPA-only plus 2.2 means unmandated collections should not occur |
| 2.5 | A mandate unused for **36 months** must be **cancelled by the creditor** | EPC SDD Core Rulebook §4.2 | ⚠️ **Not yet modelled.** The mandate record can express it (`is_active`, `ended_at`) but nothing enforces it |
| 2.6 | A returned collection is matched by `EREF+` / `MREF+`; the Verwendungszweck is **never returned** | DK Anlage 3 | Mandates are append-only, so a bank change creates a new one and the old reference stays resolvable. Persisted EndToEndId: [#150](https://github.com/dgloeckner/ruderbar/issues/150) |
| 2.7 | Expect **`MS03`** domestically — Germany suppresses AM04/AC04/MD07 | DK / data protection practice | Return entry is a **lookup**, not a form; do not depend on the reason code |
| 2.8 | Over-collection is a **civil-law debt** regardless of what the software permits | § 812 Abs. 1 S. 1 BGB | Credit balances are representable; carry-forward by default, payout at offboarding |

---

## 3. Data protection

| # | Requirement | Source | How we meet it |
|---|---|---|---|
| 3.1 | Erasure does not reach data whose processing is **legally compelled** | Art. 17(3)(b) DSGVO | Two-tier model: operational tier deleted, retention tier kept ([ADR-0029](../adr/0029-two-tier-retention-and-erasure.md)) |
| 3.2 | Retention is bounded by the **scope of the recording duty** — not a blanket "keep everything" | GoBD Rz. 113 · OLG Dresden 4 U 1278/21 | Field-level split: email, phone, card UID, credentials, avatar, notes, address and DOB are **deleted**; per-transaction records, settlements, IBAN, UMR and the mandate document are retained |
| 3.3 | Data must not be kept beyond its period | Art. 5(1)(e) DSGVO | `retention_expires_at` + [the annual procedure](./retention-deletion-procedure.md), minuted at the Kassenprüfung |
| 3.4 | Data-subject rights: access, rectification, erasure, restriction, portability | Art. 15–20 DSGVO | `use-cases/dsgvo/` — note UC-DSGVO-02 and 05 were corrected on 2026-08-07 |
| 3.5 | **Information at collection** — purposes, legal bases, recipients, retention, rights | Art. 13 DSGVO | ⏳ **Open** — the onboarding form, [#175](https://github.com/dgloeckner/ruderbar/issues/175). Research running |
| 3.6 | Legal basis per purpose | Art. 6 DSGVO | ⏳ **Open** in #175. Working hypothesis, **unresearched**: Art. 6(1)(b)/(c) for the core, consent only for genuinely optional processing |

⚠️ **No settled case law** holds that Art. 17(3)(b) covers § 147 AO. Universal supervisory view, never adjudicated head-on.

⚠️ Address and date of birth are deletable **only because the club issues no invoices**. Issue one Rechnung with USt and § 14 Abs. 4 Nr. 1 UStG pulls the address onto a retained Beleg.

---

## 4. Gemeinnützigkeit — the open risk

⚠️ **Selling at purchase price is a larger exposure than the tax classification ever was**, and it is **not a software question**.

Once the bar absorbs fridge electricity, hardware depreciation, cleaning and bank fees, at-cost pricing **engineers a structural loss covered from Mitgliedsbeiträgen** — which AEAO zu § 55 Nr. 4 forbids. Counter-intuitively, **a small margin is safer than none**.

The system handles either pricing identically. **This needs a Steuerberater conversation, not a code change.**

Related: crediting members' tabs as a reward (the goodwill credit, deliberately **not** built) would be Mitgliederbegünstigung under § 55 AO.

---

## What is still open

**Engineering:**

| | |
|---|---|
| 2.5 | The 36-month mandate-cancellation duty (EPC §4.2) is modelled nowhere |
| 3.5, 3.6 | ✅ **Answered** in [#175](https://github.com/dgloeckner/ruderbar/issues/175) — Art. 13 content and legal bases settled; BGH II ZR 132/24 makes Vereinsbeitritt a Vertrag under Art. 6(1)(b) |
| — | [#177](https://github.com/dgloeckner/ruderbar/issues/177) remove the named member ranking — it violates the no-profiling control, and Art. 13(2)(f) makes that control a legal commitment |
| ⚠️ | Prepaid/post-paid boundary under § 146a AO — no authority either way. Mitigated: terminal-side top-up is ruled out **by design** |

**The club's, not the code's** — tracked as `owner-action`:

| | |
|---|---|
| [#178](https://github.com/dgloeckner/ruderbar/issues/178) | Steuerberater: does **at-cost pricing** endanger Gemeinnützigkeit? ⚠️ largest exposure surfaced |
| [#179](https://github.com/dgloeckner/ruderbar/issues/179) | Steuerberater: must the tax record **identify the member on every line item**? A "no" only ever reduces work |
| [#180](https://github.com/dgloeckner/ruderbar/issues/180) | Vorstand: adopt a **Barordnung** — BGH Rn. 23 makes the Satzung define lit. b's scope |
| [#181](https://github.com/dgloeckner/ruderbar/issues/181) | **Art. 30 Verzeichnis** — required; the *nicht nur gelegentlich* exception bites |
| [#182](https://github.com/dgloeckner/ruderbar/issues/182) | Kassenprüfung: adopt the **annual retention and data-protection reviews** |
| [#183](https://github.com/dgloeckner/ruderbar/issues/183) | Bank: are returns booked **individually**? If not, manual entry fails |

A **Datenschutzbeauftragter is not required** (§ 38 BDSG threshold 20; realistic count 3–6) — ⚠️ but that depends on keeping admin-panel logins narrow.
