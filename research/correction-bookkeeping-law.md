# Research: does German bookkeeping law require a correction to reference the booking it corrects?

Resolves [#159](https://github.com/dgloeckner/ruderbar/issues/159), part of wayfinder map [#139](https://github.com/dgloeckner/ruderbar/issues/139).
Informs [#158](https://github.com/dgloeckner/ruderbar/issues/158) (linkage) and [#141](https://github.com/dgloeckner/ruderbar/issues/141) (exclude-and-flag payouts).

**Setting assumed throughout**: a German `e.V.` (gemeinnütziger Sportverein) runs a member bar as a `wirtschaftlicher Geschäftsbetrieb`. **No cash is handled.** Drinks are booked to an internal member tab (a receivable), collected later by SEPA-Lastschrift. Transactions are append-only; corrections are new reversing rows (ADR-0004).

All statutory quotes are from `gesetze-im-internet.de` (current consolidated text as of August 2026). GoBD quotes are from the BMF-Schreiben of 28.11.2019, GZ `IV A 4 - S 0316/19/10003 :001`, verified against the PDF hosted by [IHK München](https://www.ihk-muenchen.de/ihk/documents/Recht-Steuern/Steuerrecht/2019-11-28-GoBD.pdf) (header: "28. November 2019", DOK 2019/0962810). Neither the [11.03.2024](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/AO-Anwendungserlass/2024-03-11-aenderung-gobd.pdf?__blob=publicationFile&v=2) nor the [14.07.2025](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.pdf?__blob=publicationFile&v=3) amending letter touches any Randziffer cited here, so the 2019 wording is the current wording.

> ⚖️ **Not legal advice.** This is a documented reading of primary sources by an engineer, for the purpose of choosing a data model. The one genuinely contested point (§ 3.3 below) is flagged for a Steuerberater.

---

## 0. The short answers

| Question | Answer | Weight |
|---|---|---|
| **1. Must a correction reference the original?** | **Yes.** GoBD Rz. 64 last sentence: *"Korrektur- bzw. Stornobuchungen müssen auf die ursprüngliche Buchung rückbeziehbar sein."* A standalone adjustment carrying only a free-text reason is **not** sufficient when a specific booking is being reversed. | **Requirement**, in a BMF-Schreiben (binding on the tax administration; the standard an auditor applies), resting on § 145 Abs. 1 S. 2 and § 146 Abs. 4 AO. |
| **…but does it have to be a foreign key?** | No. The law demands *rückbeziehbar*, not a column. But every conforming implementation carries **some machine-checkable, unique linkage** (GoBD Rz. 71–73); a reason string is explicitly not one. A nullable FK filled on every specific reversal is the cheapest conforming shape. | Requirement as to substance; free as to form. |
| **…so is ADR-0004's Scenario B illegal?** | No — but it is mislabelled. A goodwill credit is a **new Geschäftsvorfall**, not a Korrektur-/Stornobuchung, so Rz. 64 never attaches to it. The defect is that ADR-0004 gives both shapes `transaction_type = 'correction'`, which makes the constraint unenforceable. | See §1.4. |
| **2. What must accompany a payout to a member?** | A Beleg with the seven Rz. 77 fields (unique ID, issuer/recipient, amount, sufficient explanation, date, responsible issuer), a **unique linkage to the actual bank transaction** (Rz. 71–73), and retention for **8 years** (§ 147 Abs. 3 AO, from 1.1.2025). | **Requirement.** |
| **…countersignature? Vier-Augen-Prinzip? Vorstandsbeschluss?** | **Not required by law.** No statute, no BMF-Schreiben, no court decision imposes any of them on a Vereins-Auszahlung. They are Satzung / Geschäftsordnung / good practice. Same for the Kassenprüfer, who has **no statutory basis at all**. | **Convention.** Do not inflate. |
| **…does a payout differ from a correction?** | **Categorically yes.** A correction restates the record of a business transaction that already happened. A payout *is* a business transaction — money leaves the club's bank account. A payout therefore needs **no `related_transaction_id`**; it needs a bank reference instead. | Requirement (different rule, not a softer one). |
| **3. Is a cashless internal ledger treated like a cash register?** | **No — for the TSE regime.** § 146a AO / KassenSichV reach "elektronische oder computergestützte Kassensysteme oder Registrierkassen", and Kassenfunktion requires "zumindest teilweise **baren** Zahlungsvorgängen". No cash → no TSE, no DSFinV-K, no Belegausgabepflicht, no § 146a Abs. 4 Mitteilung. Kassensturzfähigkeit and § 146 Abs. 1 S. 2 AO likewise attach to the Barkasse only. | Requirement (a scope limit, in our favour). |
| **…so the ledger is unregulated?** | **No.** Einzelaufzeichnungspflicht (§ 146 Abs. 1 S. 1 AO), Unveränderbarkeit (§ 146 Abs. 4 AO) and the **entire GoBD** apply in full regardless — § 146 Abs. 6 AO extends them even to records kept voluntarily. Nothing in §1 or §2 is relaxed by being cashless. | Requirement. |
| **⚠️ …with one live risk** | The same AEAO passage that excludes us extends Kassenfunktion to "**virtuelle (Kunden-)Konten**" and to value taken "**an Geldes statt vor Ort**", and says a cash drawer is *not* required. A **prepaid** member balance reads as in scope. Our post-paid tab is the better reading of out-of-scope — but that is an *interpretation*, not a quoted holding. | **Open. See §3.3.** |

---

## 1. Linkage — must a `Stornobuchung` be traceable to the original?

### 1.1 The operative sentence

**GoBD Rz. 64**, final sentence — this is the whole answer, and it was **added in the 2019 recast** (in the 2014 GoBD, the word "Storno" appeared only in Rz. 93, with no linkage requirement):

> **64** Zur Erfüllung der Belegfunktionen sind deshalb Angaben zur Kontierung, zum Ordnungskriterium für die Ablage und zum Buchungsdatum auf dem Papierbeleg erforderlich. Bei einem elektronischen Beleg kann dies auch durch die Verbindung mit einem Datensatz mit Angaben zur Kontierung oder durch eine **elektronische Verknüpfung (z. B. eindeutiger Index, Barcode)** erfolgen. Ein Steuerpflichtiger hat andernfalls durch organisatorische Maßnahmen sicherzustellen, dass die Geschäftsvorfälle auch ohne Angaben auf den Belegen in angemessener Zeit progressiv und retrograd nachprüfbar sind.
> **Korrektur- bzw. Stornobuchungen müssen auf die ursprüngliche Buchung rückbeziehbar sein.**

This is the *only* place in the 44-page circular that says it. A full-text scan for `Storn…` / `Korrektur` over the 2019 document returns exactly four hits: Rz. 64 (the sentence above), Rz. 93 (below), and Rz. 130/131 (OCR text correction — irrelevant).

### 1.2 What that sentence rests on

Rz. 64 is not free-standing administrative preference. It restates two statutory duties:

**§ 145 Abs. 1 AO** — [source](https://www.gesetze-im-internet.de/ao_1977/__145.html):
> (1) Die Buchführung muss so beschaffen sein, dass sie einem sachverständigen Dritten innerhalb angemessener Zeit einen Überblick über die Geschäftsvorfälle und über die Lage des Unternehmens vermitteln kann. **Die Geschäftsvorfälle müssen sich in ihrer Entstehung und Abwicklung verfolgen lassen.**

**§ 146 Abs. 4 AO** — [source](https://www.gesetze-im-internet.de/ao_1977/__146.html):
> (4) Eine Buchung oder eine Aufzeichnung darf nicht in einer Weise verändert werden, dass der ursprüngliche Inhalt nicht mehr feststellbar ist. Auch solche Veränderungen dürfen nicht vorgenommen werden, deren Beschaffenheit es ungewiss lässt, ob sie ursprünglich oder erst später gemacht worden sind.

The GoBD develops § 145 Abs. 1 S. 2 into the progressive/retrograde audit trail:

> **32** Die Buchführung muss so beschaffen sein, dass sie einem sachverständigen Dritten innerhalb angemessener Zeit einen Überblick über die Geschäftsvorfälle und über die Lage des Unternehmens vermitteln kann. Die einzelnen Geschäftsvorfälle müssen sich in ihrer Entstehung und Abwicklung **lückenlos** verfolgen lassen (progressive und retrograde Prüfbarkeit).
> **33** Die progressive Prüfung beginnt beim Beleg, geht über die Grund(buch)aufzeichnungen und Journale zu den Konten, danach zur Bilanz … Die retrograde Prüfung verläuft umgekehrt. Die progressive und retrograde Prüfung muss für die gesamte Dauer der Aufbewahrungsfrist und in jedem Verfahrensschritt möglich sein.

A reversal whose target cannot be identified breaks *lückenlos*. That is the mechanism: linkage is required because without it the chain has a gap, not because a rule anywhere says "store a foreign key".

### 1.3 Does "rückbeziehbar" mean a stored identifier?

Not literally — but the GoBD closes off the loose alternatives.

> **71** Die Zuordnung zwischen dem einzelnen Beleg und der dazugehörigen Grund(buch)aufzeichnung oder Buchung kann anhand von **eindeutigen Zuordnungsmerkmalen (z. B. Index, Paginiernummer, Dokumenten-ID)** … gewährleistet werden. Gehören zu einer Grund(buch)aufzeichnung oder Buchung mehrere Belege …, bedarf es zusätzlicher Zuordnungs- und Identifikationsmerkmale für die Verknüpfung …
> **72** Diese Zuordnungs- und Identifizierungsmerkmale aus dem Beleg **müssen bei der Aufzeichnung oder Verbuchung in die Bücher oder Aufzeichnungen übernommen werden**, um eine progressive und retrograde Prüfbarkeit zu ermöglichen.
> **73** Die Ablage der Belege und die Zuordnung zwischen Beleg und Aufzeichnung müssen in angemessener Zeit nachprüfbar sein. So kann z. B. **Beleg- oder Buchungsdatum, Kontoauszugnummer oder Name bei umfangreichem Beleganfall mangels Eindeutigkeit in der Regel kein geeignetes Zuordnungsmerkmal** für den einzelnen Geschäftsvorfall sein.

Reading 71–73 together with 64: the identifier must be (a) unique, (b) *carried in the record itself*, and (c) resolvable "in angemessener Zeit". Rz. 73 specifically rejects date and name — which is exactly what a treasurer would fall back on if the correction carried only a free-text reason like *"reversal of Müller's 3 beers on 12 March"*. In a bar ledger with thousands of rows, "umfangreicher Beleganfall" is plainly met.

**Conclusion**: a stored, unique, machine-resolvable reference is the only shape that conforms at our volume. Whether the column is called `related_transaction_id` is a free choice; whether *something* like it exists is not.

### 1.4 What Rz. 64 does *not* require — and where ADR-0004 is actually wrong

Rz. 64 attaches to **"Korrektur- bzw. Stornobuchungen"** — bookings that restate an earlier booking. It does not attach to a booking that is an economic event in its own right. GoBD Rz. 93 confirms this is the intended mechanism for fixing errors and that it is the *only* one needed:

> **93** Fehlerhafte Buchungen können wirksam und nachvollziehbar durch Stornierungen oder Neubuchungen geändert werden (siehe unter 8.). **Es besteht deshalb weder ein Bedarf noch die Notwendigkeit für weitere nachträgliche Veränderungen einer einmal erfolgten Buchung.**

ADR-0004 (`adr/0004-immutable-transaction-storage.md:247-258`) defines two shapes:

- **Scenario A** — member charged €5.00 instead of €3.50; `related_transaction_id = original_id`. This is a Stornobuchung. **Rz. 64 applies: the link is required.**
- **Scenario B** — `related_transaction_id = NULL`, `notes = 'Goodwill credit for service issue'`. A goodwill credit is not a restatement of any earlier booking; it is a fresh Geschäftsvorfall (the club forgoes a receivable). **Rz. 64 does not apply.** Scenario B is lawful as it stands, provided it satisfies Rz. 77 (§2.1 below) on its own.

So the ADR's *outcomes* are both defensible. The defect is that both are typed `transaction_type = 'correction'` with the linkage merely "Optional" (`adr/0004:87`). That makes the requirement unenforceable at the schema level: nothing distinguishes "a reversal that forgot its link" from "a standalone adjustment that legitimately has none". The two need distinct types (e.g. `reversal` vs `adjustment`), with the FK `NOT NULL` for the former.

### 1.5 What happens if we get it wrong

An untraceable correction is a **formeller Mangel** — the records no longer "entsprechen den Vorschriften der §§ 140 bis 148" (§ 158 Abs. 1 AO). It does **not** automatically trigger a Schätzung. AEAO zu § 158, as recast by [BMF v. 11.03.2024](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/AO-Anwendungserlass/2024-03-11-aenderung-des-anwendungserlasses-zur-abgabenordnung-AEAO.pdf?__blob=publicationFile&v=2):

> **2.** … Für die Annahme einer formellen Ordnungsmäßigkeit ist das **Gesamtbild aller Umstände des Einzelfalls** maßgebend. Die Buchführung und die Aufzeichnungen können **trotz einzelner formeller Mängel** aufgrund der Gesamtwertung insgesamt als formell ordnungsmäßig anzusehen sein. Eine Buchführung ist erst dann formell ordnungswidrig, wenn sie **wesentliche** Mängel aufweist oder die Gesamtheit aller (unwesentlichen) Mängel diesen Schluss fordert (BFH-Beschluss vom 2.12.2008, X B 69/08).
> **5.** … Das Buchführungsergebnis ist nicht zu übernehmen, **soweit die Beanstandungen reichen**. Eine **Vollschätzung** an Stelle einer Hinzuschätzung kommt nur dann in Betracht, wenn sich die Buchführung **in wesentlichen Teilen als unbrauchbar** erweist.

The realistic exposure is therefore proportional, not catastrophic. But note the shape of it: a *systematic* inability to trace corrections — which is what an optional column produces once the habit sets in — is precisely the kind of defect that stops being "einzeln" and becomes "wesentlich". § 162 Abs. 2 S. 2 AO then supplies the Schätzungsbefugnis.

Worth noting separately, because it is the other half of ADR-0004's justification and it checks out:

> **108** Das zum Einsatz kommende DV-Verfahren muss die Gewähr dafür bieten, dass alle Informationen …, die einmal in den Verarbeitungsprozess eingeführt werden (Beleg, Grundaufzeichnung, Buchung), **nicht mehr unterdrückt oder ohne Kenntlichmachung überschrieben, gelöscht, geändert oder verfälscht werden können.**
> **110** Die Unveränderbarkeit … kann … **softwaremäßig (z. B. Sicherungen, Sperren, Festschreibung, Löschmerker, automatische Protokollierung, Historisierungen, Versionierungen)** … gewährleistet werden. Die Ablage von Daten und elektronischen Dokumenten in einem Dateisystem erfüllt die Anforderungen der Unveränderbarkeit regelmäßig nicht …

An append-only table with linked reversals is a textbook Rz. 110 implementation. ADR-0004's core decision is sound; only the optionality of the link is not.

---

## 2. Payouts — what must accompany paying money back to a member?

### 2.1 The tax-law floor: every payout needs a Beleg with seven fields

> **61** Jeder Geschäftsvorfall ist urschriftlich bzw. als Kopie der Urschrift zu belegen. **Ist kein Fremdbeleg vorhanden, muss ein Eigenbeleg erstellt werden.** Zweck der Belege ist es, den sicheren und klaren Nachweis über den Zusammenhang zwischen den Vorgängen in der Realität einerseits und dem aufgezeichneten oder gebuchten Inhalt … zu erbringen (Belegfunktion). … Die Belegfunktion ist die Grundvoraussetzung für die Beweiskraft der Buchführung … **Sie gilt auch bei Einsatz eines DV-Systems.**

> **77** Jedem Geschäftsvorfall muss ein Beleg zugrunde liegen, mit folgenden Inhalten:

| Bezeichnung | Begründung (GoBD) |
|---|---|
| **Eindeutige Belegnummer** (z. B. Index, Paginiernummer, Dokumenten-ID, fortlaufende Rechnungsausgangsnummer) | Angabe **zwingend** (§ 146 Abs. 1 S. 1 AO, einzeln, vollständig, geordnet). Kriterium für Vollständigkeitskontrolle. |
| Belegaussteller und -empfänger | Soweit branchenübliche Mindestaufzeichnungspflicht |
| **Betrag** bzw. Mengen-/Wertangaben | Angabe **zwingend** (BFH v. 12.5.1966, BStBl III S. 371) |
| Währungsangabe / Wechselkurs bei Fremdwährung | — |
| **Hinreichende Erläuterung des Geschäftsvorfalls** | BFH v. 12.5.1966, BStBl III S. 371; BFH v. 1.10.1969, BStBl II 1970 S. 45 |
| **Belegdatum** | Angabe **zwingend** (§ 146 Abs. 1 S. 1 AO, zeitgerecht) |
| Verantwortlicher Aussteller, soweit vorhanden | z. B. Bediener der Kasse |

For a bank-transfer payout the **Kontoauszug is the Fremdbeleg**, so no Eigenbeleg is strictly required *provided* the `payout` row and the bank line can be tied together. That tie is not optional — Rz. 71–73 govern it, and Rz. 73 expressly warns that **Kontoauszugsnummer alone is generally not a sufficient Zuordnungsmerkmal**. Practically: the payout row must carry a bank reference (EndToEndId / a Verwendungszweck containing the payout's own ID), and the same string must appear on the transfer.

This dovetails with the ruling already recorded in [#149](https://github.com/dgloeckner/ruderbar/issues/149) — a stable, persisted EndToEndId. That decision was made for SEPA-return matching; Rz. 71–73 make it a bookkeeping requirement as well, and for outbound payouts too, not just collections.

### 2.2 Retention: 8 years, but do not hard-delete at 8

**§ 147 Abs. 3 AO** (current, after the Viertes Bürokratieentlastungsgesetz) — [source](https://www.gesetze-im-internet.de/ao_1977/__147.html):
> (3) Die in Absatz 1 **Nummer 1 und 4a** aufgeführten Unterlagen sind **zehn Jahre**, die in Absatz 1 **Nummer 4** [Buchungsbelege] aufgeführten Unterlagen **acht Jahre** und die sonstigen … **sechs Jahre** aufzubewahren … **Die Aufbewahrungsfrist läuft jedoch nicht ab, soweit und solange die Unterlagen für Steuern von Bedeutung sind, für welche die Festsetzungsfrist noch nicht abgelaufen ist**; § 169 Absatz 2 Satz 2 gilt nicht.
> (4) Die Aufbewahrungsfrist beginnt **mit dem Schluss des Kalenderjahrs**, in dem … der Buchungsbeleg entstanden ist …

Per **Art. 97 § 19a Abs. 2 EGAO** the 8-year rule applies from **1 January 2025**, retroactively to every Buchungsbeleg whose 10-year period had not yet expired on 31.12.2024.

Two consequences for the code: (a) 8 years is the number for a payout Beleg, 10 for the ledger itself (Nr. 1: "Bücher und Aufzeichnungen"); (b) Satz 5 means the clock can be suspended, so **an automated purge at exactly 8 years is unsafe** — retention must be a floor with a manual hold, not a cron job. This interacts with the GDPR anonymisation workflow and is worth surfacing there.

### 2.3 The Vereinsrecht floor

**§ 27 Abs. 3 S. 1 BGB** → **§ 666 BGB** (Rechenschaft ablegen) → **§ 259 Abs. 1 BGB**:

> (1) Wer verpflichtet ist, über eine mit Einnahmen oder Ausgaben verbundene Verwaltung Rechenschaft abzulegen, hat dem Berechtigten eine **die geordnete Zusammenstellung der Einnahmen oder der Ausgaben enthaltende Rechnung** mitzuteilen und, **soweit Belege erteilt zu werden pflegen**, Belege vorzulegen.

Two duties with different strength: the *geordnete Zusammenstellung* is unconditional; *Belegvorlage* is qualified by a Verkehrsüblichkeits-Vorbehalt. Note also the **Saldierungsverbot** that follows from "geordnet" — income and expenditure may not be netted against one another. That is an argument *against* ever representing a payout as a negative purchase, and *for* the separate `payout` row that [#141](https://github.com/dgloeckner/ruderbar/issues/141) §4 already specifies.

And for gemeinnützigkeit, **§ 63 Abs. 3 AO**:
> (3) Die Körperschaft hat den Nachweis, dass ihre tatsächliche Geschäftsführung den Erfordernissen des Absatzes 1 entspricht, durch **ordnungsmäßige Aufzeichnungen über ihre Einnahmen und Ausgaben** zu führen.

### 2.4 What is NOT required — say this plainly

| Control | Status |
|---|---|
| Countersignature / Vier-Augen-Prinzip on a payout | **No statutory basis.** Satzung / Geschäftsordnung only. |
| Vorstandsbeschluss per payout | **No statutory basis.** |
| Kassenprüfer / Rechnungsprüfer reviewing payouts | **No statutory basis whatsoever** — the BGB does not know the office. Purely satzungsbasiert. |

On the last point, [IWW VereinsBrief, 03.04.2023](https://www.iww.de/vb/vereinsrecht/vereinsrecht-fakultative-vereinsorgane-teil-3-der-rechnungspruefer-f152603):
> **Das Vereinsrecht kennt keine allgemeine Pflichtprüfung der Vermögensverwaltung des Vorstands in der Form einer jährlichen Rechnungsprüfung. Die Satzung kann aber vorsehen, dass sie regelmäßig erfolgt** …

and the [Württembergischer Landessportbund](https://www.wlsb-infothek.de/vereinsmanagement/recht/vereinsrecht/die-satzung/die-kassenpruefung):
> **Das Vereinsrecht sieht keine gesetzlichen Regelungen zur Überprüfung der Finanzen des Vereins vor. Auch aus handels- und steuerrechtlicher Sicht ergibt sich für Vereine keine Notwendigkeit, die Finanzen intern prüfen zu lassen.**

Even the sports-federation best-practice guidance frames the countersignature as advice, not law — [VIBSS / Landessportbund NRW, "Buchführung – Eigenbeleg"](https://www.vibss.de/vereinsmanagement/steuern-finanzen/buchfuehrung/die-buchfuehrung/buchfuehrung-eigenbeleg):
> Dieser Eigenbeleg **sollte** vom Kassierer angefertigt und vom Vorsitzenden abgezeichnet werden.

The whole § 27 Abs. 3 regime is in any case **nachgiebiges Recht** — § 40 S. 1 BGB lets the Satzung override § 27 Abs. 1 und 3. So a club that *wants* a four-eyes rule writes it into its Satzung or Geschäftsordnung; the software may offer it, but must not present it as a legal requirement.

One genuine adjacency: a payout **to a Vorstandsmitglied** must be documented well enough to show it is echter Aufwendungsersatz (§ 670 BGB) rather than disguised Vergütung, since § 27 Abs. 3 S. 2 BGB makes the office unentgeltlich and § 55 AO makes Mittelfehlverwendung a gemeinnützigkeit risk. That is a *content* requirement on the "hinreichende Erläuterung" field, not a workflow requirement.

### 2.5 Payout ≠ correction

| | Correction / reversal | Payout |
|---|---|---|
| What it is | A restatement of an already-recorded Geschäftsvorfall | A Geschäftsvorfall in its own right — money leaves the bank account |
| Governing rule | § 146 Abs. 4 AO, GoBD Rz. 64, 93, 107–112 | GoBD Rz. 61, 71–73, 77; § 259 BGB; § 63 Abs. 3 AO |
| Must reference the original booking | **Yes** (when reversing a specific one) | **No** — there is no "original" |
| Must reference an external document | No | **Yes** — the bank transfer (Fremdbeleg) |
| Beleg content (Rz. 77) | Required | Required |

So `related_transaction_id` should be **NULL for payouts by construction**, not merely unfilled. A payout that carries one is expressing something the domain does not mean.

---

## 3. Cash register vs. internal member ledger

### 3.1 The TSE regime does not reach us

**§ 146a Abs. 1 AO** — [source](https://www.gesetze-im-internet.de/ao_1977/__146a.html):
> (1) ¹Wer aufzeichnungspflichtige Geschäftsvorfälle oder andere Vorgänge **mit Hilfe eines elektronischen Aufzeichnungssystems** erfasst, hat ein elektronisches Aufzeichnungssystem zu verwenden, das jeden … Geschäftsvorfall … einzeln, vollständig, richtig, zeitgerecht und geordnet aufzeichnet. ²Das elektronische Aufzeichnungssystem und die digitalen Aufzeichnungen nach Satz 1 sind durch eine **zertifizierte technische Sicherheitseinrichtung** zu schützen.

The scope gate is delegated to **§ 1 Abs. 1 KassenSichV** — [source](https://www.gesetze-im-internet.de/kassensichv/__1.html):
> **(1) Elektronische Aufzeichnungssysteme im Sinne des § 146a Absatz 1 Satz 1 der Abgabenordnung sind elektronische oder computergestützte Kassensysteme oder Registrierkassen.** Nicht als elektronische Aufzeichnungssysteme gelten 1. Fahrscheinautomaten …, 3. **elektronische Buchhaltungsprogramme**, 4. Waren- und Dienstleistungsautomaten, 5. Geldautomaten sowie 6. Geld- und Warenspielgeräte.

and defined by **AEAO zu § 146a Nr. 1.2** ([BMF v. 30.06.2023](https://lfst.rlp.de/fileadmin/lfst.rlp.de/Service/Unternehmer/BMF___146a_AO_BMF-Schreiben_2023-06-30.pdf), S. 4 — verified verbatim against the PDF):

> Die in § 1 Abs. 1 Satz 1 KassenSichV genannten „elektronischen oder computergestützten Kassensysteme oder Registrierkassen" sind für den Verkauf von Waren oder die Erbringung von Dienstleistungen und deren Abrechnung spezialisierte elektronische Aufzeichnungssysteme, die „**Kassenfunktion**" haben.
>
> **Kassenfunktion haben elektronische Aufzeichnungssysteme dann, wenn diese der Erfassung und Abwicklung von zumindest teilweise baren Zahlungsvorgängen dienen können.** Dies gilt auch für vergleichbare elektronische, vor Ort genutzte Zahlungsformen (elektronisches Geld wie z. B. Geldkarte oder **virtuelle (Kunden-)Konten**) sowie **an Geldes statt vor Ort angenommener** Gutscheine, Guthabenkarten, Bons und dergleichen.
>
> Eine Aufbewahrungsmöglichkeit des verwalteten Bargeldbestandes (z. B. Kassenlade) ist nicht erforderlich.

A system that can never handle a bare Zahlungsvorgang has no Kassenfunktion. Consequences, all falling away together: **no TSE**, no DSFinV-K export, **no Belegausgabepflicht** (§ 146a Abs. 2 AO, whose duty-bearer is per AEAO Nr. 2.5.1 "derjenige, der Geschäftsvorfälle mit Hilfe eines elektronischen Aufzeichnungssystems i. S. d. § 146a Abs. 1 Satz 1 AO erfasst"), **no § 146a Abs. 4 Mitteilung** to the Finanzamt, and no § 6 KassenSichV receipt (which is anyway unproducible without a TSE — items 6 and 7 are the TSE serial number and the signature counter).

### 3.2 Kassensturzfähigkeit is about counting banknotes

**§ 146 Abs. 1 AO**:
> (1) Die Buchungen und die sonst erforderlichen Aufzeichnungen sind einzeln, vollständig, richtig, zeitgerecht und geordnet vorzunehmen. **Kasseneinnahmen und Kassenausgaben sind täglich festzuhalten.** …

(Note: since the Kassengesetz of 29.12.2016 Satz 2 is imperative — "sind … festzuhalten", not the older "sollen".)

That duty is about the Barkasse. GoBD **Rz. 55** treats bare and unbare Vorgänge as belonging in different places, with Kassensturzfähigkeit as the constraint on the cash side only:

> **55** In der Regel verstößt die nicht getrennte Verbuchung von baren und unbaren Geschäftsvorfällen … gegen die Grundsätze der Wahrheit und Klarheit … Eine kurzzeitige gemeinsame Erfassung … ist regelmäßig nicht zu beanstanden, wenn die ursprünglich im Kassenbuch erfassten unbaren Tagesumsätze … gesondert kenntlich gemacht sind und nachvollziehbar unmittelbar nachfolgend wieder aus dem Kassenbuch auf ein gesondertes Konto aus- bzw. umgetragen werden, **soweit die Kassensturzfähigkeit der Kasse weiterhin gegeben ist**.

[Senatsverwaltung für Finanzen Berlin, *Merkblatt zur Ordnungsmäßigkeit der Kassen(buch)führung*, Stand Februar 2025](https://www.berlin.de/sen/finanzen/steuern/informationen-fuer-steuerzahler-/merkblatt-ordnungsmaessigkeit-der-kassenbuchfuehrung2.pdf), S. 4:
> **Nur Barumsätze sind im Kassenbuch zu erfassen. Unbare Zahlungen (Kreditkarte/ EC-Umsätze etc.) sind separat abzubilden.**

and [Finanzministerium Mecklenburg-Vorpommern, *Merkblatt Ordnungsmäßigkeit der Kassenführung … ab 01.01.2025*](https://www.steuerportal-mv.de/static/Regierungsportal/Finanzministerium/Steuerportal/Dateien/Downloads/Merkblatt%20Ordnungsm%C3%A4%C3%9Figkeit%20der%20Kassenf%C3%BChrung%20-%20Aufzeichnungs-%20und%20Aufbewahrungspflichten%20ab%2001.01.2025.pdf), S. 8, on the Kassen-Nachschau:
> Die Amtsträger können zusätzlich verlangen, dass der **gesamte betriebliche Bargeldbestand ausgezählt** wird (sog. „**Kassensturz**").

You cannot count a receivable. No Kasse → no Kassenbuch, no daily Festhalten, no Kassensturzfähigkeit.

### 3.3 ⚠️ The one live risk: prepaid vs. post-paid

Re-read the AEAO passage in §3.1. It extends Kassenfunktion to "**virtuelle (Kunden-)Konten**" and to value taken "**an Geldes statt vor Ort**", and expressly says a cash drawer is not required. That wording is aimed at exactly the design pattern of a member card with a stored balance.

- A **prepaid** member account — member loads credit, drinks draw it down — reads squarely as a "virtuelles (Kunden-)Konto" and is, on this wording, **in scope**: TSE, Belegausgabepflicht, DSFinV-K, § 146a Abs. 4 Mitteilung.
- Our **post-paid tab** — the member incurs a `Forderung des Vereins`, nothing is tendered at the point of sale, settlement happens later over the bank account by SEPA-Lastschrift — is on the same wording arguably **not** in scope, because nothing is accepted "an Geldes statt vor Ort".

**I could not find any BMF-Schreiben, Länder-Merkblatt, or BFH decision stating in terms that a post-paid Kunden-/Debitorenkonto is a Forderung and therefore outside § 146a AO.** That proposition is a reading of the AEAO wording, not a sourced holding, and it should not be repeated as though it were one. It is the single point worth putting to a Steuerberater before launch.

Design consequence, and it is a real constraint on the roadmap: **never let a member top up a balance at the terminal.** The moment the ledger can hold prepaid credit taken on site, the out-of-scope argument weakens sharply. Note that this cuts *with* the exclude-and-flag ruling in [#140](https://github.com/dgloeckner/ruderbar/issues/140): a credit balance arising from a *correction* is a residual receivable turned negative, not money the member handed over — a different thing from a top-up, and worth keeping different in the UI language too.

### 3.4 What still applies, cashless or not

Being out of TSE scope buys nothing on the GoBD side. **AEAO zu § 146a Nr. 2.1.1**:
> Der sachliche Anwendungsbereich der Pflicht zum Einsatz einer TSE wird durch § 146a Abs. 1 Satz 2 AO i. V. m. § 1 Abs. 1 KassenSichV **begrenzt** … **Unabhängig davon unterliegen jedoch alle elektronischen Aufzeichnungssysteme der Einzelaufzeichnungspflicht nach § 146 Abs. 1 Satz 1 AO.**

And **§ 146 Abs. 6 AO** closes the last escape:
> (6) Die Ordnungsvorschriften gelten auch dann, wenn der Unternehmer Bücher und Aufzeichnungen, die für die Besteuerung von Bedeutung sind, führt, **ohne hierzu verpflichtet zu sein.**

So even though the club is almost certainly **not buchführungspflichtig** — § 141 AO now bites only above **800.000 € Umsatz / 80.000 € Gewinn** ([source](https://www.gesetze-im-internet.de/ao_1977/__141.html), thresholds raised by the Wachstumschancengesetz, applicable to periods beginning after 31.12.2023 per Art. 97 § 19 Abs. 3/4 EGAO) *and* only after a constitutive Mitteilung by the Finanzamt (§ 141 Abs. 2 AO) — and even though the *form* may therefore be a plain EÜR with geordnete Belegablage (§ 4 Abs. 3 EStG; § 146 Abs. 5 S. 1 AO expressly permits "die geordnete Ablage von Belegen"), **§§ 145–147 AO and the GoBD apply in full to the records that are kept.** There is no GoBD-free zone for an EÜR. GoBD Rz. 115 confirms the retention side for EÜR taxpayers explicitly, citing BFH v. 24.6.2009, BStBl II 2010 S. 452.

One genuine relief exists and is worth knowing about, though we should not lean on it — GoBD **Rz. 15**:
> Bei Kleinstunternehmen, die ihren Gewinn durch Einnahmen-Überschussrechnung ermitteln (bis 17.500 Euro Jahresumsatz), ist die Erfüllung der Anforderungen an die Aufzeichnungen nach den GoBD **regelmäßig auch mit Blick auf die Unternehmensgröße zu bewerten**.

A proportionality clause, not an exemption — and one that evaporates the moment the bar's turnover grows. Building to it would be building a ceiling.

---

## 4. Direct implications for the open decisions

1. **`related_transaction_id` must be mandatory whenever a specific transaction is reversed.** GoBD Rz. 64 is a requirement, not a convention. ADR-0004's "Optional" is below the floor for Scenario A.
2. **Scenario B survives, but must stop being called a correction.** A goodwill credit is an own Geschäftsvorfall; Rz. 64 does not reach it. Split the type (`reversal` vs `adjustment`) so the FK can be `NOT NULL` on the former — an optional column cannot express the rule.
3. **Both shapes need the Rz. 77 fields**, and two of ours are weak: "hinreichende Erläuterung des Geschäftsvorfalls" and "verantwortlicher Aussteller". The free-text `notes` field must be non-empty for every non-purchase row, and `created_by_admin_id` must be populated (which #144's ruling already forces to NULL on the *sync* path — correctly, since a terminal purchase has no admin author, but it must be present on every admin-created correction).
4. **Payouts get a bank reference, not a transaction link** — and `related_transaction_id` should be NULL for them by construction. #149's persisted EndToEndId ([#150](https://github.com/dgloeckner/ruderbar/issues/150)) is a GoBD Rz. 71–73 requirement for outbound payouts too, not only for return matching.
5. **No countersignature, no four-eyes, no Vorstandsbeschluss is legally required.** If the product offers them, they are a configurable club policy, not a compliance feature. Do not let the UI claim otherwise.
6. **Retention is 8 years for Belege / 10 for the ledger, with a suspension clause.** Any automated purge or GDPR-anonymisation job must treat these as floors with a hold, never as a deletion trigger.
7. **Keep the tab strictly post-paid.** A terminal-side top-up would plausibly pull the whole system into § 146a AO. This is the strongest architectural constraint the law places on us, and it is currently satisfied only implicitly.
8. **Not affected by any of this**: Kassenbuch, Kassensturzfähigkeit, TSE, DSFinV-K, Belegausgabepflicht. Cashless is a real and defensible scope exit for the TSE regime — just not for the GoBD.

---

## 5. Sources

**Statute** (all `gesetze-im-internet.de`): [§ 140 AO](https://www.gesetze-im-internet.de/ao_1977/__140.html) · [§ 141 AO](https://www.gesetze-im-internet.de/ao_1977/__141.html) · [§ 145 AO](https://www.gesetze-im-internet.de/ao_1977/__145.html) · [§ 146 AO](https://www.gesetze-im-internet.de/ao_1977/__146.html) · [§ 146a AO](https://www.gesetze-im-internet.de/ao_1977/__146a.html) · [§ 147 AO](https://www.gesetze-im-internet.de/ao_1977/__147.html) · [§ 158 AO](https://www.gesetze-im-internet.de/ao_1977/__158.html) · [§ 162 AO](https://www.gesetze-im-internet.de/ao_1977/__162.html) · [§ 63 AO](https://www.gesetze-im-internet.de/ao_1977/__63.html) · [§ 64 AO](https://www.gesetze-im-internet.de/ao_1977/__64.html) · [Art. 97 § 19 EGAO](https://www.gesetze-im-internet.de/aoeg_1977/art_97__19.html) · [Art. 97 § 19a EGAO](https://www.gesetze-im-internet.de/aoeg_1977/art_97__19a.html) · [§ 1 KassenSichV](https://www.gesetze-im-internet.de/kassensichv/__1.html) · [§ 6 KassenSichV](https://www.gesetze-im-internet.de/kassensichv/__6.html) · [§ 27 BGB](https://www.gesetze-im-internet.de/bgb/__27.html) · [§ 259 BGB](https://www.gesetze-im-internet.de/bgb/__259.html) · [§ 666 BGB](https://www.gesetze-im-internet.de/bgb/__666.html) · [§ 670 BGB](https://www.gesetze-im-internet.de/bgb/__670.html) · [§ 40 BGB](https://www.gesetze-im-internet.de/bgb/__40.html) · [§ 4 EStG](https://www.gesetze-im-internet.de/estg/__4.html)

**BMF-Schreiben**: [GoBD, 28.11.2019, IV A 4 - S 0316/19/10003 :001](https://www.ihk-muenchen.de/ihk/documents/Recht-Steuern/Steuerrecht/2019-11-28-GoBD.pdf) · [1. Änderung, 11.03.2024](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/AO-Anwendungserlass/2024-03-11-aenderung-gobd.pdf?__blob=publicationFile&v=2) · [2. Änderung, 14.07.2025](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.pdf?__blob=publicationFile&v=3) · [AEAO zu § 146a, 30.06.2023, IV D 2 - S 0316-a/20/10003 :006](https://lfst.rlp.de/fileadmin/lfst.rlp.de/Service/Unternehmer/BMF___146a_AO_BMF-Schreiben_2023-06-30.pdf) · [AEAO-Änderung 11.03.2024 (zu § 158)](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/AO-Anwendungserlass/2024-03-11-aenderung-des-anwendungserlasses-zur-abgabenordnung-AEAO.pdf?__blob=publicationFile&v=2)

**Länder-Merkblätter**: [Berlin, Ordnungsmäßigkeit der Kassen(buch)führung, Feb. 2025](https://www.berlin.de/sen/finanzen/steuern/informationen-fuer-steuerzahler-/merkblatt-ordnungsmaessigkeit-der-kassenbuchfuehrung2.pdf) · [Mecklenburg-Vorpommern, ab 01.01.2025](https://www.steuerportal-mv.de/static/Regierungsportal/Finanzministerium/Steuerportal/Dateien/Downloads/Merkblatt%20Ordnungsm%C3%A4%C3%9Figkeit%20der%20Kassenf%C3%BChrung%20-%20Aufzeichnungs-%20und%20Aufbewahrungspflichten%20ab%2001.01.2025.pdf)

**Vereinsrecht commentary**: [IWW VereinsBrief, Rechnungsprüfer, 03.04.2023](https://www.iww.de/vb/vereinsrecht/vereinsrecht-fakultative-vereinsorgane-teil-3-der-rechnungspruefer-f152603) · [WLSB Infothek, Kassenprüfung](https://www.wlsb-infothek.de/vereinsmanagement/recht/vereinsrecht/die-satzung/die-kassenpruefung) · [VIBSS/LSB NRW, Eigenbeleg](https://www.vibss.de/vereinsmanagement/steuern-finanzen/buchfuehrung/die-buchfuehrung/buchfuehrung-eigenbeleg) · [Pfeffer, *Buchführung, Jahresabschluss und Steuererklärung im Verein*, vereinsknowhow.de](https://www.vereinsknowhow.de/e-books/buchfuehrung.pdf) (note: predates both the Wachstumschancengesetz and BEG IV — its 600.000/60.000 and 10-year figures are stale; used only for structural statements)

## 6. Explicitly not found

Stated so nobody later mistakes silence for support:

1. **No source** states that a post-paid Kunden-/Debitorenkonto is a Forderung and therefore outside § 146a AO. The AEAO's "virtuelle (Kunden-)Konten" wording actively cuts the other way for prepaid balances. This is the load-bearing gap (§3.3).
2. **No statute, BMF-Schreiben, or court decision** imposes a Vier-Augen-Prinzip, countersignature, or Vorstandsbeschluss on a Vereins-Auszahlung. Anyone asserting otherwise should be asked for the citation.
3. **BFH VIII R 174/77** (the classic Kassensturzfähigkeit holding) was verified only in secondary form and is therefore not quoted verbatim above; the §3.2 conclusion rests instead on GoBD Rz. 55 and the two Länder-Merkblätter.
4. **Großkommentar text on § 259 BGB** (MüKoBGB, Grüneberg) is paywalled; the reading of "soweit Belege erteilt zu werden pflegen" rests on the statutory wording alone.
