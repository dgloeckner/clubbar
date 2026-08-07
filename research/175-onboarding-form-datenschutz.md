# Research: Onboarding form — Art. 13 notice, legal bases, SEPA mandate vs. consent, Art. 30 / § 38 BDSG, common practice

**Ticket:** [#175](https://github.com/dgloeckner/ruderbar/issues/175)
**Date:** 2026-08-07
**Companion:** `research/art9-rfid-display-retention.md` (Art. 9, RFID as personal data, screen display, retention periods) — not repeated here.

> ⚠️ Not legal advice. Confidence stated per finding. **NO AUTHORITY FOUND** = looked for, not found.

---

## Headline: how the two hypotheses fared

| Hypothesis in the brief | Verdict |
|---|---|
| Vereinsmitgliedschaft is a *Vertrag* for Art. 6(1)(b) — flagged as "asserted without checking", possibly contested | ✅ **SURVIVES, and is now settled by the BGH.** BGH 10.12.2025 – II ZR 132/24 holds it expressly, in a *Verein* case, with a Union-autonomous reading. The LfDI BW "rechtsgeschäftsähnliches Schuldverhältnis" wording is **not** a denial of Vertrag status — see §2. |
| Consent (Art. 6(1)(a)) would be *actively wrong* for the core, because it is revocable while the retention duty is not | ✅ **SURVIVES**, and is independently endorsed by LfDI BW as a *Täuschung* of the member. |
| A SEPA-Lastschriftmandat is **not** a GDPR consent | ✅ **SURVIVES on the law**, ❌ **but is contradicted by widespread practice.** Real German forms — including municipalities and a federal political party — routinely label the mandate an Art. 6(1)(a) Einwilligung. That practice is legally incoherent and we should not copy it. See §3. |

**The one thing that changed my prior:** I expected the LfDI BW "rechtsgeschäftsähnliches Schuldverhältnis" phrase to be a real obstacle. It is not — the same document calls membership a *Vertragsverhältnis* two sections earlier, and the BGH has since removed the question.

---

## 2. Legal basis per purpose

### 2.1 ⚖️ Is Vereinsmitgliedschaft a "Vertrag" under Art. 6(1)(b)? — YES, settled by the BGH

**BGH, Urteil vom 10.12.2025 – II ZR 132/24** (II. Zivilsenat, the Vereins-/Gesellschaftsrecht senate; *Nachschlagewerk: ja*, headnote on `DSGVO Art. 6 Abs. 1 Unterabs. 1 lit. b`). Rn. 22–23, verbatim:

> **Rn. 22:** „Der Beitritt zu einem Verein fällt unter den Vertragsbegriff des Art. 6 Abs. 1 Unterabs. 1 lit. b DSGVO."

> **Rn. 23:** „Entgegen der Ansicht der Revision ist der Begriff des Vertrags dabei **nicht zivilrechtlich auszulegen, sondern datenschutzrechtlich und unionsautonom**. Es kommt nicht darauf an, ob das Rechtsverhältnis, zu dessen Erfüllung die Verarbeitung erforderlich ist, ein Vertrag i.S.d. Bürgerlichen Gesetzbuchs ist, sondern ob das datenschutzrechtliche Telos des Erlaubnistatbestands erfüllt ist. … **Maßgeblich ist allein, ob das Rechtsverhältnis privatautonom begründet ist und die maßgebliche Verpflichtung daher als Ausdruck der Selbstbestimmung legitimiert ist.** Der Tatbestand von lit. b ist auf all jene **vertragsähnlichen Konstellationen**, die gleichermaßen auf willentliche Entscheidungen des von der Verarbeitung Betroffenen zurückgehen, anzuwenden. **Vereinsgründung und -beitritt begründen damit einen Vertrag i.S.v. Art. 6 Abs. 1 lit. b DSGVO, dessen Inhalt durch die Vereinssatzung konkretisiert wird**, da es sich dabei um einen selbstbestimmt erklärten Beitritt zu einer privaten Vereinigung handelt."

> **Rn. 25:** „Die Bekanntgabe von Mitgliederdaten ist zur Wahrnehmung der Mitgliederrechte … regelmäßig im Rahmen der Mitgliedschaft gemäß Art. 6 Abs. 1 Unterabs. lit. b DSGVO zulässig."

Rn. 23 cites OLG Hamm ZIP 2023, 1897, 1902 and four standard commentaries (BeckOK/Albers-Veit; Kühling/Buchner; Plath; Gola/Heckmann) — i.e. this was already the prevailing view and the BGH confirmed it.

⚠️ **Note the friction with the EDPB.** EDPB Guidelines 2/2019 require that the contract be *valid under applicable national contract law*; the BGH expressly declines to make BGB validity the test, and even treats it as irrelevant that the club's Satzung dispenses with a Willenserklärung of the Verein on admission. So the BGH reading is **broader** than the EDPB's. For us this is a comfort, not a risk: our case is squarely within even the narrow reading, because the member signs an Aufnahmeantrag that the club accepts.

**The LfDI BW wording is not a counter-authority.** The same Orientierungshilfe says both:

> „**Die Mitgliedschaft in einem Verein ist als Vertragsverhältnis zwischen den Mitgliedern und dem Verein anzusehen**, dessen Inhalt im Wesentlichen durch die Vereinssatzung und sie ergänzende Regelungen (z.B. eine Vereinsordnung) vorgegeben wird." (Nr. 1.3.1)

> „…nur solche Daten von Mitgliedern erheben, die für die Begründung und Durchführung des zwischen Mitglied und Verein durch den Beitritt zustande kommenden **rechtsgeschäftsähnlichen Schuldverhältnisses** erforderlich sind" (Nr. 2.1)

Read together, "rechtsgeschäftsähnlich" is describing the *Vereinsrecht* character of the bond (Satzung-governed, not negotiated term-by-term), not denying Art. 6(1)(b). LfDI BW's own operative sentence names lit. b:

> „Als Rechtsgrundlage für die Verarbeitung personenbezogener Daten kommen insbesondere **Art. 6 Abs. 1 lit. b) und lit. f) DS-GVO** in Betracht"

**Confidence: Very High.** Binding BGH authority, on a Verein, on this exact provision, from December 2025.

### 2.2 Basis per purpose

| # | Purpose | Basis | Confidence |
|---|---|---|---|
| 1 | Membership administration (name, address, DOB, contact, Beitrag) | **Art. 6(1)(b)** — BGH II ZR 132/24 Rn. 22–25; content set by the Satzung | Very High |
| 2 | The bar tab: RFID identification, per-drink line items, running balance | **Art. 6(1)(b)** *if* bar use is part of the membership relationship (Satzung/Barordnung); otherwise lit. b as a **separate** purchase contract per drink | High |
| 3 | SEPA collection (IBAN, Mandatsreferenz, pain.008 to the bank) | **Art. 6(1)(b)** — necessary to perform the payment obligation. **Not** Art. 6(1)(a). See §3 | High |
| 4 | 10-year retention of the accounting records | **Art. 6(1)(c)** i.V.m. § 147 Abs. 1 Nr. 1, Abs. 3 S. 1 AO and § 63 Abs. 3 AO. Art. 6(3) supplies the required member-state law | Very High |
| 5 | The RFID card / UID ↔ member mapping | **Art. 6(1)(b)** — the token is the technical means of performing purpose 2. It needs no basis of its own | High |
| 6 | Terminal display of name and balance to the member at checkout | **Art. 6(1)(b)** — constitutive of performing the contract; no separate basis. (Established in the companion file; the constraints are Art. 5(1)(c)/25/32, not Art. 6) | High |
| 7 | Photo on the club website, birthday lists, newsletter, WhatsApp group | **Art. 6(1)(a)** — genuinely optional, genuinely revocable, must be separately tick-boxed | Very High |

### 2.3 Is there a serious argument for Art. 6(1)(f)?

**Yes, but only as a fallback and only for edges — not for the core.**

- LfDI BW names lit. f alongside lit. b as a candidate basis for Vereine.
- Note the BGH in II ZR 132/24 reasons about a *berechtigtes Interesse* (Rn. 16–20) and then holds lit. b applies (Rn. 21–25) — i.e. it uses the interest analysis to establish *Erforderlichkeit*, then anchors in lit. b. It does not treat lit. f as the operative basis.
- Where lit. f is genuinely apt for us: **fraud/abuse detection on the bar system**, **IT security logging**, **retention of a defaulted claim beyond the contract for Rechtsverfolgung** (though Art. 17(3)(e) and § 195 BGB do that work anyway).
- ⚠️ Where lit. f is **not** apt: never use it to legitimise consumption profiling. A lit. f basis obliges you to disclose the interest under Art. 13(1)(d) *and* hands the member an Art. 21(1) Widerspruchsrecht — a right they do **not** have against lit. b or lit. c. Using lit. f where lit. b fits therefore makes the club's position worse, not better.

### 2.4 ⚠️ Why consent for the core would be actively wrong — confirmed by the DPA in terms

LfDI BW, Nr. 1.3.4, is unusually blunt and worth quoting on the form-design decision:

> „**Es empfiehlt sich nicht, Einwilligungen für Datenverarbeitungsmaßnahmen einzuholen, die bereits aufgrund einer gesetzlichen Erlaubnis möglich sind.** Denn dadurch wird beim Betroffenen der Eindruck erweckt, er könne mit der Verweigerung der Einwilligung oder ihrem späterem Widerruf die Datenverarbeitung verhindern. Hat der Verein aber von vornherein die Absicht, im Falle der Verweigerung des Einverständnisses auf die gesetzliche Verarbeitungsbefugnis zurückzugreifen, **wird der Betroffene getäuscht**, wenn man ihn erst nach seiner ausdrücklichen Einwilligung fragt, dann aber doch auf gesetzliche Ermächtigungen zurückgreift."

That is exactly our situation: a member who "withdraws consent" to the drink records cannot make § 147 AO go away. Asking for consent we would not honour is a *Täuschung*. Add Art. 7(4) (Kopplungsverbot) and LfDI BW's rule:

> „Die Aufnahme in einem Verein darf grundsätzlich nicht von der Einwilligung in die Datenverarbeitung für **vereinsfremde Zwecke** abhängig gemacht werden (Art. 7 Abs. 4 DS-GVO)."

**Design rule for the form: consent boxes only for things the club will actually stop doing on withdrawal.**

---

_(sections 1, 3, 4, 5 follow — see git history)_
