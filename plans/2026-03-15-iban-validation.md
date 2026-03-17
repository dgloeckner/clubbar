# IBAN Validation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add proper IBAN validation (ISO 7064 Mod 97-10 checksum) across frontend and backend, with inline feedback and E2E tests.

**Architecture:** Shared `validateIban()` utility on frontend, new `iban` validation rule on backend. Both use the same Mod 97 algorithm. Frontend shows inline ValidationIndicator on IBAN fields; backend rejects invalid IBANs with 422.

**Tech Stack:** TypeScript (frontend utility), PHP (backend Validator rule), Playwright (E2E tests)

---

### Task 1: Shared Frontend IBAN Validation Utility

**Files:**
- Create: `admin-frontend/src/utils/iban.ts`

**Step 1: Create the utility**

```typescript
/**
 * IBAN validation using ISO 7064 Mod 97-10 checksum.
 * Validates format (2-letter country + 2 check digits + BBAN) and verifies checksum.
 */

/**
 * Normalize an IBAN: strip whitespace, uppercase.
 */
export function normalizeIban(iban: string): string {
  return iban.replace(/\s/g, '').toUpperCase()
}

/**
 * Validate an IBAN using Mod 97-10 checksum (ISO 13616).
 *
 * Steps:
 * 1. Normalize (strip spaces, uppercase)
 * 2. Check format: 2 letters + 2 digits + 11-30 alphanumeric chars (total 15-34)
 * 3. Move first 4 chars to end
 * 4. Replace letters with numbers (A=10, B=11, ..., Z=35)
 * 5. Compute mod 97 — must equal 1
 */
export function validateIban(iban: string): boolean {
  const normalized = normalizeIban(iban)

  // Format check: country (2 letters) + check digits (2 digits) + BBAN (11-30 alphanum)
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(normalized)) {
    return false
  }

  // Rearrange: move first 4 chars to end
  const rearranged = normalized.slice(4) + normalized.slice(0, 4)

  // Replace letters with numbers (A=10 .. Z=35)
  const numeric = rearranged.replace(/[A-Z]/g, (ch) =>
    (ch.charCodeAt(0) - 55).toString()
  )

  // Mod 97 on large number (process in chunks to avoid BigInt)
  let remainder = 0
  for (let i = 0; i < numeric.length; i++) {
    remainder = (remainder * 10 + parseInt(numeric[i], 10)) % 97
  }

  return remainder === 1
}
```

**Step 2: Verify manually**

Run: `cd admin-frontend && npx tsx -e "import {validateIban} from './src/utils/iban'; console.log(validateIban('DE89370400440532013000'), validateIban('DE00000000000000000000'), validateIban('INVALID'))"`
Expected: `true false false`

**Step 3: Commit**

```bash
git add admin-frontend/src/utils/iban.ts
git commit -m "feat: add shared IBAN validation utility with Mod 97 checksum"
```

---

### Task 2: Add IBAN Validation to Members Form (MembersPage)

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`
- Modify: `admin-frontend/public/locales/en.json`
- Modify: `admin-frontend/public/locales/de.json`

**Step 1: Add i18n keys**

Add to `en.json` under `members.validation`:
```json
"invalidIban": "Invalid IBAN (check country code and digits)"
```

Add to `de.json` under `members.validation`:
```json
"invalidIban": "Ungültige IBAN (Ländercode und Ziffern prüfen)"
```

**Step 2: Add ValidationIndicator and client-side validation to MembersPage**

In `MembersPage.tsx`:

1. Add import at top:
```typescript
import { validateIban } from '../utils/iban'
import { ValidationIndicator } from '../components/forms/ValidationIndicator'
```

2. Add client-side IBAN validation in `handleSubmit`, right after `setFormErrors({})` (line ~212), before the payload building:
```typescript
      // Client-side IBAN validation
      if (formData.iban && !validateIban(formData.iban)) {
        setFormErrors({ iban: t('members.validation.invalidIban') })
        setIsLoading(false)
        return
      }
```

3. Update the IBAN label to include a ValidationIndicator (after the `</label>` close, before the `<input>`). Change the IBAN `<div>` block (lines ~1308-1332) to add the indicator in the label and an error message after the input:

Replace the label line:
```tsx
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.iban')} <span style={{ color: theme.colors.semantic.danger }}>*</span> <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')})</span>
                </label>
```

With:
```tsx
                <label style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.sm, marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.iban')} <span style={{ color: theme.colors.semantic.danger }}>*</span> <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')})</span>
                  <ValidationIndicator
                    isValid={validateIban(formData.iban)}
                    show={formData.iban.length > 0}
                    testId="members-form-iban-validation"
                  />
                </label>
```

4. After the IBAN `<input>` closing tag and before the closing `</div>`, add the error message (same pattern as card_uid error at line ~1296):
```tsx
                {formErrors.iban && (
                  <p data-testid="members-form-iban-error" style={{ color: theme.colors.semantic.danger, fontSize: theme.typography.fontSize.sm, marginTop: theme.spacing.xs }}>
                    {formErrors.iban}
                  </p>
                )}
```

5. Update the IBAN input border to show red when there's an error — change the border style from:
```typescript
border: `1px solid ${theme.colors.border.light}`,
```
to:
```typescript
border: `1px solid ${formErrors.iban ? theme.colors.semantic.danger : theme.colors.border.light}`,
```

**Step 3: Verify visually**

Open `http://localhost:5173/members`, click "Create Member", type an IBAN:
- `DE89370400440532013000` → green checkmark
- `DE00000000000000000000` → red X
- Try submitting with invalid IBAN → error message appears, form stays open

**Step 4: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx admin-frontend/public/locales/en.json admin-frontend/public/locales/de.json
git commit -m "feat: add inline IBAN validation to member form with Mod 97 checksum"
```

---

### Task 3: Replace Regex-Only IBAN Validation in SettingsPage

**Files:**
- Modify: `admin-frontend/src/pages/SettingsPage.tsx`

**Step 1: Replace the local validateIban with the shared utility**

1. Add import at top:
```typescript
import { validateIban } from '../utils/iban'
```

2. Remove the local `validateIban` function (lines ~323-327):
```typescript
  // Validate IBAN format (basic client-side validation)
  const validateIban = (iban: string): boolean => {
    if (!iban) return false
    return /^[A-Z]{2}[0-9A-Z]{13,32}$/.test(iban.toUpperCase())
  }
```

The rest of the code (line ~341 `validateIban(formData.creditor_iban)` and line ~572 `validateIban={validateIban}`) automatically uses the imported function.

**Step 2: Verify visually**

Open `http://localhost:5173/settings`, SEPA tab:
- Type a valid IBAN → green checkmark
- Type an invalid IBAN → red X, cannot save

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/SettingsPage.tsx
git commit -m "refactor: use shared IBAN validation with Mod 97 checksum in SEPA settings"
```

---

### Task 4: Add Backend IBAN Validation Rule

**Files:**
- Modify: `backend/src/Shared/Validation/Validator.php`
- Modify: `backend/src/Modules/Members/Controllers/AdminController.php`

**Step 1: Add `iban` rule to Validator**

In `Validator.php`, add a new case in the `check()` match expression (line ~65, before `'unique'`):

```php
            'iban'     => $this->validateIban($field, $value),
```

Add the private method:

```php
    /**
     * Validate IBAN using ISO 7064 Mod 97-10 checksum.
     */
    private function validateIban(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null; // nullable — let 'required' handle presence
        }

        $iban = strtoupper(str_replace(' ', '', (string)$value));

        // Format: 2 letters + 2 digits + 11-30 alphanumeric
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return "{$field} must be a valid IBAN";
        }

        // Rearrange: move first 4 chars to end
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        // Replace letters with numbers (A=10 .. Z=35)
        $numeric = '';
        for ($i = 0; $i < strlen($rearranged); $i++) {
            $ch = $rearranged[$i];
            if (ctype_alpha($ch)) {
                $numeric .= (string)(ord($ch) - 55);
            } else {
                $numeric .= $ch;
            }
        }

        // Mod 97 using bcmod for arbitrary precision
        if (bcmod($numeric, '97') !== '1') {
            return "{$field} must be a valid IBAN";
        }

        return null;
    }
```

**Step 2: Add IBAN validation to member create**

In `AdminController.php` (Members), add `'iban'` to the validation rules in `store()` method (line ~94):

```php
            'iban' => ['nullable', 'string', 'iban'],
```

**Step 3: Add IBAN validation to member update**

In `AdminController.php` (Members), add IBAN validation in `update()` method, after the `preferred_language` validation block (line ~154):

```php
        // Validate IBAN if provided
        if (isset($body['iban']) && $body['iban'] !== null && $body['iban'] !== '') {
            if (!$this->validator->validate($body, [
                'iban' => ['string', 'iban'],
            ])) {
                return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
            }
        }
```

**Step 4: Restart PHP and test**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2

# Test invalid IBAN rejected
curl -s -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -d '{"first_name":"Test","last_name":"User","email":"test@test.com","preferred_language":"de","iban":"DE00000000000000000000"}' | jq .

# Expected: 422 with "iban must be a valid IBAN"

# Test valid IBAN accepted (will fail on other validation, but IBAN passes)
curl -s -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -d '{"first_name":"Test","last_name":"User","email":"test@test.com","preferred_language":"de","iban":"DE89370400440532013000"}' | jq .

# Expected: 201 (or different error, but NOT iban validation error)
```

**Step 5: Commit**

```bash
git add backend/src/Shared/Validation/Validator.php backend/src/Modules/Members/Controllers/AdminController.php
git commit -m "feat: add IBAN Mod 97 validation rule to backend Validator and member endpoints"
```

---

### Task 5: Add Page Object Methods for IBAN Validation

**Files:**
- Modify: `e2etests/pages/MembersPage.ts`
- Modify: `e2etests/pages/SettingsPage.ts`

**Step 1: Add IBAN validation methods to MembersPage**

Add private locators after `cardUidDuplicateError` (line ~63):

```typescript
  // IBAN validation
  private readonly ibanValidationIndicator = () => this.page.getByTestId('members-form-iban-validation')
  private readonly ibanError = () => this.page.getByTestId('members-form-iban-error')
```

Add public methods in the "FORM FIELD HELPERS" section (after `fillMandateReference`, line ~346):

```typescript
  async expectIbanValidVisible() {
    await expect(this.ibanValidationIndicator()).toBeVisible()
    await expect(this.ibanValidationIndicator()).toContainText('✓')
  }

  async expectIbanInvalidVisible() {
    await expect(this.ibanValidationIndicator()).toBeVisible()
    await expect(this.ibanValidationIndicator()).toContainText('✗')
  }

  async expectIbanValidationHidden() {
    await expect(this.ibanValidationIndicator()).not.toBeVisible()
  }

  async expectIbanErrorVisible() {
    await expect(this.ibanError()).toBeVisible()
  }

  async expectIbanErrorHidden() {
    await expect(this.ibanError()).not.toBeVisible()
  }

  async getIbanErrorText(): Promise<string> {
    return await this.ibanError().textContent() || ''
  }
```

**Step 2: Add IBAN validation methods to SettingsPage**

Add private locator after `loadingIndicator` (line ~26):

```typescript
  private readonly ibanValidationIndicator: Locator
```

Initialize in constructor (after line ~44):

```typescript
    this.ibanValidationIndicator = page.getByTestId('settings-sepa-validation-creditor_iban')
```

Add public methods (after `expectSepaTabVisible`, line ~271):

```typescript
  async expectIbanValidIndicator() {
    await expect(this.ibanValidationIndicator).toBeVisible()
    await expect(this.ibanValidationIndicator).toContainText('✓')
  }

  async expectIbanInvalidIndicator() {
    await expect(this.ibanValidationIndicator).toBeVisible()
    await expect(this.ibanValidationIndicator).toContainText('✗')
  }
```

**Step 3: Commit**

```bash
git add e2etests/pages/MembersPage.ts e2etests/pages/SettingsPage.ts
git commit -m "feat: add IBAN validation page object methods for E2E tests"
```

---

### Task 6: E2E Tests — Member IBAN Validation

**Files:**
- Modify: `e2etests/tests/admin/members.spec.ts`

**Step 1: Add IBAN validation test**

Add a new test after the existing `card UID validation` test (line ~298), inside the `describe` block:

```typescript
  test('IBAN validation: inline indicator, checksum rejection, valid IBAN accepted', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // ── Empty IBAN → no validation indicator shown ──────────────────
    await authenticatedMembersPage.expectIbanValidationHidden()

    // ── Invalid IBAN (bad checksum) → red X indicator ───────────────
    const ibanInput = authenticatedMembersPage['page'].getByTestId('members-form-iban-input')
    await ibanInput.fill('DE00000000000000000000')
    await authenticatedMembersPage.expectIbanInvalidVisible()

    // ── Try submitting invalid IBAN → form stays open, error shown ──
    await authenticatedMembersPage.fillMemberForm(
      `IVal${ts}`, `Last${ts}`, 'DE00000000000000000000', '2025-01-01',
      `ival-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.expectIbanErrorVisible()

    // ── Valid IBAN → green checkmark ─────────────────────────────────
    await ibanInput.fill('DE89370400440532013000')
    await authenticatedMembersPage.expectIbanValidVisible()
    await authenticatedMembersPage.expectIbanErrorHidden()

    // ── Pasted IBAN with spaces → normalized and valid ──────────────
    await ibanInput.fill('DE89 3704 0044 0532 0130 00')
    const normalizedValue = await authenticatedMembersPage.getFormIbanValue()
    expect(normalizedValue).toBe('DE89370400440532013000')
    await authenticatedMembersPage.expectIbanValidVisible()

    // ── Submit with valid IBAN → succeeds ────────────────────────────
    await authenticatedMembersPage.fillMemberForm(
      `IVal${ts}`, `Last${ts}`, 'DE89370400440532013000', '2025-01-01',
      `ival-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // ── Verify member created ────────────────────────────────────────
    await authenticatedMembersPage.search(`IVal${ts}`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`IVal${ts}`)
  })
```

**Step 2: Run the test**

```bash
cd e2etests && npm test -- --grep "IBAN validation" --workers=1
```

Expected: PASS

**Step 3: Run with default workers to verify parallel safety**

```bash
cd e2etests && npm test -- tests/admin/members.spec.ts --workers=4
```

Expected: All tests PASS

**Step 4: Commit**

```bash
git add e2etests/tests/admin/members.spec.ts
git commit -m "test: add E2E tests for member IBAN validation (inline indicator, checksum, normalization)"
```

---

### Task 7: E2E Tests — SEPA Config IBAN Validation

**Files:**
- Modify: `e2etests/tests/admin/settings-sepa-config.spec.ts`

**Step 1: Add IBAN validation test**

Add a new test inside the `describe` block:

```typescript
  test('should show IBAN validation indicator for valid and invalid IBANs', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSepaTab()

    // Enter invalid IBAN (bad checksum) → red X
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_iban: 'DE00000000000000000000',
    })
    await authenticatedSettingsPage.expectIbanInvalidIndicator()

    // Enter valid IBAN → green checkmark
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_iban: 'DE89370400440532013000',
    })
    await authenticatedSettingsPage.expectIbanValidIndicator()

    // IBAN with spaces → normalized (stripped by onChange handler)
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_iban: 'DE89 3704 0044 0532 0130 00',
    })
    const normalizedIban = await authenticatedSettingsPage.getIbanValue()
    expect(normalizedIban).toBe('DE89370400440532013000')
    await authenticatedSettingsPage.expectIbanValidIndicator()

    // Cancel to avoid persisting test data
    await authenticatedSettingsPage.cancel()
  })
```

**Step 2: Update the IBAN in `generateTestSepaConfig` helper**

The existing helper generates random IBANs that won't pass Mod 97 checksum. Update the helper to use a known-valid IBAN:

Replace the IBAN generation (lines ~26-27):
```typescript
  const randomDigits = Math.random().toString().substring(2, 11) + Math.random().toString().substring(2, 11)
  const iban = `DE89${randomDigits.substring(0, 18)}`.substring(0, 22)
```

With a valid IBAN:
```typescript
  // Use a known-valid German IBAN (passes Mod 97 checksum)
  const iban = 'DE89370400440532013000'
```

**Step 3: Run the test**

```bash
cd e2etests && npm test -- --grep "IBAN validation indicator" --workers=1
```

Expected: PASS

**Step 4: Run full SEPA config test suite**

```bash
cd e2etests && npm test -- tests/admin/settings-sepa-config.spec.ts --workers=4
```

Expected: All tests PASS (including existing tests that now use valid IBAN)

**Step 5: Commit**

```bash
git add e2etests/tests/admin/settings-sepa-config.spec.ts
git commit -m "test: add E2E test for SEPA config IBAN validation indicator and fix test helper IBAN"
```

---

### Task 8: Run Full Test Suite

**Step 1: Restart PHP (ensure all backend changes are loaded)**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
```

**Step 2: Run full E2E suite**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: All tests PASS

**Step 3: If failures, debug with 1 worker**

```bash
cd e2etests && npm test -- --workers=1
```

Fix any issues and re-run.
