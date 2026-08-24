# Research: Onboarding form — Art. 13 notice, legal bases, SEPA mandate vs. consent, Art. 30 / § 38 BDSG, common practice

**Ticket:** [#175](https://github.com/dgloeckner/clubbar/issues/175)
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

## 1. What the Art. 13 Datenschutzhinweis must contain

### 1.1 The statutory floor

Art. 13 Abs. 1 (must be *mitgeteilt*) and Abs. 2 (must be *zur Verfügung gestellt*) — both at the moment of collection, i.e. **on/with the onboarding form**, not later:

| Art. 13 | Element | For us |
|---|---|---|
| 1(a) | Name and contact of the **Verantwortlicher** and any Vertreter | The e.V. itself, plus the vertretungsberechtigter Vorstand nach § 26 BGB (name him) |
| 1(b) | Contact of the **Datenschutzbeauftragter** | *„gegebenenfalls"* — omit if none is required (see §4). Do **not** invent one |
| 1(c) | **Zwecke** *and* the **Rechtsgrundlage** | Must be enumerated per purpose — see the table in §2.2 |
| 1(d) | The **berechtigte Interessen** where lit. f is used | Only needed if we actually use lit. f |
| 1(e) | **Empfänger oder Kategorien von Empfängern** | ⚠️ **The bank must be named here.** See §1.3 |
| 1(f) | **Drittlandtransfer** | State that none occurs — or name the country if hosting is outside the EU |
| 2(a) | **Speicherdauer oder Kriterien** | The 10-year point — see §1.2 |
| 2(b) | Rights: Auskunft, Berichtigung, Löschung, Einschränkung, **Widerspruch**, **Datenübertragbarkeit** | All six; note Art. 20 portability *does* apply because we rely on lit. b |
| 2(c) | **Widerrufsrecht** — only where Art. 6(1)(a) / 9(2)(a) is used | So only next to the optional consent boxes |
| 2(d) | **Beschwerderecht bei einer Aufsichtsbehörde** | Name the competent Landesbehörde with address |
| 2(e) | Whether provision is **gesetzlich oder vertraglich vorgeschrieben**, whether the person is obliged, and **the consequences of not providing** | ⚠️ Frequently forgotten. For us: no IBAN → no valid mandate → no bar access at all. That consequence must be stated |
| 2(f) | Automated decision-making / Profiling per Art. 22 | State that none takes place. ⚠️ Only truthful if we never build the consumption-profile views the companion file warns against |

**Non-compliance is bußgeldbewehrt under Art. 83(5)(b)** — LfDI BW says so twice.

Add **Art. 12 Abs. 1**: *„in präziser, transparenter, verständlicher und leicht zugänglicher Form sowie in klarer und einfacher Sprache"*. WP260 rev.01 Rn. 12:

> „Die Informationen sollten konkret und belastbar sein; abstrakte oder mehrdeutige Begriffe bzw. Interpretationsspielraum sind zu vermeiden. **Insbesondere die Zwecke der und die Rechtsgrundlage für die Verarbeitung der personenbezogenen Daten sollten klar dargelegt werden.**"

WP260's annex on Art. 13(1)(c) is explicit that naming the purpose is not enough:

> „Neben der Festlegung der Zwecke der Verarbeitung … **muss die entsprechende, nach Artikel 6 herangezogene Rechtsgrundlage angegeben werden.**"

### 1.2 ⚠️ How to express a 10-year tax retention under Art. 13(2)(a)

**Answer: state the concrete period *and* its statutory trigger, per data category — not a blanket sentence.**

The authority is WP260 rev.01, annex, row *Speicherfrist*:

> „Die Speicherfrist (oder die Kriterien, um diese festzulegen) kann von Faktoren wie den rechtlichen Anforderungen oder Industrierichtlinien vorgegeben sein, sollte aber so formuliert werden, dass **die betroffene Person die Möglichkeit hat, ausgehend von ihrer jeweiligen Situation einzuschätzen, welche Speicherfrist für bestimmte Daten / Zwecke gilt**. **Eine allgemeine Aussage des Verantwortlichen, die personenbezogenen Daten würden solange wie nötig für die legitimen Zwecke der Verarbeitung gespeichert, reicht hier nicht aus.** Gegebenenfalls sollten verschiedene Speicherfristen – und bei Bedarf auch Archivfristen – für unterschiedliche Kategorien [von Daten angegeben werden]."

So three rules:

1. ❌ **Never** write *„solange wie es für die Zwecke erforderlich ist"* — WP260 names that exact formulation as insufficient.
2. ✅ **Split by data category.** Different categories genuinely have different clocks for us: contact data (delete on/shortly after exit), IBAN + mandate (as long as the mandate lives, then as an accounting Beleg), the per-drink Aufzeichnungen (10 years).
3. ✅ **Name the statute and the trigger event.** § 147 Abs. 3 S. 1 AO runs from *Schluss des Kalenderjahres* in which the last entry was made (§ 147 Abs. 4 AO) — so "10 Jahre" alone is under-specified; write "**10 Jahre, gerechnet ab dem Schluss des Kalenderjahres**, in dem die letzte Eintragung erfolgt ist (§ 147 Abs. 1 Nr. 1, Abs. 3, Abs. 4 AO)".

⚠️ **And say the honest thing about Art. 17.** Members will read "10 years" and ask to be deleted. The notice should pre-empt this: contact data is deleted; the accounting core is not, because Art. 17(3)(b) DSGVO carves out processing required by a legal obligation. Do not promise deletion you cannot deliver — that is the same *Täuschung* problem as consent (§2.4).

**Working precedents for the wording** (see §5): LSB MV writes *„Name, Vorname, Geschlecht und Geburtsdatum werden grundsätzlich 10 Jahre nach Beendigung der Vereinsmitgliedschaft gelöscht (gesetzliche Aufbewahrungsfristen zu steuerlichen Zwecken)"*; BÜNDNIS 90/DIE GRÜNEN write *„für die Dauer der gesetzlichen Aufbewahrungsfrist von zehn Jahren gemäß § 24 PartG"*. Both name a period **and** a statute. That is the pattern to copy — with the AO in place of the PartG.

⚠️ **BSSB's wording is the pattern NOT to copy**: *„Mit Beendigung der Mitgliedschaft werden die Datenkategorien gemäß den gesetzlichen Aufbewahrungsfristen vorgehalten und dann gelöscht."* No period, no statute — that fails the WP260 test.

### 1.3 ⚠️ The bank as recipient — do not under-state this

Our pain.008 carries **debtor name, IBAN, Mandatsreferenz, Gläubiger-ID and amount** to the club's bank, which forwards to the member's bank. Art. 13(1)(e) offers a choice between *Empfänger* and *Kategorien von Empfängern*, but WP260 pushes hard toward naming:

> „Anzugeben sind die tatsächlichen (benannten) Empfänger der personenbezogenen Daten oder die Kategorien von Empfängern. … **In der Praxis werden dies gemeinhin die benannten Empfänger sein, damit die betroffenen Personen genau wissen, wer im Besitz ihrer personenbezogenen Daten ist.**"

**Recommendation: name the club's bank** (as LSB MV does — *„Beitragseinzug: Sparkasse …"*). It costs nothing and it is the safer reading.

Our full recipient list is short and should be stated in full: the club's Hausbank (payment execution) · the Vorstand/Kassenwart and whoever operates the bar system (internal, not "recipients" in the Art. 4 Nr. 9 sense but worth stating) · the **Steuerberater** if used and the **Finanzamt/Betriebsprüfung** · the hosting provider as **Auftragsverarbeiter** (Art. 28 — needs an AVV, and it is *not* a "recipient" disclosure in the sense that discharges Art. 28). ⚠️ Since our bar is a *wirtschaftlicher Geschäftsbetrieb*, the tax-authority recipient line is real, not theoretical.

### 1.4 Is there a standard Vereins-form worth following?

**Yes — the LSB / DOSB "Aufnahmeantrag + Merkblatt Datenschutz" pattern, and it is worth following closely.** See §5. There is no single official DOSB master document that I could verify; what exists is a family of near-identical Landessportbund templates (LSB MV, LSB Thüringen, BSSB, plus Deutsches Ehrenamt) that clearly descend from a common 2018 source. The **LSB MV Musterbeispiel** is the fullest and is the best structural model.

**NO AUTHORITY FOUND:** a DSK Kurzpapier or Landesbehörde handout specifically on *Vereins-Aufnahmeanträge*. DSK Kurzpapier Nr. 10 covers Informationspflichten generally but adds nothing Verein-specific. The LfDI BW Orientierungshilfe (Nr. 1.3.2 and 2.4) is the closest DPA-authored checklist and is quoted below.

**LfDI BW's own checklist** — note it is *stricter* than Art. 13 in one respect, requiring the information in **every** collection form:

> „Daraus folgt, dass der Verein **in jedem Formular, das er zur Erhebung personenbezogener Daten nutzt**, auf Folgendes hinweisen muss: Name und Kontaktdaten des Verantwortlichen sowie ggf. seines Vertreters · Kontaktdaten des Datenschutzbeauftragten · **Zwecke der Verarbeitung (bitte im Einzelnen aufzählen)** · Rechtsgrundlage der Verarbeitung · berechtigte Interessen i.S.d. Art. 6 Abs. 1 lit. f) · Empfänger oder Kategorien von Empfängern · Absicht über Drittlandtransfer … · Speicherdauer · Belehrung über Betroffenenrechte … · Hinweis auf jederzeitiges Widerrufsrecht der Einwilligung · Hinweis auf Beschwerderecht bei einer Aufsichtsbehörde"

and, specific to the design of the sheet (Nr. 2.4):

> „Vereinsmitglieder sind deswegen bei der Datenerhebung darauf aufmerksam zu machen, **welche Angaben für die Mitgliederverwaltung und welche für die Verfolgung des Vereinszwecks bestimmt sind.** … Kann dem Vereinsmitglied ein bestimmter Vorteil … nur gewährt werden, wenn es dazu bestimmte Angaben macht, **muss es darauf aufmerksam gemacht werden, welche Nachteile die Verweigerung dieser Informationen mit sich bringt.**"

That last sentence is Art. 13(2)(e) applied to our exact facts: **no IBAN → no bar.** It must be on the form.

**Floor vs. good practice:**

| | |
|---|---|
| **Legal floor** | The Art. 13(1)+(2) elements above, at collection time, in clear language. Nothing requires a signature on the notice, a separate sheet, or a specific layout. |
| **Good practice (not law)** | A separate *Merkblatt Datenschutz* sheet; a "gelesen und zur Kenntnis genommen" checkbox on the application; a Datenschutzordnung adopted alongside the Satzung (LfDI BW Nr. 1.3.3 calls this a *Pflicht* to record the Grundzüge in writing, though Art. 13 itself does not). |

---

## 3. Is a SEPA-Lastschriftmandat a GDPR consent?

### 3.1 Direct answer: NO. The hypothesis survives.

A SEPA mandate is a **payment authorisation under civil and payment-services law**, not an Art. 6(1)(a) Einwilligung. Three independent reasons:

**(a) The statute gives it a different name and a different function.** § 675j Abs. 1 BGB:

> „Ein Zahlungsvorgang ist gegenüber dem Zahler nur wirksam, wenn er diesem **zugestimmt** hat (**Autorisierung**)."

The mandate's addressee set is also different: it is a **double declaration** — to the creditor (permission to collect) *and* to the payer's own bank (instruction to honour). The Bundesbank puts it as: *„Ein Mandat umfasst sowohl die Zustimmung des Zahlers zum Einzug der Zahlung per SEPA-Lastschrift an den Zahlungsempfänger als auch den Auftrag an den eigenen Zahlungsdienstleister zur Einlösung der Zahlung."* A GDPR Einwilligung is a unilateral declaration to one controller about *processing*. These are not the same instrument.

**(b) The data processing it entails is *necessary to perform the contract*, so lit. b displaces lit. a.** Once the member owes a Beitrag and a bar tab and has chosen SEPA as the payment method, transmitting name + IBAN in the pain.008 is not optional extra processing — it is the payment. That is textbook Art. 6(1)(b). Under Erwägungsgrund 43 and the LfDI BW passage at §2.4, dressing necessary processing as consent is affirmatively wrong.

**(c) ⚠️ The revocation mismatch is real and is the decisive practical argument.** Two different revocations exist and they must not be merged:

| | What it is | Effect | Governed by |
|---|---|---|---|
| Widerruf des **Mandats** | Revocation of the payment authorisation | Future collections become unauthorised; the club's *claim* survives, only the collection channel dies. Member must pay by transfer | § 675j Abs. 2 BGB, EPC Rulebook |
| Widerruf der **Einwilligung** | Art. 7(3) DSGVO | Would purport to make *processing* of the IBAN unlawful going forward | Art. 7(3) DSGVO |
| Erstattungsanspruch | The 8-week no-questions refund | Money back; nothing to do with data | § 675x BGB |

If the club had built the mandate as an Art. 6(1)(a) consent, a member's Art. 7(3) withdrawal would strip the *legal basis for storing the IBAN* — while § 147 AO still requires the mandate and the collection records to be kept, and while the club still needs the IBAN to reconcile a Rücklastschrift. The club would then have to override the withdrawal, which is exactly LfDI BW's *Täuschung* scenario. **And under our SEPA-only design that withdrawal locks the member out of the bar** — a consequence a member is entitled to be told about, and one that also makes the "consent" doubtfully *freiwillig* under Art. 7(4).

**Confidence: High** on the analysis. **NO AUTHORITY FOUND**: no German DPA statement or court decision saying in terms "a SEPA mandate is not a GDPR consent". The conclusion rests on § 675j BGB + Art. 6(1)(b) + LfDI BW's general rule, not on a case on point.

### 3.2 ⚠️ But practice widely gets this wrong — and that is worth knowing

This is the finding I did not expect, and it cuts against copying templates uncritically:

| Source | How it classifies the mandate |
|---|---|
| **Stadt Starnberg**, Art. 13 notice for SEPA-Lastschriftmandat (Mai 2022) | *„Die Rechtsgrundlage … ist: **Art. 6 Abs. 1 lit. a), Art. 7 DSGVO**"*, storage period *„bei Widerruf der Einwilligung"* |
| **Landessportbund MV**, Musterbeispiel Aufnahmeantrag | Merkblatt Nr. 4b lists under *Einwilligung (Art. 6 Abs. 1 a)*: *„… sowie die **Ermächtigung zur Beitragserhebung als SEPA-Lastschrift**"* |
| **BÜNDNIS 90/DIE GRÜNEN**, Datenschutzhinweise beim SEPA-Mandat | Art. 6(1)(a) (+ Art. 9(2)(a)) for the mandate itself; Art. 6(1)(c) only for the § 25 PartG reporting |
| **Die Schwalbe** (Verein) mandate form | *„Ich habe die … Informationen zum SEPA-Lastschriftmandat gemäß Art. 13 DSGVO zur Kenntnis genommen **und willige in die Verarbeitung meiner personenbezogenen Daten wie dort beschrieben ein**"* — the two instruments fused into one sentence |
| **Bayerischer Sportschützenbund**, Muster Aufnahmeantrag (Stand 12/2025) | ✅ **Does it right.** Art. 6(1)(b) as the general basis; the mandate is a separate signed block; the bank appears only as an *Empfänger* under the purpose *Beitragseinzug*. Never called consent |

The Starnberg sheet is self-refuting on its face: it names Art. 6(1)(a) *and* then says *„**Sie sind dazu verpflichtet, Ihre Daten anzugeben.** Diese Verpflichtung ergibt sich aus oben genannten Rechtsgrundlagen."* A consent that you are obliged to give is not a consent (Art. 4 Nr. 11, Art. 7(4)).

⚠️ **Conclusion: "every club does it this way" is not a permission.** The majority pattern here is an error propagated by template reuse. Follow the **BSSB** model, not the LSB MV / Starnberg model, on this specific point.

### 3.3 How real forms handle the two instruments physically — combined or separate?

**Consistently: separate blocks, separate signatures, one envelope.** Across every template examined:

- **BSSB**: page 1 = Aufnahmeantrag (signature: *Unterschrift Mitglied*, countersigned *Unterschrift Verein*); page 2 = SEPA-Lastschriftmandat with Gläubiger-ID and Mandatsreferenz and its own *Datum, Ort und Unterschrift*; pages 3–4 = Informationspflichten, unsigned.
- **LSB MV**: five numbered blocks, **four separate signature lines** — (1) Aufnahme + Satzung + "Merkblatt gelesen"; (2) freiwillige Angaben (Telefon) with its own signature; (3) Bildnis-Einwilligung with its own signature; (4) **SEPA mandate with its own signature (`Unterschrift Kontoinhaber`)**; (5) parental liability. Then the *Merkblatt Datenschutz* as a separate 2-page annex, unsigned.
- **Deutsches Ehrenamt**: three physically separate documents — *Muster Aufnahmeantrag*, *Muster SEPA-Lastschriftmandat*, *Datenschutzhinweise*, with the instruction *„Jedem Aufnahmeantrag sollte ein eigenes Dokument … angehängt werden."*

**Why the separate signature on the mandate is not merely cosmetic:** the account holder may be a different person from the member (LSB MV has an explicit tick-box for *„Kind vom Konto der Eltern"*, BSSB a separate *Kontoinhaber* block). The mandate must be signed by whoever owns the account. ⚠️ **Relevant to our design**: if we ever allow a third-party payer, our data model needs a Kontoinhaber distinct from the member — and the Art. 13 notice then has a *second* data subject.

---

## 4. Art. 30 Verzeichnis and § 38 BDSG Datenschutzbeauftragter

### 4.1 Art. 30 Verzeichnis — ⚠️ the club MUST keep one. The <250 exemption does not help.

Art. 30 Abs. 5:

> „Die in den Absätzen 1 und 2 genannten Pflichten gelten nicht für Unternehmen oder Einrichtungen, die **weniger als 250 Mitarbeiter** beschäftigen, **es sei denn** die von ihnen vorgenommene Verarbeitung **birgt ein Risiko** für die Rechte und Freiheiten der betroffenen Personen, **die Verarbeitung erfolgt nicht nur gelegentlich** **oder** es erfolgt eine Verarbeitung **besonderer Datenkategorien** gemäß Artikel 9 Absatz 1 bzw. … Artikel 10."

**All three exceptions must be checked, and any one is enough.** WP29 Position Paper of 19.04.2018 (endorsed by the EDPB at its first plenary):

> „the wording of Article 30(5) is clear in providing that the three types of processing to which the derogation does not apply are **alternative ('or') and the occurrence of any one of them alone triggers the obligation** to maintain the record"

and on what "occasional" means:

> „a processing activity can only be considered as 'occasional' if it is **not carried out regularly, and occurs outside the regular course of business or activity** of the controller or processor"

**Applied to us:**

| Exception | Bites? |
|---|---|
| **Not occasional** | ✅ **YES, decisively.** Member administration and daily bar transactions are the regular course of the club's activity. WP29's own example is a small organisation regularly processing employee data — *a fortiori* for member and transaction data |
| **Risk to rights and freedoms** | ✅ Probably. Note WP29: *"a risk (not just a high risk)"*. Financial data (IBAN), a persistent RFID identifier, and a decade of behavioural line items clear that bar comfortably |
| **Art. 9 special categories** | ❓ Genuinely open — this is the coin-flip in the companion file. Does not matter: the first two already trigger the duty |

LfDI BW reaches the same conclusion categorically for all Vereine:

> „**Da jedoch in jedem Verein die Verarbeitung personenbezogener Daten nicht nur gelegentlich erfolgt, ist auch bei Vereinen mit weniger als 250 Mitarbeitern ein Verzeichnis von Verarbeitungstätigkeiten zu führen.**"

⚠️ **Two textual notes.** (i) The LfDI BW passage misprints *„weniger als 250 **Mitglieder** beschäftigt"* — the statute counts **Mitarbeiter**, not members. Their conclusion is right; their sentence is not. Do not quote it in that form. (ii) WP29 adds a limit worth using: *"such organisations need only maintain records of processing activities for the types of processing mentioned by Article 30(5)"* — genuinely one-off processing may stay out of the Verzeichnis.

**Deliverable, not optional: a Verzeichnis with the Art. 30(1)(a)–(g) entries.** For us that means at minimum separate entries for *Mitgliederverwaltung*, *Bar-/Kassenbetrieb (RFID, Einzelaufzeichnungen)*, *SEPA-Beitrags- und Deckeleinzug*, and *steuerliche Aufbewahrung*. Note Abs. 1(f) *„wenn möglich, die vorgesehenen Fristen für die Löschung der verschiedenen Datenkategorien"* — the same category-by-category retention table as Art. 13(2)(a). **Build them once, use them twice.**

**Confidence: Very High.**

### 4.2 § 38 BDSG DSB — almost certainly NOT required, but check the right thing

**§ 38 Abs. 1 S. 1 BDSG (in force, verified):**

> „Ergänzend zu Artikel 37 Absatz 1 Buchstabe b und c der Verordnung (EU) 2016/679 benennen der Verantwortliche und der Auftragsverarbeiter eine Datenschutzbeauftragte oder einen Datenschutzbeauftragten, soweit sie **in der Regel mindestens 20 Personen ständig mit der automatisierten Verarbeitung personenbezogener Daten beschäftigen**."

⚠️ **Threshold correction.** The LfDI BW Orientierungshilfe text as extracted reads *„mindestens 2 Personen"*. That is wrong against the statute — and the extraction visibly drops trailing characters elsewhere in the same document (*„Bennenung"*, *„rhebt ein Verein"*, *„Medie"*), so it is near-certainly a mangled **20**. The threshold was 10 until the 2. DSAnpUG-EU raised it to **20** with effect from 26.11.2019. **Use 20.**

**Who counts toward the 20 in a Verein?**

- **Employment status is irrelevant; the task is what counts.** *„Dabei ist nicht entscheidend, ob es sich um haupt- oder **ehrenamtliche** Tätigkeiten im Verein handelt. Entscheidend ist ausschließlich der tatsächliche Aufgabenbereich der Mitarbeiter."* So **yes, ehrenamtliche Vorstandsmitglieder count** — if they actually work with the automated processing. Minijobber, Teilzeitkräfte and Praktikanten each count as one head, not FTE.
- **The limiter is *ständig*, not the headcount.** It counts people who work with the automated processing **regularly as part of their role**, not anyone who once touched the member list. A Übungsleiter who coaches and never opens the Mitgliederverwaltung does **not** count; a Kassenwart who runs the SEPA runs monthly does.
- ⚠️ **Careful with our own system.** A "few dozen members" club is nowhere near 20 people *ständig* administering data — Vorstand + Kassenwart + a bar admin is realistically 3–6. **But**: if we ever grant admin-panel logins broadly, the headcount is driven by *who has operational access to the system we are building*. That is a design decision with a legal consequence. Keep admin roles narrow.
- **Confidence: High** that no DSB is required. **NO AUTHORITY FOUND**: no DPA statement resolving *ständig* into a concrete hours/frequency test.

**Art. 37(1)(b)/(c) DSGVO must be checked separately** — they have no headcount at all:

- (b) *Kerntätigkeit* = umfangreiche regelmäßige und systematische **Überwachung**. LfDI BW's example is *Videoüberwachung im Stadion*. ⚠️ Our RFID-token transaction log is regular and systematic, but it is not the club's **Kerntätigkeit** — the club's core activity is rowing. Not triggered. (Confidence: Medium-High; this is the one line I would want a lawyer on if we ever added cameras to the clubroom.)
- (c) *Kerntätigkeit* = processing Art. 9 data. Even if the drink history were held to be Art. 9 (companion file, open question), it would still not be the club's *Kerntätigkeit*. Not triggered.

**Two practical notes:**

1. ⚠️ **If a DSB is ever appointed, it cannot be the Vorstand.** LfDI BW: *„Zur Vermeidung einer Interessenkollision dürfen die Aufgaben des Datenschutzbeauftragten nicht vom Vereinsvorstand oder dem für die Datenverarbeitung des Vereins Verantwortlichen wahrgenommen werden, da diese Personen sich nicht selbst wirksam überwachen können."* Voluntary appointment is possible — but a voluntary DSB carries the **full Art. 38/39 regime including dismissal protection**, so do not do it casually.
2. **No DSB ⇒ leave Art. 13(1)(b) out of the notice.** The provision is *„gegebenenfalls"*. Naming a "Datenschutz-Ansprechpartner" is fine and useful; do not call them *Datenschutzbeauftragter*.

**⚠️ Watch item:** the Bundesregierung announced on 04.12.2025 (*Föderale Modernisierungsagenda*) an intention to repeal § 38 Abs. 1 BDSG by 31.12.2026. As of mid-2026 there is no Referentenentwurf and **§ 38 remains in force unchanged**. Irrelevant to us in outcome (we are under the threshold either way), but relevant if this document is reused later.

---

## 5. Common practice: what German sports clubs actually put on an Aufnahmeantrag

### 5.1 The template family

Verified, downloaded and read in full:

| Template | Date | Notes |
|---|---|---|
| **LSB Mecklenburg-Vorpommern**, *Musterbeispiel Aufnahmeantrag mit Merkblatt Datenschutz* | Rev. 20180706 | Fullest example; 3-page Antrag + 2-page Merkblatt |
| **Bayerischer Sportschützenbund**, *Muster Aufnahmeantrag* | Stand 12/2025 | Most current; best on the mandate question |
| **LSB Thüringen**, *Muster Aufnahmeantrag* | — | Minimal core-data version with annotations for paper vs. online |
| **Deutsches Ehrenamt**, *Muster Aufnahmeantrag* | 12/2024 | Three separate documents; checklist-style Datenschutzhinweise |
| **BÜNDNIS 90/DIE GRÜNEN**, *Datenschutzhinweise beim SEPA-Mandat* | — | Not a sports club, but the closest analogue for a 10-year statutory retention on a membership + SEPA relationship |

### 5.2 One sheet or several? — several, in one packet

The invariant structure:

```
[Sheet 1]  AUFNAHMEANTRAG
           - required data (marked as required, with the purpose stated)
           - "Mit meiner Unterschrift erkenne ich die Satzung und Ordnungen ... an"
           - "Die umseitig abgedruckten Informationspflichten gem. Art. 13/14 DSGVO
              habe ich gelesen und zur Kenntnis genommen"          <- checkbox
           - SIGNATURE (+ signature of gesetzliche Vertreter if minor)

[Sheet 1b] OPTIONAL / FREIWILLIGE ANGABEN  -- each with its OWN signature
           - phone number, sharing with other members
           - photo/video publication consent
           - each with an explicit Widerruf notice

[Sheet 2]  SEPA-LASTSCHRIFTMANDAT
           - Gläubiger-ID, Mandatsreferenz (filled by the club)
           - the fixed EPC mandate wording + the 8-week refund notice
           - separate Kontoinhaber block (may differ from the member)
           - SEPARATE SIGNATURE (Ort, Datum, Unterschrift Kontoinhaber)

[Sheet 3]  MERKBLATT DATENSCHUTZ / DATENSCHUTZHINWEISE
           - the Art. 13 elements, numbered 1..8
           - NOT SIGNED
```

### 5.3 ⚠️ Where the Datenschutzhinweis physically sits — and why it is *not* signed

**Answer: on its own sheet, printed on the reverse or attached, referenced by a "zur Kenntnis genommen" checkbox on the application — never signed as a consent.**

This is the single most transferable design finding, and it is legally motivated. Art. 13 imposes an **information duty on the controller**, not a declaration by the member. What the club needs to be able to prove is *that it informed*, which the acknowledgment checkbox does. If the member instead **signed** the notice, the club would be manufacturing an apparent Einwilligung over processing that rests on lit. b and lit. c — the §2.4 *Täuschung* problem, plus an implied revocability that does not exist.

The templates encode exactly this distinction:
- LSB Thüringen even splits the wording by channel: *„Die **umseitig abgedruckten** Informationspflichten … habe ich gelesen und zur Kenntnis genommen"* for paper, versus a tick-box + link version for online, with the note *„setzen Sie ein Häkchen, dass angeklickt werden muss, bevor der Antrag abgeschickt wird"*.
- Deutsches Ehrenamt: *„Die Betroffenen müssen im Aufnahmeantrag bestätigen, dass sie diese Hinweise **gelesen haben**."* — read, not consented.

⚠️ **Directly relevant to our product**: our onboarding will likely be a screen, not paper. The Thüringen guidance is the design spec — the acknowledgment must be an **unchecked box the member actively ticks before submit**, with the full notice reachable next to it, and the club must **log timestamp + notice version** (the Art. 5(2) accountability record; note this is *not* Art. 7(1), which applies only to consent).

### 5.4 Other transferable details

- **Mark required vs. optional explicitly.** BSSB/Thüringen: *„Die folgenden Angaben sind für die Durchführung des Mitgliedschaftsverhältnisses erforderlich."* This discharges Art. 13(2)(e) and implements LfDI BW Nr. 2.4.
- **The Satzung acknowledgment does double duty.** Because BGH II ZR 132/24 Rn. 23 says the *Vertrag*'s content is *„durch die Vereinssatzung konkretisiert"*, the Satzung/Beitragsordnung is what defines the scope of lit. b. ⚠️ **Consequence for us: the bar and its data handling should be anchored in the Satzung or a Barordnung/Datenschutzordnung.** Without that anchor, the argument that per-drink records are *„zur Erfüllung des Vertrags erforderlich"* is weaker. LfDI BW Nr. 1.3.3 recommends a *Datenschutzordnung* adoptable by Vorstand or Mitgliederversammlung without Satzung quality — that is the low-friction route.
- **Retention is stated per data category** in the good templates (LSB MV lists five different clocks). Copy the structure, not the numbers — LSB MV deletes Bankdaten within one month of exit, which ⚠️ **would be wrong for us**: the mandate and the collection records are accounting Belege caught by § 147 AO.
- **Minors**: every template has a gesetzlicher-Vertreter signature and LSB MV adds a personal liability undertaking for the child's dues. ⚠️ For a bar serving alcohol this is not a formality — JuSchG limits are a separate constraint on the product, and a minor's onboarding must not produce an alcohol-purchasing card.
- **Nobody puts a photo consent, a phone-sharing consent and the mandate under one signature.** Granularity of consent (Art. 7(2), EG 43) is respected in every template. Ours must be too.

---

## Sources

**Primary — verified verbatim**
- **BGH, Urteil vom 10.12.2025 – II ZR 132/24** — [full PDF](https://www.bundesgerichtshof.de/SharedDocs/Entscheidungen/DE/Zivilsenate/II_ZS/2024/II_ZR_132-24.pdf?__blob=publicationFile&v=1) (Rn. 22–26 quoted)
- [Art. 13 DSGVO](https://dsgvo-gesetz.de/art-13-dsgvo/) · [Art. 30 DSGVO](https://dsgvo-gesetz.de/art-30-dsgvo/) · [Art. 6, 7 DSGVO](https://dsgvo-gesetz.de/art-6-dsgvo/)
- [§ 38 BDSG](https://www.gesetze-im-internet.de/bdsg_2018/__38.html) · [§ 675j BGB](https://www.gesetze-im-internet.de/bgb/__675j.html)

**Official guidance**
- [LfDI Baden-Württemberg, *Orientierungshilfe: Datenschutz im Verein*](https://www.baden-wuerttemberg.datenschutz.de/orientierungshilfe-datenschutz-verein/) (Stand 24.06.2020; Nr. 1.3.1, 1.3.2, 1.3.4, 2.1, 2.4, 6, 7.1, 7.2)
- [WP29 Position Paper on the derogations from Art. 30(5) GDPR](https://www.naih.hu/files/WP-29-POSITION-PAPER--Article-30-_5_-GDPR.pdf), 19.04.2018, EDPB-endorsed — full text retrieved
- WP260 rev.01, *Leitlinien für Transparenz* (German), Rn. 12 and the Art. 13/14 annex — full text retrieved
- [DSK Kurzpapier Nr. 10, *Informationspflichten bei Dritt- und Direkterhebung*](https://www.datenschutzkonferenz-online.de/media/kp/dsk_kpnr_10.pdf)

**Practice templates — downloaded and read in full**
- [LSB Mecklenburg-Vorpommern, Aufnahmeantrag + Merkblatt Datenschutz](https://www.lsb-mv.de/export/sites/lsbmv/service/downloads/Datenschutz/06_Aufnahmeantrag-Merkblatt-Datenschutz.pdf)
- [Bayerischer Sportschützenbund, Muster Aufnahmeantrag (12/2025)](https://www.bssb.de/fileadmin/Service/Vereinsrecht/Musterformulare_oder_Antraege/2025_Muster_Aufnahmeantrag.pdf)
- [LSB Thüringen, Muster Aufnahmeantrag](https://www.thueringen-sport.de/fileadmin/user_upload/Muster_Aufnahmeantrag.pdf)
- [Deutsches Ehrenamt, Muster Aufnahmeantrag](https://deutsches-ehrenamt.de/app/uploads/2024/12/Muster-Aufnahmeantrag.pdf)
- [Stadt Starnberg, Information nach DSGVO – SEPA-Lastschriftmandat](https://www.starnberg.de/assets/downloads/buergerservice-verwaltung/Datenschutz/Information_nach_DSGVO_-_SEPA-Lastschriftmandat.pdf)
- [BÜNDNIS 90/DIE GRÜNEN, Datenschutzhinweise beim SEPA-Mandat](https://www.gruene.de/artikel/datenschutzhinweise-beim-sepa-mandat)

**Secondary**
- [delegedata.de on BGH II ZR 132/24 vs. the EDSA view](https://www.delegedata.de/2026/01/was-ist-ein-vertrag-nach-art-6-abs-1-b-dsgvo-bgh-mit-neuer-entscheidung-und-gegen-die-auffassung-des-edsa/) · [dr-datenschutz.de, *Braucht ein Verein einen Datenschutzbeauftragten?*](https://www.dr-datenschutz.de/braucht-ein-verein-einen-datenschutzbeauftragten/) · [Deutsche Bundesbank, FAQ zu SEPA](https://www.bundesbank.de/dynamic/action/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/sepa/613964/fragen-und-antworten-zu-sepa)

**Method note:** the WebSearch budget was exhausted early in this session; searching was done via direct DuckDuckGo HTML queries and all PDFs were converted locally with `pdftotext -layout` rather than relying on summarised fetches. No sub-agents were used.

**NO AUTHORITY FOUND (looked for, not found):** a DPA statement or judgment holding in terms that a SEPA mandate is not a GDPR consent · a Verein-specific DSK Kurzpapier or Landesbehörde handout on Aufnahmeanträge · a concrete test for *ständig beschäftigt* under § 38 BDSG · a single authoritative DOSB master Aufnahmeantrag (only the LSB family).

