# Research: Art. 9 DSGVO, RFID, screen display, and retention periods

**Ticket:** [#175](https://github.com/dgloeckner/ruderbar/issues/175) (partial — see "What is still missing")
**Date:** 2026-08-07
**Status:** Findings recovered from a research agent that completed while its siblings failed on session limits. Preserved here before the remaining questions are re-run.

> ⚠️ **Not legal advice.** Confidence is stated per finding. Items marked **NO AUTHORITY FOUND** are gaps the researcher looked for and did not find — they are not omissions.

---

## 1. ⚠️ The big one: are per-drink records Art. 9 special-category data?

**Genuinely open, and it matters more than anything else here.**

The threshold from **CJEU C-21/23 (Lindenapotheke, 04.10.2024)** para 83 is very low:

> „genügt folglich, dass aus diesen Daten mittels gedanklicher Kombination oder Ableitung auf den Gesundheitszustand der betroffenen Person geschlossen werden kann"

Probability suffices, not certainty (para 90). Controller intent is irrelevant (para 87). Accuracy of the inference is irrelevant. And para 91 **expressly names a Kundenkonto / Treueprogramm structure as an aggravating factor** — which is exactly what an RFID running tab is.

**But** the *ratio* of C-21/23 turns on something beverages lack (para 84): the order

> „stellt eine Verbindung zwischen einem **Arzneimittel, seinen therapeutischen Indikationen und Anwendungen** und einer identifizierten … natürlichen Person her"

A medicine has a defined therapeutic indication, so the inference runs *product → condition → person*. Beer has none; the inference runs *consumption → population-level risk*, which is not a statement about **this** person's Gesundheitszustand.

| Proposition | Assessment | Confidence |
|---|---|---|
| A single row *"Member X, 1× Pils, 19:42"* is a Gesundheitsdatum | **No** | ~80% |
| Product category alone triggers Art. 9 | **No** — C-252/21 para 72 requires the data *tatsächlich* enable disclosure | High |
| A **10-year, per-unit, named** consumption history in a **few-dozen-member** club reveals health data | **Coin flip, ~40–50%** | **Low** |
| A German DPA or court has ruled on alcohol consumption data | **NO AUTHORITY FOUND** | High |

Two features push *our* system harder toward Art. 9 than a supermarket till: **longitudinal quantification under a persistent identifier for a decade** (a consumption dossier, not a receipt), and **small-n** — in a club of a few dozen, an abrupt permanent switch to non-alcoholic drinks plausibly reveals a health event, pregnancy, medication or recovery. That pattern is *closer* to a real health revelation than the C-21/23 facts.

### ⚠️ If Art. 9 applies, the exits are bad

- **Art. 9(2)(d) is NOT a general Vereinsprivileg.** It covers bodies that are *„politisch, weltanschaulich, religiös oder gewerkschaftlich ausgerichtet"*. A rowing club is none of these. **This is a common and dangerous misreading.**
- **§ 22 BDSG** offers no usable gateway; lit. d (*erhebliches öffentliches Interesse, zwingend erforderlich*) has **NO AUTHORITY** applying it to routine tax retention.
- **§ 24 BDSG is irrelevant** — its Zweckänderung list is closed (Gefahrenabwehr/Strafverfolgung; *zivilrechtliche* Ansprüche) and does not include tax retention. Nor is a Zweckänderung analysis needed: Art. 6(1)(c)+(3) with § 147 AO covers it, and Art. 6(4) exempts it from the compatibility test.
- That leaves **Art. 9(2)(a) explicit consent** — which then **collides with § 147 AO** on withdrawal. **NO AUTHORITY** resolves that conflict.

### Design consequences — act on these regardless

The researcher's recommendation, which we should adopt: **design so Art. 9 does not credibly bite**, rather than betting on the answer.

1. **Do not build consumption-profile views.** No per-member drink-type breakdowns, no leaderboards, no "your favourite drink", no trend charts. Each is a step from *billing record* toward *health inference*, and is exactly what a supervisory authority would seize on.
2. **Separate the billing layer** (member, period, total) **from the tax-record layer** (line items). Restrict human access to line items to what a Betriebsprüfung actually requires.
3. ⚠️ **Open question worth asking the Steuerberater:** does the tax record need the **member identity on every line item**, or would a per-member periodic total plus anonymised line items satisfy §§ 146, 147 AO? The researcher calls this *"the single highest-leverage design question in the whole system"* and found **no authority** on it.

This aligns with, and sharpens, [ADR-0029](../adr/0029-two-tier-retention-and-erasure.md)'s two-tier model.

---

## 2. Retention: 10 years confirmed, and *why* — with a correction to how it is usually reported

**§ 147 Abs. 1 AO:** Nr. 1 = *Bücher und **Aufzeichnungen***. Nr. 4 = *Buchungsbelege*.

**§ 147 Abs. 3 S. 1 AO (verified in force):** Nr. 1 and 4a → **10 years**; Nr. 4 → **8 years**; sonstige → 6 years.

> ⚠️ **BEG IV's reduction applies ONLY to Buchungsbelege (Nr. 4).** It did **not** create a general "8 instead of 10". This is frequently misreported — and [ADR-0028](../adr/0028-legal-constraints-on-money-handling.md) §5 originally had it wrong in exactly that way.

**Per-drink records are Nr. 1 *Aufzeichnungen* → 10 years.** The chain: § 146 Abs. 1 S. 1 AO commands that *„die Buchungen und die sonst erforderlichen **Aufzeichnungen** … **einzeln**"* be made; those line items *are* the Einzelaufzeichnungen; § 147 Abs. 1 Nr. 1 uses the same statutory term; § 63 Abs. 3 AO independently demands *„ordnungsmäßige **Aufzeichnungen** über ihre Einnahmen und Ausgaben"*.

**Confidence ~85%.** The GoBD itself could not be primary-verified (BMF site behind a Radware challenge), so the Nr. 1-not-Nr. 4 classification rests on the textual argument plus consistent secondary sources. The asymmetry decides it: deleting at 8 when 10 was right is a § 147 breach; keeping to 10 when 8 was right costs two years of a minimisation problem.

**Transitional rule (new to us):** Art. 97 § 19a Abs. 2 EGAO applies the 8-year period retroactively to Buchungsbelege whose period had not expired by 31.12.2024. Abs. 3 carves out banks, insurers and investment firms — irrelevant to the club.

**§ 141 AO thresholds:** €800,000 turnover / €80,000 profit — orders of magnitude above the club, and the duty only starts after the Finanzamt notifies (Abs. 2). **§ 140 AO** brings nothing in: a small e.V. is not a Kaufmann.

**§ 146 Abs. 1 S. 3's escape confirmed unavailable** — it requires *„nicht bekannte Personen"* **and** *„gegen Barzahlung"*. The club fails both limbs. Einzelaufzeichnung applies in full.

---

## 3. The RFID card UID is personal data — settled

**Erwägungsgrund 30 DSGVO names it outright:**

> „…oder sonstige Kennungen wie **Funkfrequenzkennzeichnungen**"

With Art. 4 Nr. 1 (restated in C-21/23 para 77), that is conclusive: the UID is mapped to a named member, so the combination exists by construction. **Confidence: Very High.**

**Art. 29 WP, WP 105 (2005)** — retrieved and quoted. Its substance survives because EG 30 codified it. Its 2005 *token* example is uncannily close to our system, and it already flags **health** as an inference from a token-linked purchase file.

**Design notes:** use a **random UID** that encodes no member number or name; store **no personal data on the tag**; note that passive tags answer any reader in range, so a third party with a cheap reader can enumerate cards in the clubroom — an Art. 32 consideration.

**NO AUTHORITY FOUND**: no German DPA guidance on RFID membership cards or chip cards in clubs. WP175 not retrieved; WP105's post-GDPR endorsement status unverified.

---

## 4. Screen display: processing, but no separate legal basis needed

**Art. 4 Nr. 2 DSGVO** settles that display is processing — *„Offenlegung durch Übermittlung, Verbreitung oder eine andere Form der **Bereitstellung**"*.

But it needs **no separate basis** where it serves the same purpose: showing a member their own name and balance at checkout is constitutive of performing the contract, so **Art. 6(1)(b)** covers the display step as it covers storage. A *different* purpose — cycling through all balances, a "top consumers" board, leaving the last session on screen — would need its own basis, realistically only consent.

**The real constraints are Art. 5(1)(c), Art. 25 and Art. 32:**

- **Sichtschutzfolie**, viewing angle, mounting height — the pharmacy-counter analogue
- **Minimal identity** — first name + last initial may suffice; full name plus an exact Euro balance to bystanders exceeds *das notwendige Maß*
- **Balance behind a second action** — show the basket total by default, the running tab only on request
- **Aggressive session clearing** after checkout
- ⚠️ **Never display line items post-checkout** — a screen reading *"Max Müller — 4× Pils"* is the worst case, combining a possible Art. 9 inference with disclosure to the room

**NO AUTHORITY FOUND** on POS screen display to bystanders. Closest analogue is LfDI BW's Schwarzes-Brett → Vereinsblatt → Internet spectrum, which places a members-only terminal at the **least** exposed end.

---

## 5. Employment law: § 26 BDSG is irrelevant here

Still formally in force, but widely regarded as inapplicable in Abs. 1 S. 1 after **C-34/21** (LfDI BW: *„wohl unanwendbar sein dürfte"*), with **C-65/23** adding that Art. 88 provisions must also satisfy Arts. 5, 6(1) and 9.

**Irrelevant to the bar regardless:** members are not Beschäftigte (§ 26 Abs. 8 is exhaustive), and even a Minijobber buying a beer is acting as a member, not *„für Zwecke des Beschäftigungsverhältnisses"*.

⚠️ One caveat for the Verzeichnis: **if the same RFID card were ever used for Arbeitszeiterfassung** of a Minijobber, that is a separate processing activity where this problem does bite.

---

## What is still missing from #175

The sibling agents died on session limits. **Not yet researched:**

1. What the **Art. 13 Datenschutzhinweis** must contain, and how a 10-year retention is expressed under Art. 13(2)(a)
2. **Legal basis per purpose**, and whether Vereinsmitgliedschaft counts as a *Vertrag* for Art. 6(1)(b)
3. Whether a **SEPA-Lastschriftmandat is a GDPR consent**, and how real German forms separate the two instruments
4. **Art. 30 Verzeichnis** and **§ 38 BDSG Datenschutzbeauftragter** thresholds for a Verein
5. **Common practice** — DOSB/LSB Aufnahmeantrag templates

---

## Sources

**Primary, verified:** [DSGVO](https://eur-lex.europa.eu/eli/reg/2016/679/oj/deu) · [BDSG](https://www.gesetze-im-internet.de/bdsg_2018/) · [AO](https://www.gesetze-im-internet.de/ao_1977/) §§ 63, 140, 141, 146, 147 · [Art. 97 § 19a EGAO](https://www.gesetze-im-internet.de/aoeg_1977/art_97__19a.html) · [WP 105](https://ec.europa.eu/justice/article-29/documentation/opinion-recommendation/files/2005/wp105_en.pdf)

**CJEU, full German text retrieved:** C-21/23 Lindenapotheke · C-184/20 OT · C-252/21 Meta/Bundeskartellamt · C-34/21 · C-65/23

**Official guidance:** [LfDI BW Orientierungshilfe Verein](https://www.baden-wuerttemberg.datenschutz.de/orientierungshilfe-datenschutz-verein/) · [LfDI BW FAQ zu C-34/21](https://www.baden-wuerttemberg.datenschutz.de/faq-rechtsgrundlagen-bei-beschaeftigtendaten/)

**Not verified:** GoBD (BMF site blocked) · WP175 · EDPB Endorsement 1/2018 · § 26 BDSG legislative pipeline · § 146a AO / KassenSichV applicability to the terminal
