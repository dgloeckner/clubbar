# Legal Requirements and How We Meet Them

**Last reviewed:** 2026-08-20

Every legal constraint established by research during the money-semantics work, and the specific mechanism that satisfies it. The constraints themselves live in [ADR-0028](../adr/0028-legal-constraints-on-money-handling.md) and [ADR-0029](../adr/0029-two-tier-retention-and-erasure.md); this page is the **mapping**, so that a change to any mechanism can be traced back to what it was there for.

> ⚠️ **Not legal advice.** Items marked ⚠️ need confirmation from the club's Steuerberater or bank; they are flagged where research found no authority, not smoothed over. Sources: [#140](https://github.com/dgloeckner/clubbar/issues/140), [#149](https://github.com/dgloeckner/clubbar/issues/149), [#159](https://github.com/dgloeckner/clubbar/issues/159), [#174](https://github.com/dgloeckner/clubbar/issues/174), with full working in `research/`:

| File | Covers |
|---|---|
| `club-bookkeeping-obligations.md` | Whether the bar carries bookkeeping duties at all, at what granularity — the premise that everything else rests on |
| `correction-bookkeeping-law.md` | GoBD Rz. 64 linkage; payout Belege; the KassenSichV exit |
| `art9-rfid-display-retention.md` | Art. 9 and consumption data; RFID as personal data; screen display; retention classification |
| `175-onboarding-form-datenschutz.md` | Art. 13 content; legal basis per purpose; mandate-vs-consent; Art. 30 / § 38 BDSG; form practice |
| `credit-limit-precedents.md` | Credit-balance and refund precedents |
| `juschg-age-limits.md` | JuSchG § 9's two thresholds; why the limit rides the product; why the terminal prevents and the server only records |
| `electronic-signature-onboarding.md` | Which simple electronic signatures hold for the Beitritt, the SEPA mandate and the Kenntnisnahme; what courts wanted as proof; what the bank decides; minors; the 2027 wallet QES |

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
| 2.3 | **8 weeks** to reclaim an authorised collection, no reason needed | § 675x Abs. 4 BGB | Settlement reversal ([#148](https://github.com/dgloeckner/clubbar/issues/148)); a `bank_return` places the member on collection hold so the next sweep cannot re-debit the disputed amount |
| 2.4 | **13 months** where no valid mandate existed — an unauthorised transaction | § 676b Abs. 2 BGB | Largely *prevented*: SEPA-only plus 2.2 means unmandated collections should not occur |
| 2.5 | A mandate unused for **36 months** must be **cancelled by the creditor** | EPC SDD Core Rulebook §4.2 | ⚠️ **Not yet modelled.** The mandate record can express it (`is_active`, `ended_at`) but nothing enforces it |
| 2.6 | A returned collection is matched by `EREF+` / `MREF+`; the Verwendungszweck is **never returned** | DK Anlage 3 | Mandates are append-only, so a bank change creates a new one and the old reference stays resolvable. The EndToEndId is derived from the settlement and the member and stored on every item of the collection at export time, so an `EREF+` quoted back resolves to exactly one collection ([#150](https://github.com/dgloeckner/clubbar/issues/150), shipped) |
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
| 3.5 | **Information at collection** — purposes, legal bases, recipients, retention, rights | Art. 13 DSGVO | ✅ Settled in [#175](https://github.com/dgloeckner/clubbar/issues/175). Notice is **never signed** (checkbox only); states the concrete period with statute **and** trigger event (WP260 rejects „solange wie nötig“ as insufficient), **names the bank** as recipient, and states that refusal means **no bar access** |
| 3.6 | Legal basis per purpose | Art. 6 DSGVO | ✅ **Art. 6(1)(b)** — membership, tab, RFID, collection, terminal display. **6(1)(c)** + §§ 147/63 AO — retention. **6(1)(a)** — optional extras only. **BGH II ZR 132/24 (10.12.2025)** settles Vereinsbeitritt as a *Vertrag*, read unionsautonom |
| 3.7 | A SEPA mandate is **not** a GDPR consent | § 675j Abs. 1 BGB | Separate signature on the mandate; the Datenschutzhinweis is never signed. ⚠️ Most real Verein forms get this **wrong** (Starnberg, LSB MV) — only the BSSB template is right |
| 3.8 | **Art. 13(2)(f)** — declare whether profiling occurs | Art. 13(2)(f) DSGVO | Declaring "no profiling" is truthful **only while** the no-profiling control holds — which makes it a legal commitment, not an internal rule. → [#177](https://github.com/dgloeckner/clubbar/issues/177) |
| 3.9 | **Art. 30 Verzeichnis** required — the *nicht nur gelegentlich* exception bites | Art. 30(5) DSGVO | → [#181](https://github.com/dgloeckner/clubbar/issues/181). A **DSB is not** required (§ 38 BDSG threshold 20; count 3–6) ⚠️ provided admin logins stay narrow |
| 3.10 | A **further processing purpose** needs its own named legal basis in the notice | Art. 13(1)(c) DSGVO | Holding a member's date of birth is Art. 6(1)(b) — membership administration, `research/175-onboarding-form-datenschutz.md` §2.2 purpose 1. **Using it to gate alcohol sales is a different purpose on a different basis: Art. 6(1)(c) i.V.m. § 9 JuSchG.** The software enforces it from [ADR-0045](../adr/0045-age-restricted-products.md); the notice needs a new row even though the input field already exists → [#591](https://github.com/dgloeckner/clubbar/issues/591) |
| 3.11 | **Data minimisation** — a terminal cache may hold only what its function needs | Art. 5(1)(c) DSGVO | The kiosk cache gains exactly one field, `date_of_birth`, and nothing else. It is never rendered, and no age derived from it is rendered — a refusal names what the *drink* requires, never what the member is. Erasure rides the ordinary delta sync, so no kiosk keeps a birth date the server has erased beyond one sync interval ([ADR-0045](../adr/0045-age-restricted-products.md), [`erm-frontend.md`](./erm-frontend.md)) |

⚠️ **No settled case law** holds that Art. 17(3)(b) covers § 147 AO. Universal supervisory view, never adjudicated head-on.

⚠️ Address and date of birth are deletable **only because the club issues no invoices**. Issue one Rechnung with USt and § 14 Abs. 4 Nr. 1 UStG pulls the address onto a retained Beleg. Since [ADR-0045](../adr/0045-age-restricted-products.md) the date of birth is no longer hypothetical — it is a real column on `members`, mandatory at creation, nulled by erasure, and mirrored on every terminal cache.

---

## 4. Jugendschutz

| # | Requirement | Source | How we meet it |
|---|---|---|---|
| 4.1 | Beer, wine and sparkling wine may **not be handed to anyone under 16** | § 9 Abs. 1 Nr. 1 JuSchG | `products.min_age = 16` on those drinks. The number is data, not code — the club sets it per product |
| 4.2 | Spirits — anything containing distilled alcohol — may **not be handed to anyone under 18** | § 9 Abs. 1 Nr. 2 JuSchG | `products.min_age = 18` |
| 4.3 | The limit binds **at the moment the drink is handed over**, in a room that may have no network | § 9 Abs. 1 JuSchG | The terminal refuses **offline**. `members.date_of_birth` and `products.min_age` both ride the ordinary delta sync, and the terminal computes the age from its own clock at checkout — never from a number the server derived, which is wrong from the member's next birthday until the next sync ([ADR-0045](../adr/0045-age-restricted-products.md) decision 1) |
| 4.4 | A missing age must not become a **permission** | § 9 Abs. 1 JuSchG | Date of birth is **mandatory** when a member is created, so there is no "unknown age" state. A cached NULL means *anonymized*, and an anonymized member is refused anything carrying a limit — there is no fail-open branch (decision 2, invariant 3) |
| 4.5 | The limit must not be removable **by accident** | — | It sits on the **product**, not the category. Rearranging the grid — an ordinary shift-time action with no legal character — cannot un-restrict a drink; only deliberately clearing a number on that product can, and that is an audited write |
| 4.6 | A sale that slipped through must become **known**, not disappear | § 9 JuSchG · § 146 Abs. 1 S. 1 AO (see 1.3) | A stale terminal can still sell. The server **stores the row and raises a `jugendschutz_violation` audit entry**; it never rejects. Rejecting would not un-serve the minor — it would delete the club's knowledge that it happened and trade a youth-protection incident for a bookkeeping one. Same two-layer split as [ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md): the terminal prevents, the server is the backstop |
| 4.7 | The record must stay true **after the fact** | — | The violation stores `min_age` as it stood at the sale and the age at that moment, and does not clear when the drink is later un-restricted (invariant 4). Unlike the derived `ineligible_members` bucket, a past sale to a minor is a fact, not a recomputed state |
| 4.8 | Enforcement must not **expose the member** to whoever is at the bar | Art. 5(1)(c) DSGVO · `research/art9-rfid-display-retention.md` (screen display) | The terminal never states a member's age or birth date. The refusal names what the *drink* requires — "ab 18" — never what the member is (invariant 6). The Getränkewart who sets the limit gains no member data at all: they can set, correct and clear `min_age` and still get 403 from the member roster ([`role-based-access.md`](./role-based-access.md)) |

⚠️ **Not modelled here:**

- **JuSchG § 3 Aushang** — Veranstalter and Gewerbetreibende must display the relevant provisions conspicuously on the premises. A physical obligation, not a software one, but somebody should check it.
- **§ 28 Ordnungswidrigkeit exposure** for the club and the individual serving. The system's contribution is that a violation becomes *known*, not that it becomes harmless.
- **Whether a Vereinsheim bar is a "Gaststätte"** for Abs. 1's purposes. It does not matter for the design: *"und sonst in der Öffentlichkeit"* catches it either way.

Full working in `research/juschg-age-limits.md`.

---

## 5. Gemeinnützigkeit — the open risk

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
| 3.5, 3.6 | ✅ **Answered** in [#175](https://github.com/dgloeckner/clubbar/issues/175) — Art. 13 content and legal bases settled; BGH II ZR 132/24 makes Vereinsbeitritt a Vertrag under Art. 6(1)(b) |
| — | [#177](https://github.com/dgloeckner/clubbar/issues/177) remove the named member ranking — it violates the no-profiling control, and Art. 13(2)(f) makes that control a legal commitment |
| ⚠️ | Prepaid/post-paid boundary under § 146a AO — no authority either way. Mitigated: terminal-side top-up is ruled out **by design** |

**The club's, not the code's** — tracked as `owner-action`:

| | |
|---|---|
| [#178](https://github.com/dgloeckner/clubbar/issues/178) | Steuerberater: does **at-cost pricing** endanger Gemeinnützigkeit? ⚠️ largest exposure surfaced |
| [#179](https://github.com/dgloeckner/clubbar/issues/179) | Steuerberater: must the tax record **identify the member on every line item**? A "no" only ever reduces work |
| [#180](https://github.com/dgloeckner/clubbar/issues/180) | Vorstand: adopt a **Barordnung** — BGH Rn. 23 makes the Satzung define lit. b's scope |
| [#181](https://github.com/dgloeckner/clubbar/issues/181) | **Art. 30 Verzeichnis** — required; the *nicht nur gelegentlich* exception bites |
| [#182](https://github.com/dgloeckner/clubbar/issues/182) | Kassenprüfung: adopt the **annual retention and data-protection reviews** |
| [#183](https://github.com/dgloeckner/clubbar/issues/183) | Bank: are returns booked **individually**? If not, manual entry fails |

A **Datenschutzbeauftragter is not required** (§ 38 BDSG threshold 20; realistic count 3–6) — ⚠️ but that depends on keeping admin-panel logins narrow.
