# SEPA Export: NOTPROVIDED BIC Encoding (Othr/Id instead of BICFI)

**Issue:** [#12](https://github.com/dgloeckner/clubbar/issues/12)
**Goal:** For IBAN-only submissions, encode the missing agent BIC as `<Othr><Id>NOTPROVIDED</Id></Othr>` instead of `<BICFI>NOTPROVIDED</BICFI>`, as prescribed by the EPC/DK implementation guidelines for pain.008.001.08.

**Related:** [ADR-0008](../adr/0008-sepa-xml-export-format-selection.md) (SEPA XML export format), UC-A31 (SEPA XML export)

---

## Analysis

### Current behaviour

`SepaExportService` passes the literal string `'NOTPROVIDED'` as the BIC for both agents:

| Location | Code |
|----------|------|
| `backend/src/Modules/Settlements/Services/SepaExportService.php:65` | `'creditorAgentBIC' => 'NOTPROVIDED'` |
| `backend/src/Modules/Settlements/Services/SepaExportService.php:87` | `'debtorBic' => 'NOTPROVIDED'` |

Produced output:

```xml
<CdtrAgt><FinInstnId><BICFI>NOTPROVIDED</BICFI></FinInstnId></CdtrAgt>
<DbtrAgt><FinInstnId><BICFI>NOTPROVIDED</BICFI></FinInstnId></DbtrAgt>
```

### Why this is wrong even though XSD validation passes

`BICFI` is typed `BICFIDec2014Identifier` with pattern `[A-Z0-9]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?`.
The literal `NOTPROVIDED` (11 chars) accidentally satisfies it: `NOTP` (institution) + `RO` (country) + `VI` (location) + `DED` (branch).

That means the file declares a **syntactically well-formed but non-existent Romanian BIC** for a German creditor. Schema validation cannot catch this — only a bank-side validator that resolves the BIC against a directory, or cross-checks the BIC country against the IBAN country, will. Frankfurter Sparkasse and Deutsche Bank both run such checks on upload.

`Othr/Id` is untyped beyond `Max35Text`, carries no BIC semantics, and is the encoding the EPC/DK customer-to-bank guidelines specify for the IBAN-only case.

### Verified facts

Established by inspecting the pinned `digitick/sepa-xml` 3.1.0 source and by generating both variants against the vendored XSD (`vendor/digitick/sepa-xml/doc/ISO20022/pain/008/001/pain.008.001.08.xsd`):

1. **`CdtrAgt` and `DbtrAgt` are both mandatory** in pain.008.001.08 — declared without `minOccurs`, so `minOccurs=1` applies. Neither can be dropped. (The issue noted this for `DbtrAgt`; it holds for `CdtrAgt` too.)
2. **`FinancialInstitutionIdentification18` allows `Othr`** (`GenericFinancialIdentification1`, `Id` = `Max35Text`), all children optional. `<Othr><Id>NOTPROVIDED</Id></Othr>` is schema-valid.
3. **The library already implements the desired encoding natively.** `BaseDomBuilder::getFinancialInstitutionElement(?string $bic)` emits `<Othr><Id>NOTPROVIDED</Id></Othr>` when `$bic === null`, `<BICFI>` for direct-debit `.001.>=03`, `<BIC>` otherwise. The comment in the library states the intent verbatim: *"We use this to circumvent some banks' strict BIC validation."*
4. **Null propagates cleanly through the facade.** `CustomerDirectDebitFacade::addPaymentInfo()` reads `$paymentInformation['creditorAgentBIC'] ?? null`; `addTransfer()` only calls `setBic()` when `isset($transferInformation['debtorBic'])`, and `BaseTransferInformation::getBic()` returns `null` by default.
5. **Both variants validate against the official XSD.** Confirmed by generating each and running `DOMDocument::schemaValidate()`.

Conclusion: **no DOM post-processing and no upstream contribution are needed.** Dropping the two array keys is sufficient.

### Effect on the issue's suggested steps

The issue proposed *verify with a bank validator first, change only if rejected*. That gate is unnecessary here:

- `Othr/Id=NOTPROVIDED` is the spec-conformant encoding regardless of what any individual bank tolerates.
- `BICFI=NOTPROVIDED` is at best tolerated by accident, and cannot be more likely to pass than the prescribed form.
- The change costs two deleted array keys.

So the change is strictly non-regressive and does not depend on a bank round-trip. Bank-side verification remains worthwhile as post-implementation confirmation (Task 5), not as a precondition.

---

## File Map

| File | Change |
|------|--------|
| `backend/src/Modules/Settlements/Services/SepaExportService.php` | **Modify** — drop `creditorAgentBIC` / `debtorBic` keys, add explaining comment |
| `backend/tests/Unit/Modules/Settlements/Services/SepaExportServiceTest.php` | **Modify** — replace the `BICFI` assertion; add a dedicated encoding test |
| `adr/0008-sepa-xml-export-format-selection.md` | **Modify** — document IBAN-only agent encoding (**requires maintainer approval**) |

---

## Task 1: Emit `Othr/Id` instead of `BICFI` for IBAN-only submissions

**Files:** Modify `backend/src/Modules/Settlements/Services/SepaExportService.php`

- [ ] **Step 1: Remove the pseudo-BIC from the payment information**

At line 65, delete the `'creditorAgentBIC' => 'NOTPROVIDED',` entry and add a comment above the array explaining that omitting the BIC makes the library emit the EPC/DK-prescribed `Othr/Id` form:

```php
// IBAN-only submission: no agent BIC is supplied. Omitting the BIC makes
// digitick emit <FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>,
// the encoding the EPC/DK guidelines prescribe for pain.008.001.08.
// Passing the literal 'NOTPROVIDED' would instead land in <BICFI>, where it
// parses as a (non-existent) Romanian BIC and can trip bank-side validators.
```

- [ ] **Step 2: Remove the pseudo-BIC from the transfer**

At line 87, delete the `'debtorBic' => 'NOTPROVIDED',` entry.

**Success criteria:** Generated XML contains `<Othr><Id>NOTPROVIDED</Id></Othr>` under both `CdtrAgt/FinInstnId` and `DbtrAgt/FinInstnId`, and no `BICFI` element.

---

## Task 2: Update and extend the unit tests

**Files:** Modify `backend/tests/Unit/Modules/Settlements/Services/SepaExportServiceTest.php`

- [ ] **Step 1: Replace the outdated BICFI assertion**

`testExportContainsCoreDirectDebitStructure()` currently asserts (line 89-90):

```php
// ISO 20022 2019 versions (>= .08) use BICFI instead of BIC
$this->assertGreaterThan(0, $xpath->query('//p:FinInstnId/p:BICFI')->length);
```

This asserts the defect. Remove it — the encoding gets its own test in Step 2.

- [ ] **Step 2: Add a dedicated encoding test**

```php
public function testIbanOnlySubmissionUsesOthrIdNotProvidedForAgents(): void
{
    $xml = $this->makeService()->generateSepaXml(self::SETTLEMENT_ID);

    $dom = new \DOMDocument();
    $dom->loadXML($xml);
    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08');

    // EPC/DK guidelines: when no BIC is supplied, the agent must be identified
    // via Othr/Id = NOTPROVIDED. BICFI=NOTPROVIDED parses as a non-existent
    // Romanian BIC and is rejected by strict bank-side validators.
    $this->assertSame(0, $xpath->query('//p:FinInstnId/p:BICFI')->length);

    foreach (['CdtrAgt', 'DbtrAgt'] as $agent) {
        $nodes = $xpath->query("//p:{$agent}/p:FinInstnId/p:Othr/p:Id");
        $this->assertSame(1, $nodes->length, "{$agent} must carry exactly one Othr/Id");
        $this->assertSame('NOTPROVIDED', $nodes->item(0)->textContent);
    }
}
```

- [ ] **Step 3: Assert both agents remain present**

`CdtrAgt` and `DbtrAgt` are mandatory (`minOccurs=1`). Add to the same test, guarding against a future "just omit the element" regression:

```php
$this->assertSame(1, $xpath->query('//p:CdtrAgt')->length);
$this->assertSame(1, $xpath->query('//p:DbtrAgt')->length);
```

**Verification:**

```bash
cd backend && vendor/bin/phpunit tests/Unit/Modules/Settlements/Services/SepaExportServiceTest.php
```

**Success criteria:** All tests pass, including the pre-existing `testExportValidatesAgainstOfficialXsd()` (XSD validation must stay green).

---

## Task 3: Run the full backend unit suite

- [ ] **Step 1: Confirm no regressions**

```bash
cd backend && vendor/bin/phpunit
```

**Success criteria:** No new failures relative to the pre-change baseline.

---

## Task 4: Update ADR-0008 (**requires maintainer approval**)

> ADRs must not be modified without explicit user confirmation (CLAUDE.md). Do not start this task until approved.

**Files:** Modify `adr/0008-sepa-xml-export-format-selection.md`

- [ ] **Step 1: Correct the XML structure example**

The example currently shows `<CdtrAgt><FinInstnId><BICFI>COBADEFFXXX</BICFI></FinInstnId></CdtrAgt>` and omits `DbtrAgt` entirely from `DrctDbtTxInf`. Both diverge from what the implementation emits. Replace with the IBAN-only form and add the mandatory `DbtrAgt`.

- [ ] **Step 2: Add an explicit "Agent identification" core principle**

State that Club Bar does not collect agent BICs (IBAN-only), that both `CdtrAgt` and `DbtrAgt` are mandatory in pain.008.001.08 and therefore cannot be omitted, and that the missing BIC is encoded as `Othr/Id = NOTPROVIDED` per EPC/DK guidelines.

- [ ] **Step 3: Tick the relevant post-implementation monitoring item**

The "Monitor bank acceptance (any rejections?)" checkbox relates directly to this change.

**Note:** The issue also claims ADR-0008 "still documents pain.008.001.02 and the old settlement-ID scheme". That is stale — the ADR was amended on 2026-08-04 and already documents pain.008.001.08 plus the truncated-UUID ID scheme. Only the agent-encoding details above are outdated.

---

## Task 5: Bank-side confirmation (post-implementation, manual)

- [ ] **Step 1: Upload a generated export to a bank file check**

Frankfurter Sparkasse "Datei-Übergabe" validates before submission; Deutsche Bank validates via its e-banking channel.

- [ ] **Step 2: Record the outcome on issue #12 and close it**

Not a precondition for Tasks 1-3 — the code change is spec-conformant either way. This step converts "conforms to the guidelines" into "confirmed accepted by our banks".

---

## Out of Scope

- Adding real BIC support (collecting/deriving agent BICs from IBANs). Not required for SEPA within the EEA since the IBAN-only rule took effect, and it would add a bank-directory dependency.
- Using `BaseDomBuilder::setOmitAgentElementIfBicMissing(true)`. That drops the `CdtrAgt`/`DbtrAgt` wrappers entirely, which is invalid for pain.008.001.08 where both are mandatory. It targets other pain profiles.
- Upstream contribution to `digitick/sepa-xml`. The library already supports the required encoding.
