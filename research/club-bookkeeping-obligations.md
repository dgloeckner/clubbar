# Research: does this club's bar actually carry bookkeeping obligations — and at what granularity?

Resolves [#174](https://github.com/dgloeckner/clubbar/issues/174), part of wayfinder map [#139](https://github.com/dgloeckner/clubbar/issues/139).
Tests the premise underneath [#159](https://github.com/dgloeckner/clubbar/issues/159) and [ADR-0028](../adr/0028-legal-constraints-on-money-handling.md).
Unblocks [#165](https://github.com/dgloeckner/clubbar/issues/165) (erasure window) and [#173](https://github.com/dgloeckner/clubbar/issues/173) (offboarding).

**Setting**: an eingetragener, gemeinnütziger Ruderverein. The bar sells drinks **to members only, at purchase price** (no margin, by design). It is **unstaffed self-service**, takes **no cash at all**, runs **post-paid tabs** (a Deckel / receivable) collected monthly by **SEPA-Lastschrift**. A few dozen members. It replaces an informal Kaffeekasse that had no bookkeeping whatsoever.

> ⚖️ **Not legal advice.** This is a documented reading of primary sources by an engineer, for the purpose of choosing a data model and a retention policy. Points marked ⚠️ are flagged because the research found **no authority** — not because it found authority it disliked.

**Sources.** Statutory text from `gesetze-im-internet.de` (current consolidated text, August 2026). GoBD quotes verified against the **consolidated 14.07.2025 version** (BMF-Schreiben v. 28.11.2019, as amended 11.03.2024 and 14.07.2025). AEAO zu § 146 quoted from the BMF-Schreiben v. 19.06.2018, GZ IV A 4 - S 0316/13/10005 :053. Other AEAO passages from the Finanzverwaltung NRW's official 2025 reproduction (*Vereine & Steuern*), because `ao.bundesfinanzministerium.de` is behind a bot wall — ⚠️ AEAO Nummern shift between annual editions, so verify the Nr. before quoting in a filing.

---

## 0. The short answers

| Question | Answer | Confidence |
|---|---|---|
| **1. Is the bar a wirtschaftlicher Geschäftsbetrieb, given no margin and members only?** | **Yes, and it is a *steuerpflichtiger* wGB, not a Zweckbetrieb.** The club's two intuitions — members-only and no margin — are the two arguments German tax law refuses *by name*. | **High** |
| **2. What do the thresholds switch off?** | **Tax liability, and essentially nothing else.** Below § 64 Abs. 3 AO: no KSt, no GewSt. Below § 141 AO: no doppelte Buchführung. Under § 19 UStG: supplies are steuerfrei. **No threshold touches §§ 145–147 AO.** One partial exception: § 65 UStDV *substitutes* a thinner recording duty for Kleinunternehmer. | **High** |
| **3. THE HINGE — per-drink records, or is the Beleg the monthly SEPA collection?** | **The dichotomy is false; both are true at different layers.** The **Buchung** is the monthly collection plus the bank statement — per-drink *Verbuchung* is expressly **not** required (AEAO zu § 146 Nr. 2.1.3). But the per-drink log is a **Grund(buch)aufzeichnung in a Vorsystem**, and GoBD Rz. 99 makes retaining it the **express condition** of booking aggregates. So per-drink records **must be retained**. | **Medium-high.** Rz. 99 is close to dispositive; §3.6 and §3.9 record the untested counter-argument |
| **4. Retention period, and which fields survive erasure?** | **10 years** for the transaction journal (Nr. 1 *Aufzeichnungen*), **8 years** for Belege (Nr. 4), **6→7 years** for the rest via the Ablaufhemmung — all from 31.12. of the originating year. Erasure of the accounting core is **restriction under Art. 17(3)(b)**, not deletion. But **contact data must still be deleted** — GoBD Rz. 113 limits retention to the scope of the recording duty. | **High** on the periods; **medium** on the 8-vs-10 classification, which has no authority post-BEG IV |

**Verdict on the premise under test.** The sentence that was assumed rather than researched — *"A club bar selling drinks is a wirtschaftlicher Geschäftsbetrieb, so the Verein carries bookkeeping obligations for it"* — is **correct in both halves**, and the club's two counter-intuitions (members-only, no margin) are the two arguments German tax law refuses by name.

But it was correct by luck rather than by reasoning, and three things it got wrong or missed matter:

1. **The obligation does not come from where the ADR implies.** It comes from **§ 63 Abs. 3 AO** — threshold-free, unconditional — not from any wGB-specific bookkeeping rule. The GoBD and §§ 145–147 AO are *accessory*: they say how, never whether.
2. **The retention period in ADR-0028 §5 is too short** for the transaction journal — 10 years, not 8 — and the Ablaufhemmung is modelled nowhere.
3. **The cashless design does not lighten the record-keeping load; it removes every escape from it.** ADR-0028 §6's framing implies the opposite.

See §6 for the full list of ADR corrections. The ⚠️⚠️ "premise under review" banner can be lifted.

---

## 1. Is the bar a wirtschaftlicher Geschäftsbetrieb? — Yes

### 1.1 § 14 AO, element by element

> **Satz 1:** „Ein wirtschaftlicher Geschäftsbetrieb ist eine selbständige nachhaltige Tätigkeit, durch die Einnahmen oder andere wirtschaftliche Vorteile erzielt werden und die über den Rahmen einer Vermögensverwaltung hinausgeht."
> **Satz 2:** „Die Absicht, Gewinn zu erzielen, ist nicht erforderlich."
> **Satz 3:** „Eine Vermögensverwaltung liegt in der Regel vor, wenn Vermögen genutzt, zum Beispiel Kapitalvermögen verzinslich angelegt oder unbewegliches Vermögen vermietet oder verpachtet wird."

| Element | Satisfied | Why |
|---|---|---|
| selbständige Tätigkeit | Yes | Distinguishable from the ideelle Ruder-Tätigkeit |
| nachhaltig | Yes | Permanently available, monthly billing cycle |
| **Einnahmen** werden erzielt | Yes | § 14 measures *gross inflows*, not surplus. Zero margin ≠ zero Einnahmen |
| über Vermögensverwaltung hinaus | Yes | Selling goods is a Leistungstätigkeit, not passive Nutzung (§ 14 S. 3) |

Note the structure of the "Einnahmen" element: it is a **gross** concept, confirmed for exactly this activity by the AEAO:

> „Zu den Einnahmen i. S. d. § 64 Abs. 3 AO gehören leistungsbezogene Einnahmen **einschließlich Umsatzsteuer** aus dem laufenden Geschäft, **wie Einnahmen aus dem Verkauf von Speisen und Getränken**."
> — AEAO zu § 64 Nr. 19 (NRW 2025 numbering; older editions: Nr. 16)

A club that takes in €12,000 and spends €12,000 on stock has **€12,000 of Einnahmen**, not €0.

### 1.2 The two arguments the club is relying on, and why each fails

**"Members only."** This is refused by name, in the sport-club context, in administrative guidance:

> „Die Unterhaltung von Club-Häusern, Kantinen, Vereinsheimen oder Vereinsgaststätten ist keine ‚sportliche Veranstaltung', **auch wenn diese Einrichtungen ihr Angebot nur an Mitglieder richten**."
> — AEAO zu § 67a Nr. 10

> „Der Verkauf von Speisen und Getränken – auch an Wettkampfteilnehmer, Schiedsrichter, Kampfrichter, Sanitäter usw. – und die Werbung gehören nicht zu den sportlichen Veranstaltungen. **Diese Tätigkeiten sind gesonderte steuerpflichtige wirtschaftliche Geschäftsbetriebe.**"
> — AEAO zu § 67a Nr. 6

Structurally: § 14 AO contains **no** „Beteiligung am allgemeinen wirtschaftlichen Verkehr" element. That is an element of § 15 Abs. 2 EStG (Gewerbebetrieb), deliberately not carried into § 14.

**"No margin."** Refused by § 14 Satz 2 in terms. The ticket's steer on this was right, though it was right for a reason the ticket did not give: it is not merely that profit intent is unnecessary, it is that **Einnahmen is the measured quantity and it is measured gross**.

**"We don't compete with any pub."** The Wettbewerbsgedanke is real but lives at § 65 Nr. 3, **not** at § 14:

> „Ein wirtschaftlicher Geschäftsbetrieb i.S. von § 14 AO erfordert nicht das Bestehen eines konkreten oder potentiellen Wettbewerbs."
> — BFH, Urt. v. 24.06.2015, **I R 13/13**, Leitsatz 1

This is the precise question the map owner asked to have settled, and it is settled: competition-protection is the *policy rationale* for taxing wGB, but the BFH refused to read it back into the statutory definition as an unwritten element.

### 1.3 Zweckbetrieb (§ 65 AO)? — No, and it is not close

The three conditions of § 65 AO are **cumulative**. The bar fails Nr. 1 and Nr. 2 decisively:

> **Nr. 1** — „der wirtschaftliche Geschäftsbetrieb in seiner Gesamtrichtung dazu dient, die steuerbegünstigten satzungsmäßigen Zwecke der Körperschaft zu verwirklichen"

Serving a beer does not *unmittelbar* promote rowing; it promotes Geselligkeit, which is not a begünstigter Zweck under § 52 Abs. 2 AO at all. AEAO zu § 65 Nr. 2 requires the Zweckbetrieb to serve the purposes „**tatsächlich und unmittelbar**", not „nur mittelbar … durch Abführung seiner Erträge".

> **Nr. 2** — „die Zwecke nur durch einen solchen Geschäftsbetrieb erreicht werden können"

AEAO zu § 65 Nr. 3: the club must „den Zweckbetrieb zur Verwirklichung ihrer satzungsmäßigen Zwecke **unbedingt und unmittelbar benötigen**". Rowing can plainly be pursued without a bar.

> **Nr. 3** — Wettbewerb

Even here the club loses, because the administration applies a **potential**-competition standard: a Zweckbetrieb is not given „wenn ein Wettbewerb mit steuerpflichtigen Unternehmen **lediglich möglich wäre, ohne dass es auf die tatsächliche Wettbewerbssituation vor Ort ankommt**" (AEAO zu § 65 Nr. 4, expressly *entgegen* BFH V R 30/99).

§§ 66–68 AO do not help either. § 66 requires Wohlfahrtspflege and caps alcohol at 5% of turnover (AEAO zu § 66 Nr. 5); § 67a expressly excludes drinks sales; § 68 has no bar category.

### 1.4 Is it a Leistungsaustausch, or a Kostenumlage? — Leistungsaustausch

This retests [#140](https://github.com/dgloeckner/clubbar/issues/140)'s assertion rather than inheriting it. The operative test:

> „Soweit eine Vereinigung zur Erfüllung ihrer den Gesamtbelangen sämtlicher Mitglieder dienenden satzungsgemäßen Gemeinschaftszwecke tätig wird und dafür echte Mitgliederbeiträge erhebt … fehlt es an einem Leistungsaustausch mit dem einzelnen Mitglied."
> — Abschn. 1.4 Abs. 1 S. 1 UStAE

Abschn. 1.4 Abs. 1 S. 2 UStAE supplies the converse: where the Verein renders services serving the **Sonderbelange der einzelnen Mitglieder** and charges **entsprechend der tatsächlichen oder vermuteten Inanspruchnahme**, there *is* a Leistungsaustausch. ⚠️ The exact wording of Satz 2 could not be verified from a primary source; its content is corroborated across secondary sources.

A member scans a card, takes *one specific bottle*, and is charged *for that bottle*. That is measurement of consideration by actual individual Inanspruchnahme in its purest form. **The itemised digital tab is the strongest single fact against the Umlage reading** — which is worth noting, because it means the system's own design reinforces the classification.

The Kostenumlage reading, given a genuine run, fails on ownership and risk: the Verein buys the stock in its own name, bears breakage/theft/expiry, and holds a *receivable against each member*. An Umlage is a contribution to a common pot fixed independently of what any one contributor takes out; this is fixed exactly by what each member took out.

⚠️ **No authority found** — no BFH decision, no AEAO Nr., no UStAE Abschnitt — squarely addressing a Verein supplying goods to members **at exact acquisition cost** and characterising it as Lieferung vs. Selbstkostenerstattung. The Umlage reading is defeated by the general rules, not by a case on point.

**#140's Leistungsaustausch assertion survives the retest.**

### 1.5 ⚠️ An unrelated finding that matters more than the classification: § 55 AO

Selling **at purchase price** is itself the club's largest Gemeinnützigkeit risk — larger than the wGB classification, which below the Besteuerungsgrenze is largely harmless.

> „**Es ist grundsätzlich nicht zulässig, Mittel des ideellen Bereichs** (insbesondere Mitgliedsbeiträge, Spenden, Zuschüsse, Rücklagen) … **für einen steuerpflichtigen wirtschaftlichen Geschäftsbetrieb zu verwenden, z. B. zum Ausgleich eines Verlustes.**"
> — AEAO zu § 55 Nr. 4

Break-even is not a Verlust, so the rule is not engaged at exact break-even. But "turnover equals cost of goods" is break-even on *cost of goods only*. The wGB's result must also absorb electricity for the fridge, depreciation on the dispensing/RFID hardware, cleaning, insurance and payment fees. **Pricing at purchase price therefore engineers a structural annual loss**, covered from Mitgliedsbeiträge — which is exactly what AEAO zu § 55 Nr. 4 forbids.

The safe harbours mostly do not fit a *deliberate* pricing policy: Fehlkalkulation (Nr. 6) requires a miscalculation; Anlaufverluste (Nr. 8) last three years; the gemischte-Aufwendungen relief (Nr. 5) requires „marktübliche Preise" and expressly excludes a „Gaststättenbetrieb in einer Sporthalle". One route does fit: „**Umlagen und Zuschüsse, die dafür bestimmt sind**" may lawfully cover the loss (Nr. 6, Schlusssatz).

**This is out of scope for #174 but should be raised with the club's Steuerberater.** Adding a small margin sufficient to cover allocated overheads is *more* gemeinnützigkeitssicher, not less — the opposite of the club's instinct.

### 1.6 Which facts are load-bearing

**Do NOT change the answer:** members-only (AEAO zu § 67a Nr. 10); no margin (§ 14 S. 2); no actual competition (BFH I R 13/13); unstaffed; cashless; small scale. Scale affects whether tax is *payable*, never whether a wGB *exists*.

**WOULD change the answer:** leasing the bar to a Pächter (Pachtzins = Vermögensverwaltung, § 14 S. 3) — the true dividing line; or giving drinks away free with no per-member accounting, which abandons the Deckel and is therefore not the system being built.

⚠️ **No authority found** on unstaffed / self-service / Vertrauenskasse arrangements specifically. Every Vereinsgaststätte authority located presupposes a staffed operation without saying so. The doctrine's *stated* reasoning does not rest on that premise — it rests on § 14's elements, none of which mention staff — but the premise-stripping argument is untested.

---

## 2. What the thresholds switch off

**The framing that matters: tax LIABILITY disappearing is not record-keeping OBLIGATIONS disappearing.** Holding those apart is the whole of this section.

| Threshold | Statute | Club's position | Tax liability removed | Record-keeping removed |
|---|---|---|---|---|
| **€50,000** Besteuerungsgrenze | § 64 Abs. 3 AO | Far below | KSt **and** GewSt on the wGB. **Not USt.** | **None** |
| €5,000 Freibetrag | § 24 KStG | Unreachable while under § 64 Abs. 3 | Deducted from Einkommen | **None** |
| €5,000 Freibetrag | § 11 Abs. 1 S. 3 Nr. 2 GewStG | Same | Deducted from Gewerbeertrag | **None** |
| **€800,000 / €80,000** | § 141 Abs. 1 AO | Orders of magnitude below | — (not a liability rule) | Removes **doppelte Buchführung + Bilanz** only |
| **€25,000 / €100,000** | § 19 Abs. 1 UStG | Below | Umsätze **steuerfrei**; § 18 Abs. 1–4 Erklärungspflichten | **Partial — the only real relief.** Via § 65 UStDV |
| — | §§ 145–147 AO | Applies | — | **No threshold exists** |
| — | § 63 Abs. 3 AO | Applies | — | **No threshold. Unconditional.** |

### 2.1 ⚠️ Two figures in the ticket are out of date

- **§ 64 Abs. 3 AO is now €50,000, not €45,000** — raised by the Steueränderungsgesetz 2025, **in force 1.1.2026**. €45,000 (from €35,000, JStG 2020) was correct only through VZ 2025. Most secondary literature still says €45,000 and has not caught up.
- **§ 141 Abs. 1 AO is €800,000 / €80,000** (Wachstumschancengesetz, in force 28.3.2024).

> § 64 Abs. 3 AO: „Übersteigen die Einnahmen einschließlich Umsatzsteuer aus wirtschaftlichen Geschäftsbetrieben, die keine Zweckbetriebe sind, insgesamt nicht **50 000 Euro** im Jahr, so unterliegen die diesen Geschäftsbetrieben zuzuordnenden Besteuerungsgrundlagen nicht der Körperschaftsteuer und der Gewerbesteuer."

It is a **Freigrenze, not a Freibetrag** — exceed by one euro and the entire Bemessungsgrundlage becomes taxable. And § 64 Abs. 2 AO **aggregates**: the bar is measured together with Werbung, Vereinsfeste and every other non-Zweckbetrieb activity, not on its own.

### 2.2 The unconditional recording duty nobody had found: § 63 Abs. 3 AO

This is the load-bearing citation, and it is simpler and stronger than the AEAO route the ticket was hunting for:

> „Die Körperschaft hat den Nachweis, dass ihre tatsächliche Geschäftsführung den Erfordernissen des Absatzes 1 entspricht, durch **ordnungsmäßige Aufzeichnungen über ihre Einnahmen und Ausgaben** zu führen."
> — § 63 Abs. 3 AO

No threshold. No "unless below €50,000". It applies to the whole Körperschaft, in every sphere. **Being under the Besteuerungsgrenze removes no record-keeping duty, because the duty never came from § 64 in the first place.**

The AEAO's own relief language confirms this by presupposing the records exist: below the threshold the Mittelverwendung question need not be pursued „wenn bei **überschlägiger Prüfung der Aufzeichnungen** erkennbar ist, dass … keine Verluste entstanden sind" (AEAO zu § 64). The relief runs to the *authority's* audit intensity, not to the *club's* recording duty.

### 2.3 § 141 AO removes the upgrade, not the baseline

> § 141 Abs. 2 AO: „Die Verpflichtung nach Absatz 1 ist vom Beginn des Wirtschaftsjahrs an zu erfüllen, das auf die Bekanntgabe der **Mitteilung** folgt, durch die die Finanzbehörde auf den Beginn dieser Verpflichtung hingewiesen hat."

A constitutive Mitteilung is required, effective only from the following Wirtschaftsjahr — so even exceeding the threshold would not auto-trigger. Being below means **no doppelte Buchführung, no Bilanz**. It does not touch §§ 145–147 AO.

⚠️ **§ 140 AO and Vereinsrecht: genuinely unresolved.** Whether § 27 Abs. 3 i.V.m. §§ 666, 259 BGB (Rechenschaftspflicht of the Vorstand) counts as "andere Gesetze" pulling an accounting duty into tax law via § 140 AO is contested. Practitioner sources assert the pull-through as obvious; **none engaged with the distinction between a duty to *render account* and a duty to *keep books***, and the academic commentary could not be reached. **Not outcome-determinative here** — § 63 Abs. 3 AO independently requires the records, and § 259 Abs. 1 BGB's „geordnete Zusammenstellung der Einnahmen oder der Ausgaben" plus Belege is a *lower* bar than § 63 Abs. 3 already demands.

### 2.4 § 19 UStG — the one genuine record-keeping relief, and it is a substitution

The JStG 2024 rewrite is confirmed. Since 1.1.2025 the regime is a genuine **Steuerbefreiung**:

> § 19 Abs. 1 S. 1 UStG: „Ein … Umsatz im Sinne des § 1 Absatz 1 Nummer 1 **ist steuerfrei**, wenn der Gesamtumsatz nach Absatz 2 im vorangegangenen Kalenderjahr **25 000 Euro** nicht überschritten hat und im laufenden Kalenderjahr **100 000 Euro** nicht überschreitet."

§ 19 Abs. 1 S. 2 lists what is disapplied — § 4 Nr. 1 lit. b, § 6a, § 9, § 14a, § 18 Abs. 1–4. **§ 22 UStG is not in that list, and neither are §§ 145–147 AO.** The relief is in the UStDV instead, and this is the single most consequential provision in the whole hinge analysis:

> **§ 65 UStDV (Aufzeichnungspflichten der Kleinunternehmer):** „Unternehmer, auf deren Umsätze § 19 Abs. 1 Satz 1 des Gesetzes anzuwenden ist, haben **an Stelle der nach § 22 Abs. 2 bis 4 des Gesetzes vorgeschriebenen Angaben** Folgendes aufzuzeichnen: 1. **die Werte der erhaltenen Gegenleistungen** für die von ihnen ausgeführten Lieferungen und sonstigen Leistungen …"

Note precisely what this does:
- **Substitution, not abolition** — "an Stelle der".
- **§ 22 Abs. 1 S. 1 UStG survives** (§ 65 UStDV displaces only Abs. 2–4): „Der Unternehmer ist verpflichtet, zur Feststellung der Steuer und der Grundlagen ihrer Berechnung Aufzeichnungen zu machen."
- The residual duty is to record **"die Werte der erhaltenen Gegenleistungen"** — the *values received*. Not the article, not the buyer, not the unit price, not the USt rate.

**This is what knocks out the umsatzsteuerliche pillar of the per-drink field list.** See §3.4.

### 2.5 §§ 145–147 AO have no thresholds at all

> § 145 Abs. 2 AO: „Aufzeichnungen sind so vorzunehmen, dass der Zweck, den sie für die Besteuerung erfüllen sollen, erreicht wird."

> § 146 Abs. 6 AO: „Die Ordnungsvorschriften gelten auch dann, wenn der Unternehmer Bücher und Aufzeichnungen, die für die Besteuerung von Bedeutung sind, führt, **ohne hierzu verpflichtet zu sein**."

Nothing in §2.1–§2.4 switches these off. **Being small changes *what* you must write down; it does not change *how* you must write it down.**

---

## 3. THE HINGE — at what granularity?

### 3.1 The decisive structural point: the GoBD and §§ 145–147 AO are *accessory*

Neither the GoBD nor §§ 145–147 AO **create** a record-keeping duty. They govern *how* records must be kept once some other norm requires them. The GoBD says so in its own words:

> **Rz. 3** „Nach § 140 AO sind die außersteuerlichen Buchführungs- und Aufzeichnungspflichten, die für die Besteuerung von Bedeutung sind, auch für das Steuerrecht zu erfüllen. …"
> **Rz. 4** „Steuerliche Buchführungs- und Aufzeichnungspflichten ergeben sich sowohl aus der Abgabenordnung (z. B. §§ 90 Absatz 3, 141 bis 144 AO) als auch aus Einzelsteuergesetzen (z. B. § 22 UStG, § 4 Absatz 3 Satz 5, § 4 Absatz 4a Satz 6, § 4 Absatz 7 und § 41 EStG)."
> **Rz. 7** „Die Ordnungsvorschriften der §§ 145 bis 147 AO gelten für die **vorbezeichneten** Bücher und sonst erforderlichen Aufzeichnungen …"

The BFH states the same principle for retention, and it is the single most useful holding in this whole file:

> 1. „Die Datenanforderung nach § 147 Abs. 6 AO ist **akzessorisch** zur Aufzeichnungs- und Aufbewahrungspflicht des Steuerpflichtigen."
> 2. „Bei der Gewinnermittlung durch Einnahmen-Überschussrechnung sind Aufzeichnungen **nur aufzubewahren, soweit dies aufgrund anderer Steuergesetze** … gefordert ist."
> 3. „**‚Freiwillig' geführte Unterlagen und Daten unterliegen nicht dem Datenzugriff** nach § 147 Abs. 6 AO."
> — BFH, Urt. v. 12.02.2020, **X R 8/18**, Leitsätze 1–3

So "does the GoBD reach our per-drink log?" is not answerable on its own. It reduces to: **is there a norm requiring the per-drink record?**

### 3.2 Which norms could require it, and what each actually demands

| Candidate | Applies? | What it demands |
|---|---|---|
| § 140 AO via HGB | **No** — Vereinsregister, not Kaufmann | — |
| § 141 AO | **No** — far below, and needs a Mitteilung | — |
| § 4 Abs. 3 S. 5 / Abs. 7 EStG | **No** — not engaged on these facts | — |
| **§ 22 Abs. 1 S. 1 UStG** | **Yes** | Aufzeichnungen „zur Feststellung der Steuer und der Grundlagen ihrer Berechnung" |
| **§ 65 UStDV** (displaces § 22 Abs. 2–4) | **Yes** | only **„die Werte der erhaltenen Gegenleistungen"** |
| **§ 63 Abs. 3 AO** | **Yes, unconditionally** | „ordnungsmäßige Aufzeichnungen über ihre **Einnahmen und Ausgaben**" |

**Both live duties are phrased in terms of money received, not goods handed over.** Hold that thought — it is the whole of §3.4.

### 3.3 Where the club has no escape: Einzelaufzeichnung applies in full

> § 146 Abs. 1 S. 1 AO: „Die Buchungen und die sonst erforderlichen Aufzeichnungen sind **einzeln**, vollständig, richtig, zeitgerecht und geordnet vorzunehmen."
> § 146 Abs. 1 S. 3 AO: „Die Pflicht zur Einzelaufzeichnung nach Satz 1 besteht aus Zumutbarkeitsgründen bei Verkauf von Waren an eine **Vielzahl von nicht bekannten Personen gegen Barzahlung** nicht."

The Zumutbarkeit exception has **two cumulative requirements**, and the club fails **both**: there is no cash, and every buyer is a named member identified by RFID at the point of sale. The GoBD adds a third disqualifier:

> **Rz. 39** „… Wird hingegen ein **elektronisches Aufzeichnungssystem** verwendet, gilt die Einzelaufzeichnungspflicht nach § 146 Absatz 1 Satz 1 AO unabhängig davon, ob das elektronische Aufzeichnungssystem … mit einer zertifizierten technischen Sicherheitseinrichtung zu schützen sind."

And for non-cash transactions there is no exception at all — the Zumutbarkeit escape is drafted only for Barzahlung.

**Summen-only recording is not available to this club.** Note the irony worth recording in the ADR: *the cashless, member-identified, digital design is exactly what forecloses the exception.* The feature that eliminates KassenSichV/TSE risk is the feature that pulls in Einzelaufzeichnung.

### 3.4 But *of what*? — the genuinely fine distinction

`Einzeln` attaches to „die Buchungen **und die sonst erforderlichen Aufzeichnungen**". It does not float free; it qualifies whatever records are *required*. So the granularity question is: **required records of what?**

**Reading (a) — the drink is the Geschäftsvorfall.** Each sale is a Geschäftsvorfall; einzeln therefore means per drink. This is the mainstream reading and what the Finanzverwaltung applies. It has the AEAO's field list behind it:

> „Zeitnah … aufzuzeichnen sind der **verkaufte, eindeutig bezeichnete Artikel**, der **endgültige Einzelverkaufspreis**, der dazugehörige **Umsatzsteuersatz und -betrag**, vereinbarte Preisminderungen, die **Zahlungsart**, das **Datum und der Zeitpunkt des Umsatzes** sowie die **verkaufte Menge bzw. Anzahl**."
> — AEAO zu § 146 Nr. 2.1.3

> „Der Grundsatz der Einzelaufzeichnungspflicht gilt **auch für Steuerpflichtige, die ihren Gewinn nach § 4 Abs. 3 EStG ermitteln**."
> — AEAO zu § 146 Nr. 2.1.7

**Reading (b) — the payment received is what must be recorded.** Three independent supports:

1. **§ 65 UStDV**: for a Kleinunternehmer the required content is *„die Werte der **erhaltenen** Gegenleistungen"*. Note that the AEAO Nr. 2.1.3 field list above is essentially the § 22 Abs. 2 catalogue in prose — and § 65 UStDV **displaces § 22 Abs. 2–4 wholesale**. The umsatzsteuerliche foundation of that field list does not exist for this club.
2. **§ 63 Abs. 3 AO** requires Aufzeichnungen über „**Einnahmen und Ausgaben**" — cash flows, not stock movements.
3. **§ 11 EStG / § 4 Abs. 3 EStG.** Under EÜR a receivable is not income. The AEAO confirms this is the measure even for the § 64 Abs. 3 Freigrenze: „Bei anderen steuerbegünstigten Körperschaften sind die im Kalenderjahr **zugeflossenen** Einnahmen (§ 11 EStG) maßgeblich." The Betriebseinnahme arises on the SEPA credit, not on the pour. GoBD Rz. 37 requires „die Aufzeichnung jedes Geschäftsvorfalls - also auch **jeder Betriebseinnahme und Betriebsausgabe**" — which, for an EÜR taxpayer, points at the payment.

**Reading (a) wins, and the GoBD closes it more firmly than expected.** Two Randziffern speak directly to the exact architecture this project proposes — per-drink detail in a Vorsystem, aggregate totals booked:

> **Rz. 87** „Sowohl beim Einsatz von Haupt- als auch von Vor- oder Nebensystemen ist eine Verbuchung im Journal des Hauptsystems … bis zum Ablauf des folgenden Monats nicht zu beanstanden, wenn die einzelnen Geschäftsvorfälle bereits in einem Vor- oder Nebensystem die Grundaufzeichnungsfunktion erfüllen **und die Einzeldaten aufbewahrt werden**."

> **Rz. 99** „**Bei der Übernahme verdichteter Zahlen ins Hauptsystem müssen die zugehörigen Einzelaufzeichnungen aus den Vor- und Nebensystemen erhalten bleiben.**"

That is not ambiguous. The permission to book monthly aggregates is **expressly conditioned** on retaining the per-item detail behind them. And the GoBD lists the failure mode as a worked example of *unzutreffende Qualifizierung*:

> **Rz. 161, Beispiele 12** — „Ein Steuerpflichtiger stellt aus dem PC-Kassensystem nur **Tagesendsummen** zur Verfügung. Die digitalen Grund(buch)aufzeichnungen (Kasseneinzeldaten) wurden archiviert, aber nicht zur Verfügung gestellt."

**Assessment: (a) is the law as the administration applies it, and Rz. 99 is close to dispositive. (b) is a genuine but untested argument.** Reading (b) is not frivolous — it is built entirely from primary sources and BFH X R 8/18 supports its underlying logic. But the Finanzverwaltung's own guidance is written against it at three separate points (AEAO Nr. 2.1.7, GoBD Rz. 87, Rz. 99). **Do not design the system on reading (b).**

### 3.5 What is settled, and is the most useful finding in this file

**Aufzeichnung ≠ Verbuchung**, and the AEAO says so explicitly:

> „**Eine Verpflichtung zur einzelnen Verbuchung (im Gegensatz zur Aufzeichnung) eines jeden Geschäftsvorfalls besteht nicht.**"
> — AEAO zu § 146 Nr. 2.1.3

And the GoBD permits condensed bookings provided they can be broken down:

> **Rz. 42** „Zusammengefasste oder verdichtete Aufzeichnungen im Hauptbuch (Konto) sind zulässig, sofern sie **nachvollziehbar in ihre Einzelpositionen** in den Grund(buch)aufzeichnungen oder des Journals **aufgegliedert werden können**."

**This answers the ticket's question as posed.** The two options it offered were a false dichotomy:

- The **Buchung** is the monthly SEPA collection (plus the bank statement). Per-drink Verbuchung is expressly not required. The ticket's second option is correct *at the level of the books*.
- The **Aufzeichnung** is per-drink, and it is the Grundaufzeichnung that the condensed booking must be capable of being broken down into. The ticket's first option is correct *at the level of the underlying record*.

The per-drink log is therefore neither "a Buchungsbeleg" nor "the club's own operational data". It is a **Grund(buch)aufzeichnung / Vorsystem**, and the GoBD reaches Vorsysteme expressly:

> **Rz. 20** „Unter DV-System wird die im Unternehmen … eingesetzte Hard- und Software verstanden … Dazu gehören das Hauptsystem sowie **Vor- und Nebensysteme** (z. B. Finanzbuchführungssystem, … **Kassensystem**, **Warenwirtschaftssystem**, Zahlungsverkehrssystem, … Fakturierung …) einschließlich der Schnittstellen zwischen den Systemen."

### 3.6 The sting: recording who drank what makes those records aufbewahrungspflichtig

The club must record member identity — not for tax reasons, but because it cannot bill otherwise. The AEAO addresses exactly this:

> „Es wird z.B. nicht beanstandet, wenn die Mindestangaben zur Nachvollziehbarkeit des Geschäftsvorfalls … einzeln aufgezeichnet werden, **nicht jedoch die Kundendaten**, sofern diese nicht zur Nachvollziehbarkeit und Nachprüfbarkeit des Geschäftsvorfalls benötigt werden. … **Soweit Aufzeichnungen über Kundendaten aber tatsächlich geführt werden, sind sie aufbewahrungspflichtig, sofern dem nicht gesetzliche Vorschriften entgegenstehen.**"
> — AEAO zu § 146 Nr. 2.1.5

Read that carefully, because it cuts three ways:

1. **Customer data is not *required* per transaction** where it is not needed for Nachvollziehbarkeit. For this club it *is* needed — the whole Geschäftsvorfall is "member X owes €2.50" — so this relief does not apply.
2. **Records actually kept become aufbewahrungspflichtig.** This is the strongest authority against free deletion of a member's drink history.
3. **„sofern dem nicht gesetzliche Vorschriften entgegenstehen"** — an express carve-out for conflicting statutes. Data-protection law is the obvious candidate. ⚠️ **No authority found** interpreting this clause.

⚠️ **And it sits in direct tension with BFH X R 8/18 Leitsatz 3** ("freiwillig geführte Unterlagen unterliegen nicht dem Datenzugriff"). The AEAO (2018) says voluntarily-kept customer records are aufbewahrungspflichtig; the BFH (2020) says voluntarily-kept records are not reachable. They are not squarely contradictory — one is about retention, the other about Datenzugriff — but they pull opposite ways and **no source found reconciles them.** Flag for the Steuerberater.

### 3.7 Does digitising it raise the burden? — Not the *whether*, but definitively the *how*

The GoBD's reach does **not** depend on the record being electronic — it is accessory either way (Rz. 3–7). Digitising creates no new obligation.

But it changes the form in which any existing obligation must be met, and this is a real, non-obvious cost:

> **Rz. 119** „Sind aufzeichnungs- und aufbewahrungspflichtige Daten … im Unternehmen entstanden …, sind sie **auch in dieser Form aufzubewahren** und dürfen vor Ablauf der Aufbewahrungsfrist **nicht gelöscht** werden. Sie dürfen daher **nicht mehr ausschließlich in ausgedruckter Form aufbewahrt** werden und müssen für die Dauer der Aufbewahrungsfrist **unveränderbar** erhalten bleiben."

> **Rz. 129** „Die **Reduzierung einer bereits bestehenden maschinellen Auswertbarkeit** … ist **nicht zulässig**." — with the express example „Umwandlung von elektronischen Grund(buch)aufzeichnungen (z. B. Kasse, Warenwirtschaft) in ein PDF-Format."

**So: a paper Strichliste and a database row do not attract the same regime.** Both are accessory to the same underlying duty, but once the record is born digital it must stay digital, stay unveränderbar, stay maschinell auswertbar, and may not be printed-and-purged. **Digitising the Kaffeekasse does raise the compliance burden**, and the club should know that — it is a real cost of the project, and it is not what the ADR currently says.

### 3.8 Where this system sits — and one boundary it must not cross

The TSE/KassenSichV analysis in ADR-0028 §6 **survives**: § 1 Abs. 1 KassenSichV requires „zumindest teilweise **baren** Zahlungsvorgängen". No cash → no TSE, no DSFinV-K, no Belegausgabepflicht, no Kassenbuch, no Kassensturzfähigkeit. Note this is *policy*, not observation — re-admitting cash reopens the analysis.

⚠️ The prepaid/post-paid boundary flagged in ADR-0028 §6 also **survives unresolved**. AEAO zu § 146a Nr. 1.2 extends Kassenfunktion to „virtuelle (Kunden-)Konten"; a prepaid balance plausibly reads as in scope, a post-paid Forderung does not. **Still no authority found either way.** Terminal-side top-up remains ruled out by design.

### 3.9 Direct answer to Question 3

> **Must the club retain per-drink, per-member transaction records — or is the retained Buchungsbeleg the monthly SEPA collection plus the bank statement?**

**Both, at different layers.**

- The **Buchungsbeleg** is the monthly SEPA collection plus the bank statement (and the per-member monthly Abrechnung that shows how the collected amount arose). That is where the Betriebseinnahme is booked. Per-drink Verbuchung is expressly not required (AEAO zu § 146 Nr. 2.1.3).
- The **per-drink, per-member log** is a Grund(buch)aufzeichnung, not operational data. On the mainstream reading it must be kept einzeln (§ 146 Abs. 1 S. 1 AO — no Zumutbarkeit escape available), and once member identity is recorded it is aufbewahrungspflichtig (AEAO zu § 146 Nr. 2.1.5).

**Consequence for GDPR:** erasure of a member's drink history during the retention period is **restriction of processing** under Art. 17(3)(b) / Art. 18 DSGVO, not deletion. The ticket's first branch is the operative one.

⚠️ **The unresolved residue**, stated plainly rather than smoothed over: reading (b) in §3.4 — that for a Kleinunternehmer keeping an EÜR the required Aufzeichnung is of *payments received* rather than *drinks poured* — is a genuine argument built from primary sources, and **no authority addresses a cashless internal member ledger of this kind either way.** If it is right, the per-drink log is freiwillig and BFH X R 8/18 Leitsatz 3 frees it. It is a real argument, it is not the safe one, and it should not be built on. GoBD Rz. 99 is the sentence it has to get past.

One proportionality lens exists but does not change the answer:

> **Rz. 15** „Bei Kleinstunternehmen, die ihren Gewinn durch Einnahmen-Überschussrechnung ermitteln (bis 17.500 Euro Jahresumsatz), ist die Erfüllung der Anforderungen an die Aufzeichnungen nach den GoBD **regelmäßig auch mit Blick auf die Unternehmensgröße zu bewerten**."

This is a lens on *how strictly* the requirements are assessed, not an exemption, and it is capped at €17,500 turnover in the wGB. The club may well be under it — worth noting to the Steuerberater, but not a basis for retaining nothing.

---

## 4. Retention periods, and what survives an erasure request

### 4.1 § 147 Abs. 3 AO — current periods, and the reconciliation the ticket asked for

> § 147 Abs. 3 S. 1 AO: „Die in Absatz 1 Nummer 1 und 4a aufgeführten Unterlagen sind **zehn Jahre**, die in Absatz 1 Nummer 4 aufgeführten Unterlagen **acht Jahre** und die sonstigen in Absatz 1 aufgeführten Unterlagen **sechs Jahre** aufzubewahren …"

> § 147 Abs. 4 AO: „Die Aufbewahrungsfrist beginnt mit dem **Schluss des Kalenderjahrs**, in dem die letzte Eintragung in das Buch gemacht … oder der Buchungsbeleg entstanden ist …"

Only **Nr. 4 Buchungsbelege** dropped 10 → 8 (Viertes Bürokratieentlastungsgesetz, in force 1.1.2025). Nr. 1 — *Bücher und **Aufzeichnungen***, Inventare, Jahresabschlüsse, Organisationsunterlagen — **stayed at 10 years**. Nr. 2, 3, 5 are 6 years.

The transitional rule is retroactive, and this is primary-sourced:

> **Art. 97 § 19a Abs. 2 EGAO**: „§ 147 Absatz 3 Satz 1 der Abgabenordnung in der ab dem 1. Januar 2025 geltenden Fassung gilt … **erstmals für alle Unterlagen, deren Aufbewahrungsfrist nach § 147 Absatz 3 der Abgabenordnung in der bis einschließlich 31. Dezember 2024 geltenden Fassung noch nicht abgelaufen ist**."

**And the Ablaufhemmung, which nothing in the repo currently models:**

> § 147 Abs. 3 S. 5 AO: „Die Aufbewahrungsfrist läuft jedoch **nicht ab, soweit und solange die Unterlagen für Steuern von Bedeutung sind, für welche die Festsetzungsfrist noch nicht abgelaufen ist**; § 169 Absatz 2 Satz 2 gilt nicht."

Note the final half-sentence: the extended 5-/10-year Festsetzungsfristen for leichtfertige Steuerverkürzung and Steuerhinterziehung are **expressly excluded**, so the hemmung can only ever run on the ordinary 4-year Frist (§ 169 Abs. 2 S. 1 Nr. 2 AO).

**Worked for this club's three-year filing rhythm.** Take VZ 2026, filed in 2029. Under § 170 Abs. 2 S. 1 Nr. 1 AO the Festsetzungsfrist begins at the end of the filing year (31.12.2029) — and the three-year cap in the same sentence lands on the same date. Plus four years: **the Festsetzungsfrist for VZ 2026 ends 31.12.2033.** Against the base periods for 2026 documents: Nr. 1 runs to 2036 (longer, unaffected), Nr. 4 to 2034 (longer, unaffected), **Nr. 2/3/5 to 2032 — stretched to 2033.**

So: the Ablaufhemmung bites on exactly one class, turning the 6-year documents into 7-year documents. Implement the purge date as `max(base_period_end, festsetzungsfrist_end)`, not a flat constant.

**§ 257 HGB is irrelevant** — it binds „jeder Kaufmann" (§ 257 Abs. 1), and an eingetragener Idealverein under § 21 BGB running a members-only bar at cost is not one. ⚠️ This is an application of § 1 HGB / § 21 BGB to the facts; no case law on a rowing club's bar was found.

### 4.2 The 14-month figure in ADR-0010 is wrong. There is no such rule.

A full-text search of the **SEPA Direct Debit Core Scheme Rulebook, EPC016-06, 2025 Version 1.1** (effective 5 October 2025) for "14 months" returns **zero hits**. The actual rule states no number at all:

> **§4.1** „The signed Mandate … must be stored by the Creditor **for as long as the Mandate exists**. … After cancellation, the Mandate must be stored by the Creditor according to the applicable national legal requirements, its Terms and Conditions with the Creditor PSP and **as a minimum, for as long as may be required under section 4.6.4** … for a Debtor to obtain a Refund for an Unauthorised Transaction."

> **§4.6.4** „…a Debtor must present its claim to the Debtor PSP within **13 months** of the debit date in accordance with Article 71 of the Payment Services Directive."

**13, not 14.** "14 months" appears to be an industry rule of thumb (13 + a buffer). ⚠️ **No legal or scheme authority for the figure 14 was found anywhere.**

The German source of the 13 months is **§ 676b Abs. 2 BGB**, not § 675x Abs. 3 as ADR-0028 §3 has it:

> § 676b Abs. 2 BGB: „Ansprüche und Einwendungen … sind ausgeschlossen, wenn dieser seinen Zahlungsdienstleister **nicht spätestens 13 Monate nach dem Tag der Belastung** mit einem nicht autorisierten oder fehlerhaft ausgeführten Zahlungsvorgang hiervon unterrichtet hat. Der Lauf der Frist beginnt nur, wenn der Zahlungsdienstleister den Zahlungsdienstnutzer … unterrichtet hat …"

Sting in sentence 2: if the payer was never properly informed, **the 13 months never start running.**

The 8-week window is **§ 675x Abs. 4 BGB** (ADR-0028 cites § 675x generally, which is close enough, but the Absatz is wrong):

> § 675x Abs. 4 BGB: „Ein Anspruch des Zahlers auf Erstattung ist ausgeschlossen, wenn er ihn nicht innerhalb von **acht Wochen** ab dem Zeitpunkt der Belastung … geltend macht."

And one genuine scheme rule the repo does not model at all:

> **EPC Rulebook §4.2**: „If a Creditor does not present a Collection under a Mandate for a period of **36 months** … the Creditor **must cancel the Mandate** and is no longer allowed to initiate Collections based on this cancelled Mandate."

That is an obligation to **stop collecting**, not to delete. It bears on [#164](https://github.com/dgloeckner/clubbar/issues/164)'s mandate lifecycle.

**Which period governs the mandate?** § 147 AO — the scheme rule is a contractual minimum between Creditor and Creditor PSP and cannot shorten a public-law duty (§ 147 Abs. 3 S. 2 AO: „Kürzere Aufbewahrungsfristen nach außersteuerlichen Gesetzen lassen die in Satz 1 bestimmte Frist unberührt").

⚠️ **No authority found** classifying a SEPA mandate under § 147 Abs. 1 AO — Nr. 4 (Buchungsbeleg, 8 y) or Nr. 5 (sonstige Unterlage, 6 y). GoBD Rz. 62 lists „Verträge" among Belegarten, and Rz. 63 says a Geschäftsbrief becomes a Buchungsbeleg only „mit dem Kontierungsvermerk und der Verbuchung" — which a mandate never gets. Nr. 5 is the better reading; Nr. 4 is the safe one. **Recommendation: retain 8 years from 31.12. of the year of the last collection under it.** That dominates every other rule and needs no per-record classification logic.

The civil-law backstop (§§ 195, 199 BGB — 3 years from 31.12. of the year the claim arose) is **fully absorbed** by the tax periods and should not drive the retention design.

### 4.3 The reconciliation table

| Figure | Verdict |
|---|---|
| **10 years** | Correct, but **only** for § 147 Abs. 1 **Nr. 1** — Bücher, **Aufzeichnungen**, Inventare, Jahresabschlüsse, Organisationsunterlagen. ADR-0010's "settlement records 10 years" is right if the settlement record is the *Aufzeichnung*; if it is the *Beleg*, it is 8. |
| **8 years** | Correct for **Nr. 4 Buchungsbelege** since 1.1.2025, retroactive per Art. 97 § 19a Abs. 2 EGAO. Also § 14b Abs. 1 S. 1 UStG for Rechnungen. |
| **6 years** | Nr. 2, 3, 5 — **but 7 years in practice** for this club via the Ablaufhemmung. |
| **14 months** | **Wrong. No authority anywhere.** ADR-0010's table must be corrected. |
| **13 months** | Real — the *Debtor's* deadline for an unauthorised-transaction refund (§ 676b Abs. 2 BGB / PSD2 Art. 71 / EPC §4.6.4). A floor on mandate storage, never a ceiling. |
| **36 months** | Real — EPC §4.2, Creditor **must cancel** an unused mandate. Not a deletion rule. |
| **8 weeks** | § 675x Abs. 4 BGB. Irrelevant to retention. |

⚠️ **8 vs 10 years for the transaction journal is genuinely open.** Whether the individual sale rows are Nr. 4 Buchungsbelege (8 y) or Nr. 1 *Aufzeichnungen* (10 y) — or both — has **no authority found post-BEG IV**. §3 concluded the per-drink log is a **Grund(buch)aufzeichnung**, which points at Nr. 1 and therefore **10 years**. **This means ADR-0028 §5's flat "8 years" is too short for the transaction journal.**

### 4.4 GDPR: it is Art. 17(3)(b), not Art. 18(1)

> **Art. 17 Abs. 3 lit. b DSGVO**: „Die Absätze 1 und 2 gelten nicht, soweit die Verarbeitung erforderlich ist … **zur Erfüllung einer rechtlichen Verpflichtung, die die Verarbeitung nach dem Recht der Union oder der Mitgliedstaaten, dem der Verantwortliche unterliegt, erfordert** …"

**The ticket asked which limb of Art. 18 Abs. 1 applies. The honest answer is: none of them.** lit. b requires the processing to be *unlawful* (it is not — § 147 AO compels it); lit. c requires the *data subject* to need the data for legal claims (here it is the controller who is compelled). The tax-retention case is not an Art. 18 Abs. 1 case at all — **the erasure obligation simply never arises** under Art. 17(3)(b).

"Restrict rather than delete" then rests on Art. 5 Abs. 1 lit. b/c/e (Zweckbindung, Datenminimierung, Speicherbegrenzung) plus the *mechanism* definitions:

> **Art. 4 Nr. 3 DSGVO**: „‚Einschränkung der Verarbeitung' die **Markierung gespeicherter personenbezogener Daten** mit dem Ziel, ihre künftige Verarbeitung einzuschränken"

> **ErwG 67**: „**In automatisierten Dateisystemen sollte die Einschränkung der Verarbeitung grundsätzlich durch technische Mittel so erfolgen, dass die personenbezogenen Daten in keiner Weise weiterverarbeitet werden und nicht verändert werden können. Auf die Tatsache, dass die Verarbeitung der personenbezogenen Daten beschränkt wurde, sollte in dem System unmissverständlich hingewiesen werden.**"

That is a direct implementation spec: a flag column, enforced read-only, excluded from every operational query, visibly marked.

⚠️ **Do not cite § 35 BDSG for database rows.** § 35 Abs. 1 BDSG applies only to „**nicht automatisierter** Datenverarbeitung" — paper files. § 35 Abs. 3 covers „**satzungsgemäße oder vertragliche** Aufbewahrungsfristen", not § 147 AO. Abs. 3 *is* useful if the club's Satzung prescribes a retention period — worth checking; if the Satzung is silent, § 147 AO is the whole answer.

Supervisory-authority confirmation, naming § 147 AO expressly — **Bayerischer Landesbeauftragter für den Datenschutz**, *Orientierungshilfe Recht auf Löschung*, v1.0 (1.6.2022), S. 41 Rn. 79:

> „Gesetzliche Regelungen, die eine (weitere) Verarbeitung der personenbezogenen Daten erforderlich machen und der Löschung entgegenstehen, können sich etwa aus **handels- oder steuerrechtlichen Aufbewahrungspflichten (etwa § 147 Abgabenordnung – AO, §§ 238, 257 Handelsgesetzbuch – HGB)** … ergeben."
> „Die Aufbewahrungspflicht steht einer vorherigen Löschung **gemäß Art. 17 Abs. 3 Buchst. b DSGVO** entgegen."

⚠️ **There is no settled case law squarely holding that Art. 17(3)(b) DSGVO covers § 147 AO.** It is the universal supervisory view and uncontroversial in practice, but it has not been adjudicated head-on. No BFH, no BAG, no EuGH decision found.

### 4.5 Field level — which fields must survive

The governing limiter comes from the tax side itself:

> **GoBD Rz. 113**: „Der **sachliche Umfang der Aufbewahrungspflicht in § 147 Absatz 1 AO besteht grundsätzlich nur im Umfang der Aufzeichnungspflicht**" (BFH v. 24.06.2009, BStBl II 2010 S. 452; BFH v. 26.02.2004, BStBl II S. 599)

> **GoBD Rz. 172**: „Enthalten elektronisch gespeicherte Datenbestände z. B. **nicht aufzeichnungs- und aufbewahrungspflichtige, personenbezogene** … Daten, so obliegt es dem Steuerpflichtigen …, die Datenbestände so zu organisieren, dass der Prüfer nur auf die aufzeichnungs- und aufbewahrungspflichtigen Daten … zugreifen kann. Dies kann z. B. durch geeignete Zugriffsbeschränkungen oder **„digitales Schwärzen"** der zu schützenden Informationen erfolgen."

And the one judgment that decides it at field granularity — **OLG Dresden, Urt. v. 14.12.2021, 4 U 1278/21**:

> „Die Beklagten sind nicht verpflichtet die geschäftliche Korrespondenz zu löschen. **Ihre Löschungspflicht beschränkt sich auf den Namen, die Anschrift und das Geburtsdatum des Klägers**, und damit auf die Daten, mit denen er eindeutig identifiziert werden kann. … **Auf der geschäftlichen Korrespondenz können die Daten, die eine Identifizierung seiner Person erlauben, geschwärzt werden.**"

⚠️ Quoted from a secondary reproduction (datenschutz-notizen.de), not an official court database.

**The test: does this field actually form part of a Beleg or Aufzeichnung that § 147 AO requires the club to keep?**

**Must survive (restrict, do not delete):** name; membership number (if it is the Ordnungskriterium tying transactions to the Aufzeichnung, GoBD Rz. 64); IBAN; mandate reference (UMR); **per-transaction records** (item, qty, price, timestamp, member link) — these *are* the Einnahmenaufzeichnungen under § 63 Abs. 3 AO; settlement/monthly statement totals; payment, return and reversal records; the mandate document.

**Must be deleted (no Belegfunktion):** email; phone; `preferred_language`; RFID/NFC token, PIN, credentials, session data; photo/avatar; free-text notes, marketing flags, consent-based data. **Postal address and date of birth** are deletable *unless* they appear on a Beleg the club issues — fact-dependent: if the club ever issues a Rechnung with USt, § 14 Abs. 4 Nr. 1 UStG pulls the address onto a retained Beleg. For a members-only bar at cost with no invoices, normally deletable, and OLG Dresden directly supports deleting name/address/DOB from the *operational* record while the accounting record is retained redacted.

**Do not null the Beleg-bearing fields** — nulling breaks the Belegfunktion (GoBD Rz. 61: „den sicheren und klaren Nachweis über den Zusammenhang zwischen den Vorgängen in der Realität … und dem aufgezeichneten oder gebuchten Inhalt"). This is precisely why **erasure and anonymisation must be separate operations** in the schema.

### 4.6 Vereinsrecht adds no period

§ 27 Abs. 3 BGB → § 666 BGB → § 259 Abs. 1 BGB („eine die geordnete Zusammenstellung der Einnahmen oder der Ausgaben enthaltende Rechnung … und, soweit Belege erteilt zu werden pflegen, Belege vorzulegen") creates a duty to *produce* an account and its Belege on demand. It presupposes the Belege exist but **states no period whatsoever**. Searched: §§ 21–79, 259, 666 BGB contain none. **Stated as a negative finding, not a gap in the research.** § 147 AO is the whole answer — unless the club's Satzung prescribes one, in which case § 35 Abs. 3 BDSG makes it operative.

---

## 5. What comparable German clubs actually do

Kept deliberately separate from the law above. "Everyone does it this way" is not a legal permission, and convention must not be presented as the floor — that is the same error as inflating a recommendation into a requirement, in the opposite direction.

### 5.1 Has anyone ever asserted Einzelaufzeichnungspflicht over a Strichliste? — The literature is SILENT

Searched `Strichliste` against Verein, Getränke, Vereinsheim, Finanzamt, Buchführung, Betriebsprüfung, Beleg, Grundaufzeichnung, aufbewahrungspflichtig, Kassenwart. **No Finanzamt guidance, no Betriebsprüfung report, no court decision, no Steuerberater commentary** addresses whether a Vereinsheim Strichliste is a retention-relevant Beleg or a discardable working aid. The construct is discussed constantly — but only operationally (it's messy, people forget to mark, the treasurer wastes hours). Nobody asks the retention question.

**This silence must be read as silence, not permission.** Note also *who* is silent: the paper Strichliste is discussed almost exclusively by vendors selling replacements for it and by club-practice writers, not by tax writers at all.

### 5.2 The relief the practice literature reaches for does not fit this club

Every Vereinsberatung source cites § 146 Abs. 1 S. 3 AO, and illustrates it with the **Vereinsfest cash stand** — anonymous cash sales — not a member tab:

> „Diese Regeln bedeuten jedoch nicht, dass jedes Getränk oder jedes Brötchen, das auf dem Vereinsfest ausgegeben wird, einzeln gebucht werden muss." — campai, *Kassenbuch im Verein führen* (MEDIUM trust)

Wolfgang Pfeffer (vereinsknowhow.de — the standard German reference, HIGH trust) states the floor:

> „Ein eigenes Kassenbuch muss nicht zwingend geführt werden. Es gilt aber: für jede Einnahme und Ausgabe muss ein Beleg vorliegen — fehlt ein Fremdbeleg, werden Eigenbelege erstellt — **für gleichartige Einnahmen werden Sammelbelege erstellt (Tageslosung)**"

And Pfeffer flags the voluntary-records trap: „die Ordnungsvorschriften gelten auch für **freiwillig** gemachte Aufzeichnungen" (§ 146 Abs. 6 AO).

**But the relief does not reach this club**, and the AEAO defines the exclusion in a way that rules out a tab by construction:

> „Von einem Verkauf von Waren an eine Vielzahl nicht bekannter Personen ist auszugehen, wenn nach der typisierenden Art des Geschäftsbetriebs alltäglich Barverkäufe an namentlich nicht bekannte Kunden getätigt werden … **Dies setzt voraus, dass die Identität der Käufer für die Geschäftsvorfälle regelmäßig nicht von Bedeutung ist.**" — AEAO zu § 146 Nr. 2.2.5

On a Deckel the buyer's identity is not incidental — it *is* the transaction. And a closing clause defeats the relief even where it would otherwise apply:

> „Auf die Aufzeichnungserleichterung können sich Dienstleister - wie auch Einzelhändler - aber insoweit **nicht berufen, als tatsächlich Einzelaufzeichnungen geführt werden**." — AEAO zu § 146 Nr. 2.2.6

**So the silence around the Strichliste is not evidence that per-member records are exempt.** It is evidence that nobody has litigated the paper case — most plausibly because a paper Strichliste in a small club has never been audited. The practice literature has generalised a narrow anonymous-cash exception past its text.

### 5.3 Does digitising change the answer? — Yes, and this is the significant finding

The GoBD's *substantive* standard is form-neutral (Rz. 22: elektronische Aufzeichnungen are judged „nach den gleichen Prinzipien … wie … manuell erstellten"; Rz. 5 expressly covers „**neben Unterlagen in Papierform** auch alle Unterlagen in Form von Daten"). **Digitising creates no new substantive obligation.**

But it changes the form in which any existing obligation must be discharged, and removes the practical escape route:

> **Rz. 119** „Sind aufzeichnungs- und aufbewahrungspflichtige Daten … im Unternehmen entstanden …, sind sie **auch in dieser Form aufzubewahren** und dürfen vor Ablauf der Aufbewahrungsfrist **nicht gelöscht** werden. Sie dürfen daher **nicht mehr ausschließlich in ausgedruckter Form aufbewahrt** werden und müssen … **unveränderbar** erhalten bleiben."

> **Rz. 129** „Die **Reduzierung einer bereits bestehenden maschinellen Auswertbarkeit** … ist **nicht zulässig**." — with the express example „Umwandlung von elektronischen Grund(buch)aufzeichnungen (z. B. Kasse, Warenwirtschaft) in ein PDF-Format."

> **Rz. 157** „Ein **Ausdruck auf Papier ist nicht ausreichend**."

**Stated plainly, as the map owner asked: keeping the drinks tally on paper genuinely attracts a lighter *retention* regime than keeping it in a database.** A paper Strichliste, once transferred, has no machine-evaluability to preserve. The identical information in a database triggers Rz. 119 (must stay electronic), Rz. 129 (must stay maschinell auswertbar — no PDF/CSV flattening that loses structure) and Rz. 157 (a printout does not discharge it). Add § 147 Abs. 6 AO Datenzugriff, whose trigger is *aufzeichnungs- und aufbewahrungspflichtig*, not *buchführungspflichtig*:

> **Rz. 159** „… Hierfür sind insbesondere die Daten der Finanzbuchhaltung … **und aller Vor- und Nebensysteme, die aufzeichnungs- und aufbewahrungspflichtige Unterlagen enthalten** …, für den Datenzugriff bereitzustellen."

**Digitising raises the compliance burden. That is a real cost of this project and it is not currently recorded anywhere.** The honest counterweight: the substantive duty was very likely never satisfied by the paper practice either — digitising does not create the § 146 AO exposure, it makes it visible and machine-auditable.

### 5.4 What Vereinsberater actually recommend

Every mainstream source recommends **aggregate** postings (Tageslosung / Sammelbeleg), not per-drink postings — which matches §3.5's Aufzeichnung/Verbuchung distinction exactly. **None of them addresses what happens to the underlying detail.** That is the gap, and it is total.

Pfeffer on sphere separation (HIGH): „getrennte ordnungsgemäße Aufzeichnungen über die Einnahmen und Ausgaben für – ideellen Bereich – Vermögensverwaltung – Zweckbetrieben – steuerpflichtigen wirtschaftliche Geschäftsbetriebe". The Finanzministerium NRW *Vereine & Steuern* (PRIMARY) lists „Selbstbewirtschaftete Vereinsgaststätte" and „Verkauf von Speisen und Getränken" as steuerpflichtige wirtschaftliche Geschäftsbetriebe and states „Geschäftsunterlagen und Nachweise über den Verein grundsätzlich 10 Jahre lang aufbewahrt werden."

On backup and duration the guidance is uniformly generic — "§ 147 AO, 6/8/10 Jahre." **No source distinguishes the settlement summary from the underlying per-drink detail. Not one.**

### 5.5 Cashless / SEPA-collected member tabs — the second, sharper silence

Searched Golfclub, Tennisverein, Schützenverein, Segelverein, Mitgliederkonto, Chipkarte, bargeldlos, Lastschrift against GoBD, Kassenführung, Aufzeichnungspflicht, Aufbewahrung. **No Steuerberater or Verband guidance on this construct could be located.**

Both of the club's hypotheses hold, and they do not cancel out:

**The lighter half is real but narrow.** No cash means no Kasse: no Kassenbuch, no Kassenbericht, no Kassensturzfähigkeit, no § 146 Abs. 1 S. 2 AO tägliche Kassenaufzeichnung, no Kassen-Nachschau (§ 146b AO), no Belegausgabepflicht, no TSE. Worth noting a second independent ground for the TSE exit that ADR-0028 does not have: **§ 1 Abs. 2 KassenSichV excludes Waren- und Dienstleistungsautomaten outright** — an unstaffed self-service dispenser plausibly qualifies. The bank statement is a genuine third-party record, but it evidences the *monthly total per member*, not the composition.

**The heavier half governs.** AEAO zu § 146 Nr. 2.1.4 closes the last door:

> „Ein elektronisches Aufzeichnungssystem ist die zur elektronischen Datenverarbeitung eingesetzte Hardware und Software, die elektronische Aufzeichnungen zur Dokumentation von Geschäftsvorfällen und somit Grundaufzeichnungen erstellt. **Als elektronische Aufzeichnungssysteme gelten auch elektronische Vorsysteme mit externer Geldaufbewahrung.**"

**"No TSE required" does not imply "no Einzelaufzeichnung required." The AEAO decouples these two questions explicitly** (GoBD Rz. 39 / AEAO Nr. 2.2.2: the Einzelaufzeichnungspflicht applies „unabhängig davon, ob das elektronische Aufzeichnungssystem … mit einer zertifizierten technischen Sicherheitseinrichtung zu schützen sind"). This is the sentence a "we don't need a Kasse" argument runs into.

**Vendor evidence — LOW trust, and the finding is what they *don't* say.** Clubfridge, selbstbedienBar and tinyTRESEN sell exactly this construct (RFID self-service, per-member booking, monthly SEPA-Lastschrift). None makes any claim about Finanzamt, GoBD, Aufbewahrung, Belege or retention. The pitch is entirely convenience. **Treat this as a negative finding rather than reassurance: an entire vendor category serving this construct has underwritten nothing.**

### 5.6 Practice vs. law, stated separately

**What practice does.** German Vereinsheime have run paper Strichlisten and Deckel for decades. Totals are transferred to the books periodically; the slip is, by all indication, thrown away. Vereinsberatung recommends aggregate booking and is silent on the fate of the tally.

**What practice PROVES about the law: essentially nothing** — and the reasons matter. (1) The practice is *undocumented*: not one source even *describes* the discard step, let alone endorses it, so there is no "widely-advised practice" to point to; there is an absence. (2) The absence of challenge is far better explained by the absence of audits — a few-dozen-member club below the Besteuerungsgrenze is close to never subject to a Betriebsprüfung — than by tolerance. (3) The one place practice and law touch, § 146 Abs. 1 S. 3 AO, is a relief for *anonymous cash sales* that on its own terms does not cover a named-member tab.

So the practice was probably never lawful in the strict sense. It was merely never examined.

**Where practice and law visibly diverge:** the relief everyone cites doesn't fit (§5.2); digitising eliminates the practical escape route (§5.3); and "no cash" buys less than it appears (§5.5).

**The honest bottom line, with each half labelled.** *As law:* the per-drink records are Grundaufzeichnungen in a Vorsystem, and GoBD Rz. 87/99 require them retained in electronic, machine-evaluable form for the § 147 Abs. 3 AO period. No authority pointing the other way was found. *As practice:* clubs this size have kept nothing for decades and have not been challenged, and the audit probability is very low. Both statements are true, they are not in tension, and the second is not a defence to the first. Since the system is being built now, the marginal cost of retaining the detail is near zero — **design for retention, and treat any shorter policy as a deliberate, documented risk decision rather than a reading of the law.**

---

## 6. Does ADR-0028 need correcting? — Yes: §3, §5 and §6, plus seven additions

**The headline: the premise under review holds.** The bar *is* a wirtschaftlicher Geschäftsbetrieb, and the club *does* carry bookkeeping obligations for it. The ⚠️⚠️ banner on ADR-0028 can be lifted. But it was right by luck, and four specific corrections are needed.

| Section | Status | Correction |
|---|---|---|
| **§1** (credit refundable, § 812 BGB) | **Unaffected** | Rests on BGB, not tax bookkeeping. §1.4 here *confirms* the Leistungsaustausch on sources rather than by inheritance. |
| **§2** (pain.008 `minInclusive`) | **Unaffected** | Structural schema fact. |
| **§3** (return windows) | **Amend — wrong Absatz, and one number is fictional** | The 8-week window is **§ 675x Abs. 4** BGB, not § 675x generally. The 13-month window is **§ 676b Abs. 2 BGB**, not § 675x Abs. 3 (which is the opt-out agreement). Add the § 676b Abs. 2 S. 2 sting: the 13 months never start if the payer was not informed. Add **EPC §4.2's 36-month mandate-cancellation rule**, which is currently modelled nowhere. |
| **§4** (Rz. 64 linkage) | **Confirmed, verify version** | „Korrektur- bzw. Stornobuchungen müssen auf die ursprüngliche Buchung rückbeziehbar sein" is **still at Rz. 64** in the consolidated 14.07.2025 GoBD. The citation survives both amending letters. |
| **§5** (retention) | **Amend — the period is too short and the base is incomplete** | "8 years" is right for **Buchungsbelege** (Nr. 4). It is **wrong for the transaction journal**, which §3 establishes as a *Grund(buch)aufzeichnung* → § 147 Abs. 1 **Nr. 1** → **10 years**. Add the **§ 147 Abs. 3 S. 5 Ablaufhemmung**, which turns the 6-year classes into 7 and is modelled nowhere. Add § 147 Abs. 4's Fristbeginn (Schluss des Kalenderjahrs). ⚠️ Flag the 8-vs-10 classification as having no authority post-BEG IV. |
| **§6** (outside TSE) | **Confirmed and strengthened, but the framing is wrong in one important way** | The TSE exit holds and gains a **second independent ground**: § 1 Abs. 2 KassenSichV excludes Waren- und Dienstleistungsautomaten. But the §6 diagram implies the cashless design *reduces* obligations. It does the opposite for Einzelaufzeichnung: **no cash + known members + electronic system removes every escape from § 146 Abs. 1 S. 1 AO.** AEAO Nr. 2.1.4 and GoBD Rz. 39 decouple "no TSE" from "no Einzelaufzeichnung" expressly. The prepaid/post-paid ⚠️ survives unresolved. |

**What ADR-0028 is missing entirely and should gain:**

1. **§ 63 Abs. 3 AO** — the unconditional, threshold-free duty to keep „ordnungsmäßige Aufzeichnungen über ihre Einnahmen und Ausgaben". This is the load-bearing citation for the whole system and it appears nowhere in the ADR.
2. **GoBD Rz. 87 and Rz. 99** — the permission to book aggregates is *conditioned* on retaining the Einzeldaten behind them. This is the single most directly applicable pair of sentences to this architecture.
3. **The Aufzeichnung/Verbuchung distinction** (AEAO zu § 146 Nr. 2.1.3) — which dissolves the ticket's own false dichotomy.
4. **The digitisation asymmetry** (GoBD Rz. 119, 129, 157) — a real project cost, currently unrecorded.
5. **The field-level erasure rule** (GoBD Rz. 113, Rz. 172, OLG Dresden 4 U 1278/21) — this is what [#165](https://github.com/dgloeckner/clubbar/issues/165) needs, and it is more permissive than a blanket "retain everything": contact data must still be deleted.
6. **A correction to ADR-0010's table**: "14 months (PSD2)" has **no authority whatsoever**. Zero hits in the EPC Rulebook, nothing in PSD2, nothing in the BGB.
7. **⚠️ The § 55 AO structural-loss risk** (§1.5 above) — out of scope for #174, but the largest live Gemeinnützigkeit exposure found, and it is *created* by the at-cost pricing policy.

**Do not cite § 35 BDSG for database rows** — Abs. 1 covers only nicht-automatisierte Verarbeitung; Abs. 3 covers satzungsgemäße/vertragliche periods, not § 147 AO.

---

## 7. Everything with no supporting authority

Collected in one place, per the ticket's instruction to flag rather than smooth over.

1. **No authority** on a Verein supplying goods to members at **exact acquisition cost** — Lieferung or Selbstkostenerstattung/Kostenumlage. Defeated by general rules, not by a case on point.
2. **No authority** on whether cost-recovery-only inflows satisfy „Einnahmen … erzielt werden" in § 14 S. 1 AO.
3. **No authority** on **unstaffed / self-service** arrangements. Every Vereinsgaststätte authority presupposes a staffed operation without saying so.
4. **No authority** on whether at-cost supply to members is a verdeckte Zuwendung under § 55 Abs. 1 Nr. 1 S. 2 AO; AEAO zu § 55 Nr. 12 gives a test but not the benchmark (club's cost or market price).
5. **No BFH decision** on a Vereinsgaststätte verifiable in primary text. The doctrine that *can* be documented is administrative (AEAO zu § 67a Nr. 6, Nr. 10) plus Finanzverwaltung publications.
6. **§ 140 AO / Vereinsrecht pull-through unresolved.** Practitioner sources assert it as obvious; none engages the render-account vs. keep-books distinction. Not outcome-determinative here.
7. **No authority** classifying a **SEPA mandate** under § 147 Abs. 1 AO — Nr. 4 (8 y) or Nr. 5 (6 y).
8. **No authority post-BEG IV** on whether a POS transaction journal is Nr. 4 (8 y) or Nr. 1 (10 y). §3 points at Nr. 1; ADR-0028's flat 8 is therefore probably short.
9. **No authority** for the figure **14 months** anywhere. It is an industry rule of thumb.
10. **No settled case law** that Art. 17 Abs. 3 lit. b DSGVO covers § 147 AO. Universal supervisory view, never adjudicated head-on.
11. **No authority** interpreting AEAO zu § 146 Nr. 2.1.5's carve-out „sofern dem nicht **gesetzliche Vorschriften** entgegenstehen" — the obvious candidate being data-protection law.
12. **⚠️ Direct tension, unreconciled by any source found**: AEAO Nr. 2.1.5 (voluntarily-kept customer records are aufbewahrungspflichtig) vs. **BFH X R 8/18 Leitsatz 3** (voluntarily-kept records are outside § 147 Abs. 6 Datenzugriff). Not squarely contradictory — retention vs. access — but they pull opposite ways.
13. **The prepaid/post-paid boundary** under AEAO zu § 146a Nr. 1.2 („virtuelle (Kunden-)Konten") remains **unresolved**, exactly as ADR-0028 §6 records. Terminal-side top-up stays ruled out by design.
14. **No guidance whatsoever** on cashless, SEPA-collected member tabs — the construct closest to this system.
15. **No source** addresses whether a bank statement covering a monthly total can substitute for Grundaufzeichnungen of its composition.
16. **The literature is wholly silent** on whether a Vereinsheim Strichliste is a Beleg or a working aid, and on the fate of per-drink detail once totals are booked. Silence, reported as silence.

**Verification caveats.** `ao.bundesfinanzministerium.de` and `usth.bundesfinanzministerium.de` are behind a bot wall; AEAO passages other than zu § 146 come from the Finanzverwaltung NRW's official 2025 reproduction or from steuerschroeder.de — reliable reproductions, one step removed from the BMF original, and **AEAO Nummern shift between annual editions**. AEAO zu § 146 is quoted verbatim from the BMF-Schreiben v. 19.06.2018 PDF. GoBD quotes are verbatim from the consolidated 14.07.2025 text. OLG Dresden 4 U 1278/21 is quoted from a secondary reproduction. Abschn. 1.4 Abs. 1 S. 2 UStAE is paraphrased, not verbatim.
