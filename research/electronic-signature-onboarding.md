# Research: Signing the onboarding form in the browser — which simple electronic signatures hold, and what the club must be able to prove

**Branch:** `claude/digital-signature-onboarding-m1h500` (no issue yet — this document is the M0 of a possible epic)
**Date:** 2026-09-02
**Companions:** `research/175-onboarding-form-datenschutz.md` (Art. 13, legal bases, mandate-vs-consent — not repeated here), [ADR-0028](../adr/0028-legal-constraints-on-money-handling.md) §3 (return windows), [ADR-0052](../adr/0052-member-self-registration-via-qr-code.md) (the paper flow this would extend)
**Design derived from it:** [ADR-0053](../adr/0053-electronic-mandate-signature.md) (Proposed)

> ⚠️ Not legal advice. Confidence stated per finding. **NO AUTHORITY FOUND** = looked for, not found.
>
> **Method note.** The sandbox's egress policy denied every primary legal host
> (gesetze-im-internet.de, eur-lex, bundesbank.de, europeanpaymentscouncil.eu,
> dejure/openjur, every bank PDF). Statute text below marked *verbatim* comes
> from the `bundestag/gesetze` and `legalize-dev/legalize-eu` GitHub mirrors and
> Stripe's public OpenAPI file, which were reachable; everything marked
> *(excerpt)* was seen only in search-engine excerpts, sometimes as an English
> rendering of a German source. Re-pull an excerpt before quoting it to a bank
> or a lawyer. The list of what was fetched in full is at the end.

---

## Headline: the question, and how the working hypotheses fared

The question was: can the applicant sign the onboarding form — Aufnahmeantrag,
SEPA-Basislastschriftmandat, Kenntnisnahme of the Datenschutzhinweise — on
their phone, without a commercial e-signature or e-mandate provider, in a way
that is *rechtssicher*?

| Hypothesis | Verdict |
|---|---|
| None of the three declarations carries a **statutory** form requirement, so a simple electronic signature is legally *valid* | ✅ **Holds.** Beitritt: form set by the Satzung, not the law (§ 58 Nr. 1 BGB); a Satzung "schriftlich" is satisfied by telecommunicative transmission (§ 127 Abs. 2 BGB, four OLGs). Mandate: "no special legal requirements" (Bundesbank), the scheme "does not prescribe nor limit the methods of signing" (EPC132-17). Art. 13: an information duty, nothing to sign. See §§2–4. |
| A qualified electronic signature (QES) is what "rechtssicher" requires | ❌ **Does not hold — with one exception.** QES is needed only to *replace* a statutory Schriftform (§ 126a BGB) and it alone earns the § 371a ZPO Anscheinsbeweis; neither applies here. The exception: a *separately signed Empfangsbekenntnis* escapes § 309 Nr. 12 b BGB only if handwritten or QES-signed — which is one more reason not to collect a Kenntnisnahme checkbox at all. See §4.3. |
| "Rechtssicher" for the mandate is decided by a court | ❌ **Does not hold.** It is decided by **the club's own bank**, as "erste Inkassostelle" under the Inkassovereinbarung (Bundesbank), and, in a dispute, by the *member's* bank reading a mandate copy the club must produce within seven business days. No German court decision on a disputed online SEPA mandate exists. For Frankfurter Sparkasse the readable editions of the Sparkassen form say „schriftliche und vom Zahlungspflichtigen unterzeichnete", while the group's own PSP runs click mandates — the bank has to be asked, and §3.5 says how. |
| What a simple signature lacks is *integrity* — so a drawn signature image or a hash chain is the fix | ❌ **Wrong target.** What it lacks is **identity**: no Anscheinsbeweis flows from a mailbox, an account or a password (BGH VIII ZR 289/09, OLG Köln, LG Bonn); AG München dismissed a claim for want of an attributable IP, an authentication step and a protected link. A drawn scribble on a phone is legally the same as a typed name (Bundesarchiv). The fix is a **confirmation from the mailbox on file** plus a **complete, printable record of the process** — exactly the two things courts asked for (BGH I ZR 164/09; VG Düsseldorf 29 K 9714/24). See §5. |
| Minors need a parent's handwritten signature | ❌ **Does not hold in law, holds in practice.** The parent's consent is form-free even where the Satzung requires Schriftform (§ 182 Abs. 2 BGB); one parent suffices for the ordinary case (AG Ahlen, § 1357 BGB). But a browser cannot tell a parent from a child, so the minor path stays on paper in v1. See §6. |

**The one thing that changed my prior:** I expected the Kenntnisnahme checkbox
to be harmless boilerplate. BGH III ZR 368/13 holds that a pre-formulated,
mandatory "zur Kenntnis genommen" checkbox is an invalid Tatsachenbestätigung
under § 309 Nr. 12 b BGB, and the statutory exception names *handwritten or
qualified* signatures only. The current design — link the notice, log when it
was shown, no checkbox — is therefore not merely tidy, it is the legally
stronger shape. It stays.

---

## 1. What "digital signature" means in law

### 1.1 eIDAS (Reg. (EU) 910/2014, as amended by 2024/1183)

Art. 3 (10)–(12), verbatim from the consolidated text (EN):

> (10) 'electronic signature' means data in electronic form which is attached to or logically associated with other data in electronic form and which is used by the signatory to sign;
> (11) 'advanced electronic signature' means an electronic signature which meets the requirements set out in Article 26;
> (12) 'qualified electronic signature' means an advanced electronic signature that is created by a qualified electronic signature creation device, and which is based on a qualified certificate for electronic signatures;

Art. 26 (advanced signature), verbatim:

> (a) it is uniquely linked to the signatory; (b) it is capable of identifying the signatory; (c) it is created using electronic signature creation data that the signatory can, with a high level of confidence, use under his sole control; and (d) it is linked to the data signed therewith in such a way that any subsequent change in the data is detectable.

Art. 25 (excerpt, German; identical across three sources):

> (1) Einer elektronischen Signatur darf die Rechtswirkung und die Zulässigkeit als Beweismittel in Gerichtsverfahren nicht allein deshalb abgesprochen werden, weil sie in elektronischer Form vorliegt oder weil sie die Anforderungen an qualifizierte elektronische Signaturen nicht erfüllt.
> (2) Eine qualifizierte elektronische Signatur hat die gleiche Rechtswirkung wie eine handschriftliche Unterschrift.

Art. 41 (time stamps, excerpt): a non-qualified electronic time stamp may not be
denied legal effect for being electronic; a **qualified** one carries a
presumption of the correctness of date and time and of the integrity of the
data bound to it.

**Confidence: Very High** (Art. 3, 26 verbatim); **High** (Art. 25, 41).

**What it means for us.** A typed name, a checkbox bound to a document, or a
finger-drawn scribble is already an *electronic signature* — a "simple" one
(SES). Art. 25(1) guarantees it a hearing; it says nothing about *how much* it
proves. Only Art. 26 lists functional criteria for "advanced", and no word in it
requires a certificate (§7.1). Only QES equals handwriting.

### 1.2 German form law and evidence law

Verbatim from the BGB/ZPO mirror (sections unchanged in the relevant period):

| Norm | Text | Bearing |
|---|---|---|
| **§ 126 Abs. 1 BGB** | „Ist durch Gesetz schriftliche Form vorgeschrieben, so muss die Urkunde von dem Aussteller eigenhändig durch Namensunterschrift … unterzeichnet werden." | Bites only where a **law** prescribes Schriftform |
| **§ 126a Abs. 1** | „Soll die gesetzlich vorgeschriebene schriftliche Form durch die elektronische Form ersetzt werden, so muss der Aussteller … das elektronische Dokument mit einer qualifizierten elektronischen Signatur versehen." | QES is the electronic *Schriftform*; irrelevant where no law demands Schriftform |
| **§ 126b** | „Ist durch Gesetz Textform vorgeschrieben, so muss eine lesbare Erklärung, in der die Person des Erklärenden genannt ist, auf einem dauerhaften Datenträger abgegeben werden." | A web form plus a mail the recipient can keep is Textform |
| **§ 127 Abs. 2** | „Zur Wahrung der durch Rechtsgeschäft bestimmten schriftlichen Form genügt, soweit nicht ein anderer Wille anzunehmen ist, die telekommunikative Übermittlung und bei einem Vertrag der Briefwechsel. Wird eine solche Form gewählt, so kann nachträglich eine dem § 126 entsprechende Beurkundung verlangt werden." | **The key norm for the Satzung.** A form the *Satzung* prescribes is a form "durch Rechtsgeschäft bestimmt" |
| **§ 127 Abs. 3** | „Zur Wahrung der durch Rechtsgeschäft bestimmten elektronischen Form genügt, soweit nicht ein anderer Wille anzunehmen ist, auch eine andere als die in § 126a bestimmte elektronische Signatur …" | A Satzung saying "in elektronischer Form" is met by *any* electronic signature |
| **§ 286 Abs. 1 ZPO** | „Das Gericht hat … nach freier Überzeugung zu entscheiden, ob eine tatsächliche Behauptung für wahr oder für nicht wahr zu erachten sei." | Where every SES lives: free evaluation |
| **§ 371 Abs. 1 S. 2 ZPO** | „Ist ein elektronisches Dokument Gegenstand des Beweises, wird der Beweis durch Vorlegung oder Übermittlung der Datei angetreten." | An SES document is an *Augenscheinsobjekt*, not an Urkunde |
| **§ 371a Abs. 1 ZPO** | „Auf private elektronische Dokumente, die mit einer qualifizierten elektronischen Signatur versehen sind, finden die Vorschriften über die Beweiskraft privater Urkunden entsprechende Anwendung. Der Anschein der Echtheit … kann nur durch Tatsachen erschüttert werden, die ernstliche Zweifel daran begründen …" | The Anscheinsbeweis is **QES-only** |
| **§ 416 ZPO** | „Privaturkunden begründen, sofern sie von den Ausstellern unterschrieben … sind, vollen Beweis dafür, dass die in ihnen enthaltenen Erklärungen von den Ausstellern abgegeben sind." | What the paper mandate has and the SES does not |
| **§ 309 Nr. 12 BGB** | AGB clause invalid that changes the burden of proof, „insbesondere indem er … b) den anderen Vertragsteil bestimmte Tatsachen bestätigen lässt; Buchstabe b gilt nicht für Empfangsbekenntnisse, die gesondert unterschrieben oder mit einer gesonderten qualifizierten elektronischen Signatur versehen sind" | See §4.3 |

**Confidence: Very High** (wording), **High** that it is current.

The Bundesarchiv's guidance on scanned signatures (*Scanprodukte als
Beweismittel*, Stand März 2026, excerpt) draws the practical line: a document
carrying a scanned or drawn signature is not an Urkunde but an object of
Augenschein weighed freely under § 286; § 416 attaches automatically only to a
handwritten signature on paper. **Confidence: High.**

**What it means for us.** Two consequences drive the whole design. First, *validity*
is not the problem: no statute prescribes a form for any of the three
declarations, so §§ 126/126a never engage. Second, *proof* is where an SES is
weaker than paper, and the gap is not closed by a better-looking signature — it
is closed by the surrounding record (§5).

---

## 2. The Aufnahmeantrag (Beitrittserklärung)

**§ 58 Nr. 1 BGB** (verbatim): „Die Satzung soll Bestimmungen enthalten: 1. über
den Eintritt und Austritt der Mitglieder". The law itself is form-free; the
Bundestag's Wissenschaftliche Dienste (WD 7 - 3000 - 052/23, excerpt) and the
WLSB/VIBSS guidance say the same: membership arises from an Aufnahmevertrag, and
no particular form is prescribed. **Confidence: High.**

**Where the Satzung says "schriftlich"**, the form is "durch Rechtsgeschäft
bestimmt" and § 127 Abs. 2 applies. There is no decision on an online
Beitrittsformular (**NO AUTHORITY FOUND**), but a consistent line of four
Oberlandesgerichte on the neighbouring question — a Satzung requiring a
*written* invitation to the Mitgliederversammlung, sent by e-mail — reads the
clause exactly this way:

- **OLG Hamm 24.09.2015 – 27 W 104/15** (excerpt): the Satzung's Schriftform is a form by Rechtsgeschäft, not by statute; via § 127 Abs. 1 with § 126 Abs. 3 it may be replaced by electronic form, and under § 127 Abs. 2 no signature is required; the purpose — members learning of the meeting — is achieved by e-mail.
- **OLG Saarbrücken 22.11.2012 – 5 W 407/12**, **OLG Zweibrücken 04.03.2013 – 3 W 149/12**, **OLG Hamburg 06.05.2013 – 2 W 35/13** (excerpts): same result.
- Commentary (rkpn.de *Die Schriftform in der Satzung*; IWW *Vereinspraxis im Zeitalter elektronischer Medien*, excerpts) applies the rule to the Beitrittserklärung and the Austritt: Textform, e-mail or fax satisfy a Satzung "schriftlich" unless the Satzung shows a contrary will.

**Confidence: High** for the Einladung line; **Medium** for the transfer to the Beitritt.

**BGH 29.07.2014 – II ZR 243/13** (excerpts, secondary sources): membership can
also come about *konkludent* — a person who is treated as a member and pays dues
is one, even where the Satzung's written-application procedure was not followed.
**Confidence: Medium** (the judgment itself could not be read). This lowers the
stakes: an electronic Beitritt that a court found form-defective would still
leave a membership standing on conduct; what the record must prove is the
*terms* (dues, mandate, Satzung acknowledgement), not the fact of joining.

**§ 32 Abs. 2 BGB** (in force 21.03.2023, excerpt): the Mitgliederversammlung —
the Verein's most formal act — may now be held with members participating „im
Wege der elektronischen Kommunikation". A Registergericht is unlikely to treat
an electronic Beitritt as inherently deficient after the legislature accepted
electronic communication there. **Confidence: High** (content), no verbatim.

**What it means for us.** An online form with the applicant's typed name,
delivered to the club and confirmed back by mail, is very likely
form-compliant even under a Satzung that says "schriftlich" — unless the Satzung
says "eigenhändig unterschrieben" or otherwise shows a contrary will. The FRGS
Satzung lives outside this repository and must be read. The safest sentence to
put in it: *„Der Aufnahmeantrag ist in Textform (§ 126b BGB), auch über das vom
Verein bereitgestellte elektronische Verfahren, zu stellen."* That removes the
„anderer Wille" argument entirely.

---

## 3. The SEPA-Basislastschriftmandat

### 3.1 The scheme: signature method is left to national law

EPC SDD Core Rulebook §4.1 (excerpts, identical wording returned from the 2017,
2019 and 2025 editions):

> "The Mandate must always be signed by the Debtor as account holder or by a person in possession of a form of authorisation from the Debtor to sign the Mandate on his behalf."
> "The Mandate may be an electronic document which is signed using a legally binding method of signature, and whether it be in paper or electronic form, must contain the necessary legal text and the names of the parties signing it."
> "The signed Mandate, whether it be paper-based or electronic, must be stored by the Creditor."

EPC098-13, *Clarification letter on electronic mandates* (October 2013,
excerpt): the signature methods in §4.1 "are not exhaustive"; participants "may
consider allowing continued usage of other legally binding methods of signature
including those that were used under the local legacy scheme rules."

EPC132-17, *Clarification Paper SDD Core and B2B* (v4.1, excerpt): "The
validity of an electronically signed mandate is primarily a matter of the law
that applies in the relationship between the Debtor and the Creditor. The SDD
scheme rulebooks do not prescribe nor limit the methods of signing electronic
mandates in a legally binding manner." And: "The Creditor is always liable for
the proof of the validity of the mandate when requested to do so by the Debtor
PSP (through the Creditor PSP)."

EPC106-16, *Validity of Electronic Mandates in a Cross-Border Context* (excerpt):
"the way a valid mandate is created is a matter between Debtor and Creditor
based on contractual provisions with their PSPs, and the basis for legal
assessment of mandate validity in case of dispute is what was agreed between the
Debtor and Debtor Bank."

**Confidence: High** throughout (three EPC documents, consistent; none fetched in full).

The formal EPC *e-Mandate service* — bank-routed, authenticated through the
debtor's online banking — is an optional inter-bank product (EPC002-09) that
German banks essentially do not offer. A browser click-mandate is a "market"
e-mandate outside that service, and everything above says its validity rests on
national law and the two bank contracts.

### 3.2 Germany: form-free by statute, decided by the club's bank

**§ 675j Abs. 1 BGB** (excerpt): „Art und Weise der Zustimmung sind zwischen dem
Zahler und seinem Zahlungsdienstleister zu vereinbaren." The form of the
authorisation is a matter of the *debtor–bank* contract.

**Deutsche Bundesbank**, FAQ *Ist es möglich, SEPA-Lastschriftmandate im
Internet zu erteilen?* (excerpt, English rendering; German original not
fetched): in Germany there are no special legal requirements on how a mandate
is issued, so mandates can be issued over the Internet; issuance is governed
solely by contractual agreements, in particular the Inkassovereinbarung between
the payee and its payment service provider; **the payee bears the Darlegungs-
und Beweislast** for an authorised mandate; whether Internet mandates are
accepted is decided by the erste Inkassostelle. **Confidence: High** (three
independent secondary sources repeat the same three propositions).

**Debtor-side DK conditions** (*Bedingungen für Zahlungen mittels Lastschrift im
SEPA-Basislastschriftverfahren*, Nr. 2.2.1 — verbatim German, identical across
Deutsche Bank, Sparkasse, BW-Bank, Santander and others):

> „Der Kunde erteilt dem Zahlungsempfänger ein SEPA-Lastschriftmandat. […] Das Mandat ist schriftlich oder in der mit seiner Bank vereinbarten Art und Weise zu erteilen."

**Confidence: Very High.** Read carefully: from the *member's* bank's point of
view, a click-mandate is neither "schriftlich" (§ 126) nor a form the member
agreed with *their* bank. The member's bank does not check mandates at
collection time; the clause matters only when the member disputes and the bank
reads the copy the club sends (§3.3).

**Creditor-side Inkassovereinbarung** (*Vereinbarung über den Einzug von
Forderungen durch SEPA-Basislastschriften*, Sparkassenverlag form 114 910.000,
2013/2014 editions; a DKB edition v17.0 dated May 2026 exists but was
unreachable). Excerpt, English rendering: the payee undertakes to submit
collections only against a "written and signed SEPA Direct Debit mandate".
**Confidence: Medium** (one excerpt). Haufe (excerpt, paraphrase): the mandate
must in principle be in writing or electronically with a qualified signature,
but the Deutsche Kreditwirtschaft "hat sich darauf verständigt, auch
elektronische Mandate ohne Unterschrift zuzulassen, wie sie im Online-Handel
verwendet werden" — with the risk on the payee, who must prove the mandate on
demand. **Confidence: Medium.** Whether the current edition of *this club's*
Inkassovereinbarung contains an "in einer anderen mit der Bank vereinbarten
Form" option: **NO AUTHORITY FOUND** — only the bank's PDF answers it.

**What it means for us.** The single gating fact is contractual, not legal: the
club must have its bank's acceptance of electronic mandates, in the
Inkassovereinbarung's own wording or in a written side-confirmation. Until it
does, the paper flow is the only defensible one. ADR-0053 therefore makes that
confirmation a hard enable condition, in the same shape as
`document_url_missing`.

### 3.3 What happens in a dispute, and what "copy of the mandate" means

Assembled from the DK terms, Bundesbank, EPC173-14 and Hettwer (excerpts, consistent):

| Window after debit | Mechanism | Evidence asked of the club |
|---|---|---|
| 0–8 weeks | § 675x Abs. 4 BGB / "Type 1" refund: the member's bank refunds on request, no reason, **no mandate check** | none — "The Creditor does not have to send a copy of the Mandate for a Refund request of type 1" |
| 8 weeks–13 months | § 676b Abs. 2 BGB: the member claims "nicht autorisiert"; the member's bank asks, through the club's bank, for a **copy of the mandate**; the club must supply it **within seven Geschäftstage** (DK *Bedingungen für den Lastschrifteinzug*); the member's bank decides, EPC procedure at most one month | the copy — and per EPC173-14 the refund with reason MD01 "can only occur after … the Creditor has not been able to provide **unquestionable evidence** of the mandate or has not answered the request at all" |
| > 13 months | no longer considered by the member's bank | — |

**Confidence: High.** Two consequences the design must absorb:

1. **Silence loses.** A club that cannot find, open and send the record inside a week loses the collection automatically, whatever the record's quality.
2. **Nobody has said what a "copy" of an electronic mandate is.** DK and Bundesbank guidance on the *form* of the copy for an electronic mandate: **NO AUTHORITY FOUND.** The closest: Bundesbank — storage on "Bild- oder sonstigen Datenträgern" under § 257 HGB / § 147 AO suffices, the original is not required; Haufe — an electronic archive is fine "if the electronic version can be output unchanged on paper at any time for evidence purposes". In practice the copy is whatever a bank clerk in another town will read as a mandate: a document that *looks like* the DK mandate form, with the DK text, Gläubiger-ID, Mandatsreferenz, name, IBAN and date, plus a sheet explaining how and when it was signed. A database row or a log excerpt does not read as a mandate.

### 3.4 Storage, dematerialisation, reference, pre-notification

- **Storage.** The creditor "must store mandates for as long as the mandate is valid and for at least 14 months after the last collection" (rulebook via Microsoft's SEPA documentation, read in full; Nordea and the Bundesbank FAQ agree). § 257 HGB / § 147 AO retention runs alongside. **Confidence: High.**
- **Dematerialisation** (rulebook, excerpt): "when electronic, the data elements must be extracted from the electronic document without altering the content of the electronic document." The pain.008 mandate block must be *derived from* a stored electronic document that is kept unaltered — i.e. keep the rendered document bytes, not only columns. **Confidence: High.**
- **Mandatory content** (rulebook attributes AT-01 UMR, AT-02 Creditor Identifier, AT-03 creditor name, AT-20/21 scheme and sequence type, AT-25 date of signing, AT-27 debtor id; plus debtor name and IBAN and the authorisation text). The DK model authorisation text in universal German use (read first-hand from two open-source shops): „Ich ermächtige den … , Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von dem … auf mein Konto gezogenen Lastschriften einzulösen. Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen." **Confidence: Very High** that this is the wording in use; **High** that it is the EPC/DK model text (the layout guideline PDF was blocked).
- **Mandate reference.** Danske Bank's guide (excerpt): "The mandate reference should be included in the mandate signed by the debtor. If that is not possible, you must inform your debtor of the mandate reference before you send him the first collection." Bundesbank agrees. The current design mints the UMR at submission and prints it on the sheet — already the strong form. **Confidence: High.**
- **Vorabankündigung.** Any notice stating amount and due date, by any channel including e-mail; 14 calendar days by default, shortenable by agreement including in AGB (Bundesbank, EPC132-17, Hettwer, excerpts). The signing receipt cannot serve as pre-notification for a variable first collection because the amount is unknown; the settlement mail the club already sends is the pre-notification, and the mandate text should state the shortened period and the e-mail channel. **Confidence: High.**

### 3.5 The club's bank: Frankfurter Sparkasse

The club banks with Frankfurter Sparkasse (a 100 % subsidiary of Helaba,
operating as an ordinary Sparkasse on the Sparkassen-Finanzgruppe's standard
forms, which are published by S-Management Services / DSV-Gruppe). Everything
below is from search excerpts — `frankfurter-sparkasse.de` is denied by the
sandbox's egress policy, and the bank's own Inkassovereinbarung PDF additionally
sits behind a verification page — so the owner must open the documents named
here and read the clauses in the club's own copy.

#### What Frankfurter Sparkasse publishes

| Document | What it is | What it says about the form of a mandate |
|---|---|---|
| [*Bedingungen für Zahlungen mittels Lastschrift im SEPA-Basis-Lastschriftverfahren*](https://www.frankfurter-sparkasse.de/content/dam/myif/sk-frankfurt/work/dokumente/pdf/vertragsbedingungen/bedingungen-fuer-zahlungen-mittels-lastschrift-im-SEPA-basis-lastschriftverfahren.pdf) — DSV form 114 248.000 D1, Fassung Okt. 2025, v13.0 | The **debtor-side** conditions: what a *member* who banks at Frankfurter Sparkasse has agreed with it | Nr. 2.2.1 (excerpt, verbatim): „Das Mandat ist schriftlich oder in der mit seiner Sparkasse vereinbarten Art und Weise zu erteilen." The group text of §3.2, unchanged. **Confidence: High** |
| [*Bedingungen … im SEPA-Firmen-Lastschriftverfahren*](https://www.frankfurter-sparkasse.de/content/dam/myif/sk-frankfurt/work/dokumente/pdf/vertragsbedingungen/bedingungen-fuer-zahlungen-mittels-lastschrift-im-SEPA-firmen-lastschriftverfahren.pdf) — 114 247.000 D1, Okt. 2025, v14.0 | B2B scheme | Not applicable — members are consumers, the club collects Basislastschriften |
| [*Inkassovereinbarung*](https://www.frankfurter-sparkasse.de/content/dam/myif/spk-frankfurt/work/dokumente/pdf/firmenkunden/inkassovereinbarung.pdf) (Firmenkunden download) | The **creditor-side** agreement — the one the club signed, and the one the Bundesbank says decides | **Content not obtained**: the PDF is behind a verification page and the host is denied. It is the DSV *Vereinbarung über den Einzug von Forderungen durch SEPA-Basislastschriften* family (form 114 910.000 in its 2013/2014 editions); which edition Frankfurter Sparkasse currently issues, and which one the club signed, is unknown here. **NO ACCESS** |
| [*Lastschriftmandat* online service](https://www.frankfurter-sparkasse.de/fi/home/services-und-kontakt/online-services/alle-services/lastschriftmandat.html) | A Firmenkunden online-banking function: create, change and delete **Firmenlastschrift** mandates online, as the *debtor* | Not the club's case (Basislastschrift, the club is the creditor). Relevant only as a signal: the bank already runs mandate declarations as an online-banking act. **Confidence: Medium** |
| [*SEPA* service page](https://www.frankfurter-sparkasse.de/de/home/service/sepa.html) | Explains Gläubiger-ID and Mandatsreferenz, "both shown on the account statement" | Nothing on electronic mandates |
| [BusinessCenter](https://www.frankfurter-sparkasse.de/fi/home/beratung/service-firmenkunden.html) | „Ihr direkter Kontakt für alles rund um Ihr Geschäftskonto und den Zahlungsverkehr", **069 2641-7000**; also the [FirmenkundenCenter](https://www.frankfurter-sparkasse.de/fi/home/beratung/firmenkunden-beratung.html) by phone, e-mail and video | Where the question gets asked |

#### What the Sparkassen standard Inkassovereinbarung says

The DSV form the Sparkassen issue to creditors, in the editions that are
readable online (Sparkasse Jena-Saale-Holzland, 114 910.000 D1, Fassung Aug.
2013; Kreissparkasse Ravensburg, 02/2014), excerpt in German:

> „Der Zahlungsempfänger verpflichtet sich, Lastschriften nur dann zum Einzug einzureichen, wenn ihm hierzu das schriftliche und vom Zahlungspflichtigen unterzeichnete SEPA-Lastschriftmandat … vorliegt."

**Confidence: Medium-High** (the same sentence came back, in German, from two
editions; neither PDF was read in full). The companion obligations, from the
same family (excerpts, English renderings): the mandate is produced **on
request within seven Geschäftstage**; after the mandate ends it is kept **at
least 14 months from the submission date of the last collection**; storage on
image or data carriers under § 257 HGB / § 147 AO is allowed.

Two Sparkassen state the conservative reading of that clause in their own
guidance: Kreissparkasse Ahrweiler's SEPA checklist (2016, excerpt) — mandates
not given in writing, „z. B. telefonisch oder über das Internet", are „nicht
SEPA-fähig"; and the Sparkassen-Finanzgruppe's Vereinsbroschüre (Kreissparkasse
Rottweil copy, excerpt) — the mandate „muss schriftlich vorliegen und vom
Mitglied unterschrieben sein". **Confidence: Medium.** Whether any later
edition of the form (2016 onward) carries an "in einer anderen mit der
Sparkasse vereinbarten Form" option, as the debtor-side conditions do: **NO
AUTHORITY FOUND** — the 2025/2026 form numbers that surface (114 247.000,
114 248.000) are the debtor-side *Bedingungen*, not the Inkassovereinbarung.

#### What the same group does in practice

- **PAYONE** — the Sparkassen-Finanzgruppe's own payment service provider, marketed to Sparkasse business customers on [sparkasse.de](https://www.sparkasse.de/unsere-loesungen/firmenkunden/e-commerce/payone.html). Its SEPA guidance (excerpt): printed mandates need a signature, **online mandates do not**. Its `managemandate` API (excerpts of the developer documentation, consistent across three pages) takes the consumer's **IP address**, generates an **EPC-compliant mandate text** in the consumer's language for confirmation by **checkbox**, can render the mandate as a **PDF**, sends or offers it by **e-mail**, and keeps the mandate „valid up to 36 months after the last transaction or until revoked". That is the group's own PSP running click mandates for Sparkasse merchants, with exactly the evidence set in §5. **Confidence: High.**
- **S-Verein** — the Vereinssoftware sold through Sparkassen, made by S-Management Services (DSV-Gruppe). Its help pages (excerpts) describe a mandate register in which the signature date must be recorded before a collection file is generated, and integration of the SEPA mandate into a digital membership application. Whether its "digital" mandate is a typed-name form or a scanned sheet could not be read (`hilfe.s-verein.de` is denied). **Confidence: Medium.**
- **Online banking** — Firmenlastschrift mandates are created and confirmed online by the payer at Frankfurter Sparkasse itself (table above).

The picture is consistent: **the paper the club signed very likely says
„schriftlich und unterzeichnet", the group's own products treat a click with
an evidence record as a mandate, and the bank's branch guidance is
conservative.** That is precisely the gap the Bundesbank describes — the
"erste Inkassostelle" decides — and only the bank closes it.

#### What the owner does with this

1. **Get the club's copy of the Inkassovereinbarung** — the one on file, and the current form from the Firmenkunden download (link above, behind the verification page). Read the mandate clause. If it says „schriftliche und vom Zahlungspflichtigen unterzeichnete", the electronic path is outside the letter of the contract until the bank says otherwise.
2. **Ask the BusinessCenter (069 2641-7000) or the Firmenkundenberater, in writing**, and file the answer with the date. A form of words that names what the bank needs to see:

   > Wir möchten SEPA-Basislastschriftmandate unserer Mitglieder künftig auch elektronisch einholen: Das Mitglied füllt das Mandat (DK-Mustertext, Gläubiger-ID, Mandatsreferenz, Name, IBAN) auf seinem Telefon aus, bestätigt es mit Namenseingabe und bestätigt anschließend den Vorgang über einen einmaligen Link an die angegebene E-Mail-Adresse. Wir bewahren das erteilte Mandat als unverändertes Dokument mit Zeitstempel, IP-Adresse, dem bestätigten Text und der E-Mail-Bestätigung auf und können es auf Anforderung innerhalb von sieben Geschäftstagen als Mandatskopie (PDF) vorlegen. Bitte bestätigen Sie uns, dass ein so erteiltes Mandat für unsere Inkassovereinbarung als „in einer anderen mit der Sparkasse vereinbarten Form" erteilt gilt — oder nennen Sie uns, welche Form Sie stattdessen akzeptieren.

3. **Record the answer in the settings screen** (ADR-0053 decision 2): the date, the edition of the agreement, and the note. A "yes" enables the path; a "no" leaves the club on paper and the feature refuses to enable — that is the intended failure mode. A bank that points to PAYONE instead is answering a different question: a PSP contract is not what a members' club needs to collect a bar tab.

**What the answer does not change.** The financial risk of a disputed mandate
is the club's under every edition of the form — a returned collection is
re-debited to the club whether the mandate was paper or electronic, and the
club's claim against the member survives (ADR-0028 §1). What the bank's
confirmation settles is the *contractual* position: without it, an electronic
mandate is a breach of the Inkassovereinbarung the bank could invoke, with
termination of the agreement as the sanction that would actually hurt.

---

## 4. The Kenntnisnahme of the Datenschutzhinweise

### 4.1 Nothing to sign

Art. 13 DSGVO is a duty to *inform*. `research/175-onboarding-form-datenschutz.md`
§5.3 already settled that the notice is never signed and that a screen must
log timestamp and notice version rather than collect a declaration. The
Thüringen LfDI guidance (excerpt) adds that the controller should be able to
show *when and how* it informed — version, display logic, archive. **Confidence:
High.** The current implementation records `privacy_notice_url` and
`privacy_notice_shown_at` on the pending row. That is the right artefact.

### 4.2 The mandatory checkbox is legally the weaker shape

**BGH 15.05.2014 – III ZR 368/13** (excerpt): a pre-formulated confirmation
checkbox that must be ticked to complete a registration („Widerrufsbelehrung zur
Kenntnis genommen und ausgedruckt oder abgespeichert") is **invalid under § 309
Nr. 12 b BGB**, because the Unternehmer thereby procures evidence against the
customer and shifts the burden of proof; a checkbox is "oft unbedacht gesetzt"
and not comparable to a handwritten signature. The statutory exception (§ 309
Nr. 12 Hs. 2, verbatim above) covers only an Empfangsbekenntnis that is
„gesondert unterschrieben oder mit einer gesonderten qualifizierten
elektronischen Signatur versehen". **Confidence: High.**

Whether § 309 Nr. 12 reaches a Verein's Aufnahmeformular at all (AGB control of
Verein–Mitglied terms; III ZR 368/13 is a B2C case): **NO AUTHORITY FOUND.**

**What it means for us.** Do not add a Kenntnisnahme checkbox to the electronic
path. It would add nothing Art. 13 needs, it is at risk of being an unenforceable
proof device, and the one way to make it enforceable is a QES the design does not
have. Log the display (already done), link the document (already done), and let
the applicant's *own* declarations — Beitritt, mandate — be the things they
sign.

### 4.3 A declaration is not a Tatsachenbestätigung

§ 309 Nr. 12 b targets a clause that makes the other party *confirm facts*. The
mandate authorisation and the membership application are the applicant's own
*Willenserklärungen*; ticking "Ich ermächtige …" is the declaration itself, not
a confirmation of a fact within the club's sphere. The distinction matters for
the on-screen wording in ADR-0053: every checkbox on the signing screen is a
declaration in the first person, never "I confirm that I have received/read".

---

## 5. What courts actually wanted as proof — the evidence checklist

No German decision exists on an online SEPA mandate or an MD01 return (**NO
AUTHORITY FOUND**, searched under every combination of Lastschriftmandat /
Einzugsermächtigung / online / Internet / Beweis / MD01). The neighbouring lines
— proof of electronic consent, proof of an online contract, proof from server
logs — are consistent and map directly onto a design:

| Decision | What the court held (excerpt) | Design consequence |
|---|---|---|
| **BGH 10.02.2011 – I ZR 164/09** (Double-Opt-In) | The party relying on an electronically transmitted declaration must fully document it — store it and be able to **print it at any time**. Where the consumer confirmed their address by double opt-in and later disputes an e-mail declaration, the burden shifts *to them* for that address. | A confirmation from the mailbox on file, and a record that renders to paper |
| **OLG München 27.09.2012 – 29 U 1682/12** | Lost for lack of a **protocol of the registration process**. | Record the process, not just the outcome |
| **VG Düsseldorf 27.07.2026 – 29 K 9714/24** | E-mail address, IP addresses and timestamps of sign-up and confirmation are **not sufficient**; what is required is traceable documentation of the **concrete declaration and the confirmation process** — which text, which data, what was confirmed. | Freeze the exact declaration texts and template version, the data entered, the mail body and the token that was confirmed |
| **OLG Düsseldorf 26.02.2003 – 18 U 192/02** | No Anscheinsbeweis for the correctness of provider **log files**; they are weighed freely. | Make the record's integrity explainable: a hash the applicant receives by mail, and optionally a qualified time stamp |
| **AG München 23.10.2024 – 231 C 18392/24** | Claim dismissed: no IP attributable to the defendant, **no authentication step** (login/TAN), the link **not protected**, anyone with the mailbox could have clicked. | A one-time, hashed-at-rest confirmation token; IP and user agent at both the declaration and the confirmation; an optional second factor later (§7.1) |
| **BGH 11.05.2011 – VIII ZR 289/09** (eBay); **LG Bonn 2 O 472/03**; **OLG Köln 19 U 16/02** | No Anscheinsbeweis that the account, mailbox or password holder acted; a household member could have. | Identity is the residual weakness of every SES; close it with context — the IBAN's holder is the applicant, the first debits are not returned, the card is used at the bar |
| **Bundesarchiv, *Scanprodukte als Beweismittel*** | A scanned/drawn signature is Augenschein under § 286, not an Urkunde. | Do not capture a drawn signature image: same legal weight as a typed name, more data, and it recreates the "IBAN plus signature per member" file class ADR-0037 removed |

**Confidence: High** for each holding (consistent secondary sources; none read in full).

The *DSK Kurzpapier Nr. 20* (excerpt) says the same for consents: proof is a
documentation duty; electronic consents are "zu protokollieren", covering the
whole opt-in procedure and the content. **Confidence: High.**

**The checklist a defensible electronic mandate record must satisfy** (also in
ADR-0053 as the data model):

1. **The document as shown, frozen.** The club's filled Anmeldung with the DK text, Gläubiger-ID, Mandatsreferenz, name, full IBAN and date — stored unaltered, with its SHA-256.
2. **The declarations as text**, each with its version and the applicant's first-person acceptance.
3. **Who.** Typed full name (must equal the applicant's name), the e-mail address, and the confirmation of that address by a one-time link — with the time the mail was queued, sent, and the link clicked, and the IP and user agent of the click.
4. **The act.** UTC timestamp, IP, user agent of the submission; the `Accept-Language` seen.
5. **Anchors.** The record's hash sent to the applicant in the confirmation mail (an external copy the club cannot alter after the fact); optionally an RFC 3161 time stamp from a qualified TSA (Art. 41(2) presumption on time and integrity).
6. **Lifecycle.** UMR and Gläubiger-ID communicated before the first collection (they are on the document and in the mail); retention for the life of the mandate plus 14 months after the last collection, and § 147 AO alongside; the record kept intact through anonymisation.
7. **A one-click Mandatskopie export** the treasurer can hand to the bank within seven business days: the document (opened with the private key) plus an evidence sheet rendered from the record.

---

## 6. Minors

Verbatim (BGB mirror):

- **§ 107**: „Der Minderjährige bedarf zu einer Willenserklärung, durch die er nicht lediglich einen rechtlichen Vorteil erlangt, der Einwilligung seines gesetzlichen Vertreters." A membership with dues is not "lediglich rechtlich vorteilhaft" (practitioner consensus).
- **§ 108 Abs. 1**: without consent the contract's validity depends on the representative's Genehmigung.
- **§ 182 Abs. 2**: „Die Zustimmung bedarf nicht der für das Rechtsgeschäft bestimmten Form." **The parent's consent is form-free even where the Satzung demands Schriftform for the Beitritt.**
- **§ 1629 Abs. 1 S. 2**: „Die Eltern vertreten das Kind gemeinschaftlich" — Gesamtvertretung.
- **§ 1357 Abs. 1**: each spouse may conclude „Geschäfte zur angemessenen Deckung des Lebensbedarfs der Familie" with effect for the other; Abs. 3: not when living apart.

**AG Ahlen 21.12.2017 – 30 C 244/17** (excerpts): a tennis-club membership taken
out by the mother for her minor son was a § 1357 transaction; consent of both
parents was not required. Practice guidance (VIBSS, IWW) nevertheless recommends
both signatures because of § 1629. **Confidence: Medium** (one Amtsgericht).

Electronic parental consent and its proof: **NO AUTHORITY FOUND.** Art. 8(2)
DSGVO (verbatim: „angemessene Anstrengungen, um sich … zu vergewissern, dass die
Einwilligung durch den Träger der elterlichen Verantwortung … erteilt wurde")
applies only to information-society services offered directly to a child and
leaves contract law untouched (Abs. 3); it is an analogy at most.

**What it means for us.** Legally, a parent's typed name plus a confirmation
from the parent's own mailbox, with the parent as SEPA account holder and the
first debit collected from the parent's account (a conclusive Genehmigung
argument under § 108/§ 182), is a coherent evidence stack. Practically, the
browser cannot tell who is typing, the two-parent question depends on facts the
form does not know, and the paper path already handles all of it with a
legal-representative signature line. **v1 keeps minors and third-party account
holders on paper**; a parent-confirmation path is a v2 candidate once the adult
path has run for a season.

---

## 7. Stronger signatures without a vendor

### 7.1 WebAuthn / passkeys as an "advanced" signature

Art. 26 lists four functional criteria and no certificate (§1.1). A WebAuthn
assertion over the record's hash gives (a) a key unique to the credential,
(c) creation data under the holder's control in hardware-backed platform
authenticators, and (d) tamper-evidence by construction. Criterion (b),
"capable of identifying the signatory", rests entirely on the enrolment — a
passkey identifies a credential, and its binding to a named person is only as
good as the mailbox confirmation that enrolled it. BSI TR-03107-1 (excerpt)
wants a hardware token with two factors for an AdES at the "substanziell"
level; the FIDO Alliance's own eIDAS paper (June 2020, excerpt) casts FIDO as
the *activation* of a QTSP-held signing key, not as the signature itself.
Whether a browser key pair without a certificate is an AdES: **NO AUTHORITY
FOUND** — only vendor claims (validor.app). **Confidence: Medium.**

**What it means for us.** Argue it as *evidence*, never as a category. It is the
cheapest answer to AG München's "no authentication step" and it costs no third
party — but it adds a device-bound factor that a member changing phones loses,
and its benefit lands at dispute time only. A v2 option, not a v1 requirement.

### 7.2 Qualified time stamps

Art. 41(2) gives a *qualified* electronic time stamp a presumption of the
correctness of date and time and of the integrity of the bound data. RFC 3161
endpoints listed on the EU Trusted List and answering without a contract exist
(Sectigo's `/qualified` endpoint, several national TSAs), but their terms of
free use and rate limits are not published (**Low** confidence on terms). A
German civil decision weighing a non-qualified RFC 3161 stamp: **NO AUTHORITY
FOUND.** **What it means for us:** an optional, configurable strengthening —
the hash in the applicant's own mailbox is the free anchor; a TSA token is a
second, independent one when the club configures a URL.

### 7.3 eID and the EUDI wallet

- The Personalausweis's on-card signature function is dead in practice (Bundesdruckerei discontinued the certificates); the eID is used to *identify* for a remote QES (sign-me/D-Trust), which needs a contract with D-Trust and, for the club to read the eID itself, a Berechtigungszertifikat. **Confidence: Medium.**
- **eIDAS 2.0, Art. 5a(5)(g)** (verbatim): the wallet must "offer all natural persons the ability to sign by means of qualified electronic signatures by default and free of charge", limitable to non-professional purposes (Recital 20). A member joining a club is a natural person acting non-professionally. **Confidence: Very High** (wording).
- Germany (BMDS, excerpts): wallet available from **2 January 2027** with basic functions; **QES "im Laufe des Jahres 2027"**; Digitale-Identitäten-Gesetz draft adopted by the Kabinett on 20.05.2026. **Confidence: High** as reported.

**What it means for us.** A free QES for members is a 2027 upgrade path that
would earn § 371a and § 126a, and would even make a signed Empfangsbekenntnis
enforceable. The record format in ADR-0053 leaves room for a `qes` signing
method; nothing else in the design depends on it.

---

## 8. Which simple signature holds — ranking

Nothing below reaches § 371a except the last row. "Form" = satisfies a Satzung
"schriftlich" via § 127 Abs. 2; "Proof" = expected weight under § 286 ZPO given §5.

| Design | Aufnahmeantrag | SEPA mandate | Kenntnisnahme |
|---|---|---|---|
| **1. Typed name + checkbox, nothing else** | Form: likely fine. Proof: **weak** — nothing links the act to a person; saved by conduct (BGH II ZR 243/13), not by the record | Legally possible, bank decides; proof weak; the 8-week refund makes it moot anyway | Fine for *informing*; as a proof device suspect under § 309 Nr. 12 b |
| **2. + confirmation link from the mailbox on file** | Form: fine (e-mail is telekommunikative Übermittlung; form + confirmation = Briefwechsel). Proof: **medium** — BGH I ZR 164/09 accepts the mailbox confirmation; VG Düsseldorf wants the content documented too | Same | Adequate |
| **3. + frozen document and declaration texts, typed name matching the applicant, IP/UA/UTC at both the act and the confirmation, hash in the confirmation mail, one-click Mandatskopie export** | Form: fine. Proof: **good** — answers every objection a court actually raised. Residual: "someone else used my mailbox", closed by context (IBAN holder, debits, card use). **Recommended v1.** | Best achievable without a bank-side e-mandate; the record kept ≥ 14 months past the last collection and exportable within a week | Best: proves *which* text was shown *when*, no checkbox |
| **4. + qualified RFC 3161 time stamp** | Adds Art. 41(2) presumption on time and integrity. Cheap, optional | Same | — |
| **5. + WebAuthn signature over the record hash** | **Strongest non-QES**; a device-bound second factor; argued as evidence, not as AdES. v2 | Same | Overkill |
| **6. QES (sign-me today, EUDI wallet 2027)** | § 126a, § 371a, escapes § 309 Nr. 12 b. Friction and cost today; free for members via the wallet once QES lands | Gold standard | Only if a signed Empfangsbekenntnis were ever wanted |

**Minors, all designs:** paper in v1 (§6).

---

## 9. What this means for Club Bar

The design that falls out is recorded in [ADR-0053](../adr/0053-electronic-mandate-signature.md).
In one paragraph: the applicant, an adult signing for their own account, chooses
"online unterschreiben" on the review screen; the same single request that seals
the IBAN today also renders the club's document with the full IBAN, hashes it,
seals the bytes to the club's public key, freezes the declaration texts and the
typed name into a record, and queues one mail to the submitted address carrying
a one-time confirmation link, the mandate summary with the masked IBAN, the UMR,
the Gläubiger-ID and the record's hash. Clicking the link completes the
signature. The Kassenwart approves an *electronically signed and confirmed*
registration by reviewing the record instead of holding paper, and can export a
Mandatskopie — the document opened with the private key plus an evidence sheet —
when the bank asks. Everything the paper path does stays available, and stays
the only path for minors and third-party account holders.

Three ADRs are amended rather than contradicted: ADR-0037 (an *electronic*
mandate document is retained, sealed, because there is no paper to be the
Beleg), ADR-0052 (approval attests the record where there is no paper; "a
submission queues no mail" narrows to the paper path), and ADR-0029 (one new
retention-tier artefact). ADR-0038 gains a subject type and loses nothing.

**Owner actions that gate enabling, not shipping** — the same shape as the
document URL in ADR-0052:

1. **Bank.** Frankfurter Sparkasse — see §3.5 for the documents, the BusinessCenter line and a form of words. Obtain the club's *Inkassovereinbarung* and either find an "andere vereinbarte Form" clause or get a written confirmation that mandates given electronically with the record described in §5 are accepted. Record the date in the settings screen; the feature refuses to enable without it.
2. **Satzung.** Read the Beitritt clause. If it says "schriftlich", it is very likely fine (§2); if it says "eigenhändig", amend it; in any case the next Satzungsänderung should adopt Textform expressly.
3. **Beitragsordnung / mandate text.** State the shortened Vorabankündigung period and the e-mail channel, so that the settlement mail is contractually the pre-notification.
4. **Art. 13 notice.** The notice gains one row: proof-of-signature data (IP, user agent, timestamps, confirmation token) processed on Art. 6(1)(b) with Art. 6(1)(f) for the technical proof, retained with the mandate (§ 147 AO / § 257 HGB; Art. 17(3)(b) and (e)). The document is the club's, outside this repository (frgs-website).

---

## Sources

**Fetched in full**
- BGB and ZPO — `bundestag/gesetze` mirror (`b/bgb/index.md`, `z/zpo/index.md`); cross-check against [gesetze-im-internet.de](https://www.gesetze-im-internet.de/bgb/__127.html) before external use. Note the mirror's § 32 BGB predates the 2023 amendment.
- eIDAS consolidated text and Reg. 2024/1183 — `legalize-dev/legalize-eu` mirror (`eu/32014R0910.md`, `eu/32024R1183.md`); Art. 3, 26, 5a(5)(g) and Recital 20 verbatim; Art. 25 and 41 from excerpts.
- DSGVO German text — `kiprotect/kb` mirror (Art. 5(2), 6(1), 7(1), 8, 17(3)).
- Stripe OpenAPI `spec3.json` — the `Mandate` / `customer_acceptance` / `online_acceptance` schemas (`accepted_at`, `ip_address`, `user_agent` — both required for `type: online`), `mandate.payment_method_details.sepa_debit.reference` and `.url`.
- Stripe's SEPA mandate text (EN/DE) as shipped in `stripe-ios` and mirrored in `videolan/vlc-ios`; the DK model mandate text from `shopware5/shopware` and `OpenXE-org/OpenXE`; GoCardless `mandate_pdfs` endpoint from `gocardless/gocardless-pro-php`; the rulebook storage rule from `MicrosoftDocs/dynamics-365-unified-operations-public`.
- Free/qualified RFC 3161 endpoint list — [gist](https://gist.github.com/Manouchehri/fd754e402d98430243455713efada710).

**Excerpt only (host blocked)**
- EPC: [SDD Core Rulebook 2025 v1.1](https://www.europeanpaymentscouncil.eu/sites/default/files/kb/file/2025-10/EPC016-06%202025%20SDD%20Core%20Rulebook%20version%201.1.pdf) · [EPC098-13 clarification letter](https://www.europeanpaymentscouncil.eu/document-library/guidance-documents/epc-clarification-letter-electronic-mandates-sepa-direct-debit) · [EPC106-16](https://www.europeanpaymentscouncil.eu/sites/default/files/KB/files/EPC106-16%20EPC%20Recommendations%20-%20Validity%20of%20Electronic%20Mandates%20in%20a%20Cross-Border%20Context.pdf) · [EPC132-17 v4.1](https://www.europeanpaymentscouncil.eu/sites/default/files/kb/file/2025-04/EPC132-17%20v4.1%20Clarification%20Paper%20SDD%20Core%20and%20SDD%20B2B%20scheme%20rulebooks.pdf) · [EPC173-14 reason codes](https://www.europeanpaymentscouncil.eu/sites/default/files/kb/file/2019-05/EPC173-14%20v5.0%20Guidance%20on%20Reason%20Codes%20for%20SDD%20R-transactions.pdf)
- Bundesbank: [Internet mandates FAQ](https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/sepa/ist-es-moeglich-sepa-lastschriftmandate-im-internet-zu-erteilen--640222) · [Aufbewahrung FAQ](https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/sepa/wie-sind-sepa-mandate-aufzubewahren--640260) · [Mandatsreferenz FAQ](https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/sepa/was-ist-die-mandatsreferenz--640242)
- DK texts: [Deutsche Bank, Bedingungen SEPA-Basislastschrift](https://www.deutsche-bank.de/dam/deutschebank/de/shared/pdf/ser-agb-bedingungen-SEPA-Basislastschriftverfahren_ag.pdf) · [DK Mustertext 2014](https://www.jura.uni-frankfurt.de/50744168/AGB_Banken_Lastschrift_SEPA_Basis_2014.pdf) · [Sparkasse Inkassovereinbarung 2014](https://www.kreissparkasse-ravensburg.de/inkassovereinbarung) · [Merck Finck, Bedingungen für den Lastschrifteinzug](https://www.merckfinck.de/media/45mnvkpb/bedingungen-fuer-den-lastschrifteinzug.pdf) · [Haufe, SEPA-Praxisfragen](https://www.haufe.de/finance/buchfuehrung-kontierung/sepa-praxis-fragen_186_186996.html) · [Hettwer, Mandatskopie](https://www.hettwer-beratung.de/sepa-spezialwissen/sepa-mandatsverwaltung/mandatskopie-mandatsanforderung/) · [Händlerbund](https://www.haendlerbund.de/de/ratgeber/recht/3941-sepa-umstellung)
- Case law: [BGH I ZR 164/09](https://lexetius.com/2011,4717) · [OLG München 29 U 1682/12](https://medien-internet-und-recht.de/volltext.php?mir_dok_id=2427) · [VG Düsseldorf 29 K 9714/24](https://nrwe.justiz.nrw.de/ovgs/vg_duesseldorf/j2026/29_K_9714_24_Urteil_20260727.html) · [OLG Düsseldorf 18 U 192/02](https://www.jurpc.de/jurpc/show?id=20030156) · [AG München 231 C 18392/24](https://www.lto.de/recht/nachrichten/n/231c1839224-ag-muenchen-zur-zahlungspflicht-bei-online-vertragsschluss) · [BGH VIII ZR 289/09](https://openjur.de/u/165214.html) · [BGH III ZR 368/13](https://medien-internet-und-recht.de/volltext.php?mir_dok_id=2610) · [OLG Hamm 27 W 104/15](https://nrwe.justiz.nrw.de/olgs/hamm/j2015/27_W_104_15_Beschluss_20150924.html) · [BGH II ZR 243/13](https://dejure.org/dienste/vernetzung/rechtsprechung?Gericht=BGH&Datum=29.07.2014&Aktenzeichen=II+ZR+243%2F13) · [AG Ahlen 30 C 244/17](https://www.iww.de/vb/vereinsrecht/vereinsrecht-vereinsbeitritt-minderjaehriger-erklaerung-eines-elternteils-genuegt-f111134)
- Guidance: [Bundesarchiv, Scanprodukte als Beweismittel](https://www.bundesarchiv.de/assets/bundesarchiv/de/Downloads/Erklaerungen/Scanprodukte_als_Beweismittel.pdf) · [DSK Kurzpapier Nr. 20](https://www.datenschutzkonferenz-online.de/media/kp/dsk_kpnr_20.pdf) · [TLfDI, Hinweise zu den Informationspflichten](https://tlfdi.de/fileadmin/tlfdi/datenschutz/hinweise_zu_den_informationen.pdf) · [WD 7 - 3000 - 052/23](https://www.bundestag.de/resource/blob/954142/23fcdf8875e4b0d421b54c83cb563a1a/WD-7-052-23-pdf.pdf) · [BSI TR-03107-1](https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Publikationen/TechnischeRichtlinien/TR03107/TR-03107-1.pdf) · [FIDO/eIDAS white paper](https://fidoalliance.org/wp-content/uploads/2020/06/FIDO_Using-FIDO-with-eIDAS-Services-White-Paper.pdf) · [BMDS, EUDI-Wallet](https://bmds.bund.de/themen/digitaler-staat/digitale-identitaeten/eudi-wallet)
- Industry: [GoCardless payment pages](https://gocardless.com/guides/sepa/payment-pages) · [GoCardless chargebacks](https://support.gocardless.com/hc/en-us/articles/115002883945-SEPA-Chargeback-process) · [Adyen SEPA](https://docs.adyen.com/payment-methods/sepa-direct-debit) · [Stripe SEPA terms](https://stripe.com/legal/sepa-direct-debit) · [Telekom, Bankverbindung](https://www.telekom.de/hilfe/vertrag-rechnung/rechnung/bankverbindung-einrichten-aendern) · [SlimPay](https://www.slimpay.com/blog/electronic-signature-balance-authentication-user-experience/) · [Twikey](https://www.twikey.com/guides/sepa-direct-debit-mandates.html)

**NO AUTHORITY FOUND (looked for, not found):** a court decision on an online Vereinsbeitritt under a Satzung "schriftlich" clause · a court decision on proving an online SEPA mandate or on liability after an MD01 return · DK/Bundesbank guidance on the *form* of a mandate copy for an electronic mandate · whether the current DK Inkassovereinbarung edition offers an electronic-mandate option · a decision on a parent's electronic consent to a child's Vereinsbeitritt · any authority that a browser key pair without a certificate is an AdES · a civil decision weighing a non-qualified RFC 3161 stamp · whether § 309 Nr. 12 b applies to a Verein's Aufnahmeformular · the verbatim text of Bundesbank's Internet-mandate FAQ, DSK Kurzpapier 20 and the 2025 rulebook's storage and copy-request sections.
