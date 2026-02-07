# RFID Member Identification Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable member identification via RFID/NFC tokens at terminal and admin UI management

**Architecture:** Backend filter support → Admin UI (form field + filters + table column + i18n) → Terminal RFID scanning

**Tech Stack:** PHP 8.3, React 18, TypeScript, Playwright E2E, Flutter/Dart

**Design Document:** `/Users/dg/dev/frgs-verenigsbar/docs/plans/2026-02-07-rfid-member-identification-design.md`

---

## Phase 1: Backend - Card UID Filter Support

### Task 1.1: Add has_card_uid filter to MembersRepository

**Files:**
- Modify: `backend/src/Modules/Members/Repositories/MembersRepository.php:126-162`
- Test: `e2etests/tests/api/members.spec.ts`

**Step 1: Write failing E2E test for card_uid filter**

Add to `e2etests/tests/api/members.spec.ts`:

```typescript
test('GET /admin/members?filters[has_card_uid]=true returns only members with card_uid', async ({ request, adminAuthHeaders }) => {
  const testId = `CardFilter${Date.now()}`

  // Create member WITH card_uid
  const memberWith = await request.post('http://localhost:8080/api/admin/members', {
    headers: adminAuthHeaders,
    data: {
      first_name: `${testId}With`,
      last_name: 'Test',
      email: `${testId}with@test.com`,
      iban: 'DE89370400440532013000',
      mandate_reference: `MAN${testId}W`,
      mandate_signed_at: '2024-01-15',
      preferred_language: 'de',
      card_uid: '0003195661'
    }
  })
  expect(memberWith.ok()).toBeTruthy()
  const memberWithData = await memberWith.json()

  // Create member WITHOUT card_uid
  const memberWithout = await request.post('http://localhost:8080/api/admin/members', {
    headers: adminAuthHeaders,
    data: {
      first_name: `${testId}Without`,
      last_name: 'Test',
      email: `${testId}without@test.com`,
      iban: 'DE89370400440532013001',
      mandate_reference: `MAN${testId}WO`,
      mandate_signed_at: '2024-01-15',
      preferred_language: 'de'
    }
  })
  expect(memberWithout.ok()).toBeTruthy()
  const memberWithoutData = await memberWithout.json()

  // Filter: has_card_uid=true
  const responseWithCard = await request.get('http://localhost:8080/api/admin/members?filters[has_card_uid]=true', {
    headers: adminAuthHeaders
  })
  expect(responseWithCard.ok()).toBeTruthy()
  const dataWith = await responseWithCard.json()

  const withCardIds = dataWith.items.map((m: any) => m.id)
  expect(withCardIds).toContain(memberWithData.id)
  expect(withCardIds).not.toContain(memberWithoutData.id)

  // Filter: has_card_uid=false
  const responseWithoutCard = await request.get('http://localhost:8080/api/admin/members?filters[has_card_uid]=false', {
    headers: adminAuthHeaders
  })
  expect(responseWithoutCard.ok()).toBeTruthy()
  const dataWithout = await responseWithoutCard.json()

  const withoutCardIds = dataWithout.items.map((m: any) => m.id)
  expect(withoutCardIds).not.toContain(memberWithData.id)
  expect(withoutCardIds).toContain(memberWithoutData.id)
})
```

**Step 2: Run test to verify it fails**

```bash
cd e2etests
npm test -- tests/api/members.spec.ts --grep "has_card_uid" --workers=1
```

Expected: FAIL - Filter not implemented yet, returns all members

**Step 3: Implement hasCardUid filter in MembersRepository**

Edit `backend/src/Modules/Members/Repositories/MembersRepository.php`:

```php
public function listPaginated(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $search = null): array
{
    $where = [];
    $params = [];

    if (isset($filters['is_active'])) {
        $where[] = 'is_active = ?';
        $params[] = $filters['is_active'] ? 1 : 0;
    }
    if (isset($filters['language'])) {
        $where[] = 'preferred_language = ?';
        $params[] = $filters['language'];
    }
    // NEW: Card UID filter
    if (isset($filters['has_card_uid'])) {
        if ($filters['has_card_uid']) {
            $where[] = 'card_uid IS NOT NULL';
        } else {
            $where[] = 'card_uid IS NULL';
        }
    }
    if ($search) {
        $escaped = SafeQuery::escapeLike($search);
        $where[] = "(CONCAT(first_name, ' ', last_name) LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
        $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%", "%{$escaped}%", "%{$escaped}%"]);
    }

    // ... rest of method unchanged
}
```

**Step 4: Update AdminController to pass has_card_uid filter**

Edit `backend/src/Modules/Members/Controllers/AdminController.php:36-43`:

```php
// Support both filters[is_active] (nested) and is_active (direct) formats
$filters = [];
if (isset($params['filters']['is_active'])) {
    // Convert string "true"/"false" to boolean
    $filters['is_active'] = filter_var($params['filters']['is_active'], FILTER_VALIDATE_BOOLEAN);
} elseif (isset($params['is_active'])) {
    // Convert string "true"/"false" to boolean
    $filters['is_active'] = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
}

// NEW: Card UID filter
if (isset($params['filters']['has_card_uid'])) {
    $filters['has_card_uid'] = filter_var($params['filters']['has_card_uid'], FILTER_VALIDATE_BOOLEAN);
}
```

**Step 5: Restart PHP-FPM**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
```

**Step 6: Run test to verify it passes**

```bash
cd e2etests
npm test -- tests/api/members.spec.ts --grep "has_card_uid" --workers=1
```

Expected: PASS - Filter returns correct members

**Step 7: Run full test suite to ensure no regressions**

```bash
cd e2etests
npm test -- tests/api/members.spec.ts --workers=4
```

Expected: All tests PASS

**Step 8: Commit**

```bash
git add backend/src/Modules/Members/Repositories/MembersRepository.php
git add backend/src/Modules/Members/Controllers/AdminController.php
git add e2etests/tests/api/members.spec.ts
git commit -m "feat(backend): Add has_card_uid filter to members API

- Add has_card_uid filter parameter to MembersRepository
- Support filters[has_card_uid]=true/false in AdminController
- Add E2E test for card UID filtering

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 2: Admin UI - i18n Translations

### Task 2.1: Add i18n strings for card UID feature

**Files:**
- Modify: `admin-frontend/public/locales/en.json:97-150`
- Modify: `admin-frontend/public/locales/de.json:97-150`

**Step 1: Add English translations**

Edit `admin-frontend/public/locales/en.json`, add to `members` section:

```json
{
  "members": {
    "title": "Members",
    "createMember": "Create Member",
    "editMember": "Edit Member",
    "...": "...",
    "form": {
      "firstName": "First Name",
      "lastName": "Last Name",
      "email": "Email",
      "iban": "IBAN",
      "accountHolderName": "Account Holder Name",
      "mandateReference": "Mandate Reference",
      "mandateSignedAt": "Mandate Signed At",
      "preferredLanguage": "Preferred Language",
      "cardUid": "Card UID",
      "cardUidPlaceholder": "e.g., 0003195661"
    },
    "table": {
      "name": "Name",
      "email": "Email",
      "iban": "IBAN",
      "balance": "Balance",
      "status": "Status",
      "actions": "Actions",
      "cardUid": "Card UID"
    },
    "filters": {
      "status": {
        "all": "All",
        "active": "Active",
        "inactive": "Inactive"
      },
      "card": {
        "all": "All",
        "withCard": "With Card",
        "withoutCard": "Without Card"
      }
    },
    "validation": {
      "invalidCardUid": "Invalid card UID format (8-20 hex characters required)"
    }
  }
}
```

**Step 2: Add German translations**

Edit `admin-frontend/public/locales/de.json`, add to `members` section:

```json
{
  "members": {
    "title": "Mitglieder",
    "createMember": "Mitglied erstellen",
    "editMember": "Mitglied bearbeiten",
    "...": "...",
    "form": {
      "firstName": "Vorname",
      "lastName": "Nachname",
      "email": "E-Mail",
      "iban": "IBAN",
      "accountHolderName": "Kontoinhaber",
      "mandateReference": "Mandatsreferenz",
      "mandateSignedAt": "Mandat unterzeichnet am",
      "preferredLanguage": "Bevorzugte Sprache",
      "cardUid": "Karten-UID",
      "cardUidPlaceholder": "z.B. 0003195661"
    },
    "table": {
      "name": "Name",
      "email": "E-Mail",
      "iban": "IBAN",
      "balance": "Saldo",
      "status": "Status",
      "actions": "Aktionen",
      "cardUid": "Karten-UID"
    },
    "filters": {
      "status": {
        "all": "Alle",
        "active": "Aktiv",
        "inactive": "Inaktiv"
      },
      "card": {
        "all": "Alle",
        "withCard": "Mit Karte",
        "withoutCard": "Ohne Karte"
      }
    },
    "validation": {
      "invalidCardUid": "Ungültiges Karten-UID-Format (8-20 Hex-Zeichen erforderlich)"
    }
  }
}
```

**Step 3: Commit i18n changes**

```bash
git add admin-frontend/public/locales/en.json
git add admin-frontend/public/locales/de.json
git commit -m "feat(admin-ui): Add i18n translations for card UID feature

- Add card UID form field labels (EN/DE)
- Add card filter labels (EN/DE)
- Fix missing status filter labels (All/Active/Inactive)
- Add card UID validation message

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 3: Admin UI - Card UID Form Field

### Task 3.1: Add card_uid field to member form

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx:54-63` (formData state)
- Modify: `admin-frontend/src/pages/MembersPage.tsx:200-350` (form modal JSX)
- Test: `e2etests/tests/admin/members.spec.ts`

**Step 1: Write failing E2E test for card_uid form field**

Add to `e2etests/tests/admin/members.spec.ts`:

```typescript
test('should create member with card_uid and display in list', async ({ page, authenticatedMembersPage }) => {
  const testId = `CardUID${Date.now()}`
  const cardUid = '0003195661'

  await authenticatedMembersPage.clickCreateMember()

  // Fill form including card_uid
  await page.fill('[data-testid="member-form-first-name"]', testId)
  await page.fill('[data-testid="member-form-last-name"]', 'Test')
  await page.fill('[data-testid="member-form-email"]', `${testId}@test.com`)
  await page.fill('[data-testid="member-form-iban"]', 'DE89370400440532013000')
  await page.fill('[data-testid="member-form-mandate-reference"]', `MAN${testId}`)
  await page.fill('[data-testid="member-form-mandate-signed-at"]', '2024-01-15')
  await page.fill('[data-testid="member-form-card-uid"]', cardUid)
  await page.selectOption('[data-testid="member-form-preferred-language"]', 'de')

  await page.click('[data-testid="member-form-submit"]')

  // Wait for modal to close
  await page.waitForSelector('[data-testid="member-form-modal"]', { state: 'hidden' })

  // Verify card_uid appears in table
  const row = page.locator('tr', { hasText: testId })
  await expect(row.locator('[data-testid="member-card-uid"]')).toContainText(cardUid)
})

test('should validate card_uid format', async ({ page, authenticatedMembersPage }) => {
  await authenticatedMembersPage.clickCreateMember()

  // Try invalid format (too short)
  await page.fill('[data-testid="member-form-card-uid"]', '123')
  await page.blur('[data-testid="member-form-card-uid"]')

  await expect(page.locator('text=Invalid card UID format')).toBeVisible()

  // Try invalid format (non-hex characters)
  await page.fill('[data-testid="member-form-card-uid"]', '00031956XY')
  await page.blur('[data-testid="member-form-card-uid"]')

  await expect(page.locator('text=Invalid card UID format')).toBeVisible()

  // Try valid format
  await page.fill('[data-testid="member-form-card-uid"]', '0003195661')
  await page.blur('[data-testid="member-form-card-uid"]')

  await expect(page.locator('text=Invalid card UID format')).not.toBeVisible()
})
```

**Step 2: Run test to verify it fails**

```bash
cd e2etests
npm test -- tests/admin/members.spec.ts --grep "card_uid" --workers=1
```

Expected: FAIL - Field not in form yet

**Step 3: Add card_uid to formData state**

Edit `admin-frontend/src/pages/MembersPage.tsx:54-63`:

```typescript
const [formData, setFormData] = useState({
  first_name: '',
  last_name: '',
  email: '',
  iban: '',
  account_holder_name: '',
  mandate_reference: '',
  mandate_signed_at: '',
  preferred_language: 'de',
  card_uid: '', // NEW
})
```

**Step 4: Add card_uid input field to form modal JSX**

Find the form modal section in `admin-frontend/src/pages/MembersPage.tsx` (around line 200-350), add after email field:

```tsx
{/* Card UID */}
<div>
  <label
    htmlFor="card_uid"
    style={{
      display: 'block',
      marginBottom: theme.spacing.xs,
      fontSize: theme.fontSize.sm,
      fontWeight: 500,
      color: theme.colors.gray[700],
    }}
  >
    {t('members.form.cardUid')}
    <span style={{ color: theme.colors.gray[400], marginLeft: theme.spacing.xs }}>
      ({t('common.optional')})
    </span>
  </label>
  <input
    id="card_uid"
    type="text"
    data-testid="member-form-card-uid"
    value={formData.card_uid}
    onChange={(e) => {
      const value = e.target.value.toUpperCase().replace(/[^0-9A-F]/g, '')
      setFormData({ ...formData, card_uid: value })
    }}
    placeholder={t('members.form.cardUidPlaceholder')}
    maxLength={20}
    style={{
      width: '100%',
      padding: theme.spacing.sm,
      border: `1px solid ${theme.colors.gray[300]}`,
      borderRadius: theme.borderRadius.md,
      fontSize: theme.fontSize.base,
    }}
  />
  {formData.card_uid && !/^[0-9A-F]{8,20}$/.test(formData.card_uid) && (
    <p style={{ color: theme.colors.red[600], fontSize: theme.fontSize.sm, marginTop: theme.spacing.xs }}>
      {t('members.validation.invalidCardUid')}
    </p>
  )}
</div>
```

**Step 5: Update createMember/updateMember calls to include card_uid**

Find `handleSubmit` function, update to include `card_uid`:

```typescript
const handleSubmit = async (e: React.FormEvent) => {
  e.preventDefault()
  // ... existing code ...

  if (editingMember) {
    await updateMember(editingMember.id, {
      ...formData,
      card_uid: formData.card_uid || undefined, // Send undefined if empty
    })
  } else {
    await createMember({
      ...formData,
      card_uid: formData.card_uid || undefined, // Send undefined if empty
    })
  }

  // ... rest of code ...
}
```

**Step 6: Update form reset to include card_uid**

Find `setFormData` reset calls (after create/edit), ensure `card_uid: ''` is included.

**Step 7: Run test to verify form field works**

```bash
cd e2etests
npm test -- tests/admin/members.spec.ts --grep "card_uid" --workers=1
```

Expected: PASS (form field exists and validates, but table column still missing)

**Step 8: Commit form field changes**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(admin-ui): Add card UID input field to member form

- Add card_uid to formData state
- Add card UID text input with validation (8-20 hex chars)
- Auto-convert input to uppercase and strip non-hex chars
- Display validation error for invalid format
- Include card_uid in create/update API calls

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 4: Admin UI - Card UID Table Column

### Task 4.1: Add card_uid column to members table

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx:400-600` (table JSX)
- Test: `e2etests/tests/admin/members.spec.ts`

**Step 1: Write failing E2E test for card_uid table column**

Already written in Task 3.1 (checks for `[data-testid="member-card-uid"]`)

**Step 2: Run test to verify it fails**

```bash
cd e2etests
npm test -- tests/admin/members.spec.ts --grep "should create member with card_uid" --workers=1
```

Expected: FAIL - Column not in table yet

**Step 3: Add card_uid column header**

Find the table header row in `admin-frontend/src/pages/MembersPage.tsx` (around line 400), add after "Name" column:

```tsx
<th style={{ ...headerCellBaseStyle, width: '150px' }}>
  <SortableTableHeader
    label={t('members.table.cardUid')}
    sortKey="card_uid"
    currentSortKey={sortKey}
    currentSortDirection={sortDirection}
    onSort={(key, direction) => {
      setSortKey(key as any)
      setSortDirection(direction)
    }}
  />
</th>
```

**Step 4: Add card_uid cell to table rows**

Find the table body row mapping (around line 500), add after name cell:

```tsx
<TableCell data-testid="member-card-uid">
  {member.card_uid || '—'}
</TableCell>
```

**Step 5: Update sortKey type to include card_uid**

Find the sortKey state declaration:

```typescript
const [sortKey, setSortKey] = useState<'first_name' | 'last_name' | 'created_at' | 'card_uid'>('created_at')
```

**Step 6: Run test to verify table column appears**

```bash
cd e2etests
npm test -- tests/admin/members.spec.ts --grep "should create member with card_uid" --workers=1
```

Expected: PASS - Card UID displayed in table

**Step 7: Commit table column changes**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(admin-ui): Add card UID column to members table

- Add sortable card_uid column header
- Display card_uid value or '—' if null
- Position after Name column
- Update sortKey type to include card_uid

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 5: Admin UI - Card Filter Pills

### Task 5.1: Add card filter pills (With Card / Without Card)

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx:53` (state)
- Modify: `admin-frontend/src/pages/MembersPage.tsx:72-78` (filter logic)
- Modify: `admin-frontend/src/pages/MembersPage.tsx:300-350` (filter pills JSX)
- Test: `e2etests/tests/admin/members-sort-filter.spec.ts`

**Step 1: Write failing E2E test for card filter pills**

Add to `e2etests/tests/admin/members-sort-filter.spec.ts`:

```typescript
test('should filter members by card assignment (With Card / Without Card)', async ({ page, request, adminAuthHeaders }) => {
  const testId = `CardFilter${Date.now()}`

  // Create member WITH card
  await request.post('http://localhost:8080/api/admin/members', {
    headers: adminAuthHeaders,
    data: {
      first_name: `${testId}With`,
      last_name: 'Test',
      email: `${testId}with@test.com`,
      iban: 'DE89370400440532013000',
      mandate_reference: `MAN${testId}W`,
      mandate_signed_at: '2024-01-15',
      preferred_language: 'de',
      card_uid: '1111222233'
    }
  })

  // Create member WITHOUT card
  await request.post('http://localhost:8080/api/admin/members', {
    headers: adminAuthHeaders,
    data: {
      first_name: `${testId}Without`,
      last_name: 'Test',
      email: `${testId}without@test.com`,
      iban: 'DE89370400440532013001',
      mandate_reference: `MAN${testId}WO`,
      mandate_signed_at: '2024-01-15',
      preferred_language: 'de'
    }
  })

  await page.goto('http://localhost:5173/members')
  await page.waitForLoadState('networkidle')

  // Click "With Card" filter
  await page.click('[data-testid="filter-card-with"]')
  await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

  // Verify only "With" member shown
  await expect(page.locator('text=' + testId + 'With')).toBeVisible()
  await expect(page.locator('text=' + testId + 'Without')).not.toBeVisible()

  // Click "Without Card" filter
  await page.click('[data-testid="filter-card-without"]')
  await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

  // Verify only "Without" member shown
  await expect(page.locator('text=' + testId + 'With')).not.toBeVisible()
  await expect(page.locator('text=' + testId + 'Without')).toBeVisible()

  // Click "All" to reset
  await page.click('[data-testid="filter-card-all"]')
  await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

  // Verify both shown
  await expect(page.locator('text=' + testId + 'With')).toBeVisible()
  await expect(page.locator('text=' + testId + 'Without')).toBeVisible()
})
```

**Step 2: Run test to verify it fails**

```bash
cd e2etests
npm test -- tests/admin/members-sort-filter.spec.ts --grep "card assignment" --workers=1
```

Expected: FAIL - Filter pills not in UI yet

**Step 3: Add filterCardUid state**

Edit `admin-frontend/src/pages/MembersPage.tsx:53`:

```typescript
const [filterIsActive, setFilterIsActive] = useState<'all' | 'active' | 'inactive'>('all')
const [filterCardUid, setFilterCardUid] = useState<'all' | 'with' | 'without'>('all') // NEW
```

**Step 4: Update filter logic to include has_card_uid**

Edit `admin-frontend/src/pages/MembersPage.tsx:72-78`:

```typescript
// Build filter object
const filter: { is_active?: boolean; has_card_uid?: boolean } = {}
if (filterIsActive === 'active') {
  filter.is_active = true
} else if (filterIsActive === 'inactive') {
  filter.is_active = false
}
if (filterCardUid === 'with') {
  filter.has_card_uid = true
} else if (filterCardUid === 'without') {
  filter.has_card_uid = false
}

const response = await getMembers(page, 20, search || undefined, filter, sortKey, sortDirection)
```

**Step 5: Update useEffect dependency array**

Add `filterCardUid` to dependency array:

```typescript
}, [page, search, filterIsActive, filterCardUid, sortKey, sortDirection, setIsLoading])
```

**Step 6: Add card filter pills to JSX**

Find the existing status filter pills (around line 300), add new card filter section below:

```tsx
{/* Existing status filter */}
<StatusFilterPills
  value={filterIsActive}
  onChange={setFilterIsActive}
  labels={{
    all: t('members.filters.status.all'),
    active: t('members.filters.status.active'),
    inactive: t('members.filters.status.inactive'),
  }}
/>

{/* NEW: Card filter */}
<div style={{ marginTop: theme.spacing.md }}>
  <label
    style={{
      display: 'block',
      marginBottom: theme.spacing.sm,
      fontSize: theme.fontSize.sm,
      fontWeight: 500,
      color: theme.colors.gray[700],
    }}
  >
    {t('members.table.cardUid')}:
  </label>
  <div style={{ display: 'flex', gap: theme.spacing.sm }}>
    <button
      data-testid="filter-card-all"
      onClick={() => setFilterCardUid('all')}
      style={{
        padding: `${theme.spacing.xs} ${theme.spacing.md}`,
        border: `1px solid ${filterCardUid === 'all' ? theme.colors.primary[600] : theme.colors.gray[300]}`,
        backgroundColor: filterCardUid === 'all' ? theme.colors.primary[50] : 'white',
        color: filterCardUid === 'all' ? theme.colors.primary[700] : theme.colors.gray[700],
        borderRadius: theme.borderRadius.full,
        fontSize: theme.fontSize.sm,
        fontWeight: 500,
        cursor: 'pointer',
      }}
    >
      {t('members.filters.card.all')}
    </button>
    <button
      data-testid="filter-card-with"
      onClick={() => setFilterCardUid('with')}
      style={{
        padding: `${theme.spacing.xs} ${theme.spacing.md}`,
        border: `1px solid ${filterCardUid === 'with' ? theme.colors.primary[600] : theme.colors.gray[300]}`,
        backgroundColor: filterCardUid === 'with' ? theme.colors.primary[50] : 'white',
        color: filterCardUid === 'with' ? theme.colors.primary[700] : theme.colors.gray[700],
        borderRadius: theme.borderRadius.full,
        fontSize: theme.fontSize.sm,
        fontWeight: 500,
        cursor: 'pointer',
      }}
    >
      {t('members.filters.card.withCard')}
    </button>
    <button
      data-testid="filter-card-without"
      onClick={() => setFilterCardUid('without')}
      style={{
        padding: `${theme.spacing.xs} ${theme.spacing.md}`,
        border: `1px solid ${filterCardUid === 'without' ? theme.colors.primary[600] : theme.colors.gray[300]}`,
        backgroundColor: filterCardUid === 'without' ? theme.colors.primary[50] : 'white',
        color: filterCardUid === 'without' ? theme.colors.primary[700] : theme.colors.gray[700],
        borderRadius: theme.borderRadius.full,
        fontSize: theme.fontSize.sm,
        fontWeight: 500,
        cursor: 'pointer',
      }}
    >
      {t('members.filters.card.withoutCard')}
    </button>
  </div>
</div>
```

**Step 7: Update getMembers service to accept has_card_uid filter**

Edit `admin-frontend/src/services/members.ts:40`:

```typescript
export async function getMembers(
  page: number = 1,
  perPage: number = 20,
  search?: string,
  filter?: { is_active?: boolean; has_card_uid?: boolean }, // UPDATE type
  sort: string = 'created_at',
  order: 'asc' | 'desc' = 'desc'
): Promise<MembersResponse> {
  const params: Record<string, any> = {
    page,
    per_page: perPage,
    sort,
    order,
  }

  if (search) {
    params.search = search
  }

  // Pass filters as nested object
  if (filter?.is_active !== undefined) {
    params['filters[is_active]'] = filter.is_active ? 'true' : 'false'
  }
  // NEW: has_card_uid filter
  if (filter?.has_card_uid !== undefined) {
    params['filters[has_card_uid]'] = filter.has_card_uid ? 'true' : 'false'
  }

  // ... rest unchanged
}
```

**Step 8: Run test to verify filter pills work**

```bash
cd e2etests
npm test -- tests/admin/members-sort-filter.spec.ts --grep "card assignment" --workers=1
```

Expected: PASS - Filters work correctly

**Step 9: Commit filter pills**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git add admin-frontend/src/services/members.ts
git add e2etests/tests/admin/members-sort-filter.spec.ts
git commit -m "feat(admin-ui): Add card filter pills (With Card / Without Card)

- Add filterCardUid state (all/with/without)
- Add card filter pills UI with i18n labels
- Update getMembers to pass has_card_uid filter
- Add E2E test for card filtering

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 6: Admin UI - Fix Status Filter i18n

### Task 6.1: Replace hardcoded status filter labels with i18n

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx:300-320` (status filter pills)

**Step 1: Find existing StatusFilterPills component**

Locate the StatusFilterPills usage in MembersPage.tsx.

**Step 2: Update to use i18n labels**

Replace:

```tsx
<StatusFilterPills
  value={filterIsActive}
  onChange={setFilterIsActive}
/>
```

With:

```tsx
<StatusFilterPills
  value={filterIsActive}
  onChange={setFilterIsActive}
  labels={{
    all: t('members.filters.status.all'),
    active: t('members.filters.status.active'),
    inactive: t('members.filters.status.inactive'),
  }}
/>
```

**Step 3: Check if StatusFilterPills supports labels prop**

Read `admin-frontend/src/components/forms/StatusFilterPills.tsx` to verify it accepts `labels` prop. If not, add it:

```typescript
interface StatusFilterPillsProps {
  value: 'all' | 'active' | 'inactive'
  onChange: (value: 'all' | 'active' | 'inactive') => void
  labels?: {
    all: string
    active: string
    inactive: string
  }
}

export function StatusFilterPills({ value, onChange, labels }: StatusFilterPillsProps) {
  const defaultLabels = {
    all: 'All',
    active: 'Active',
    inactive: 'Inactive',
  }

  const displayLabels = labels || defaultLabels

  // Use displayLabels.all, displayLabels.active, displayLabels.inactive in JSX
}
```

**Step 4: Test i18n switching**

Manually test:
1. Open admin UI in browser
2. Switch language to German
3. Verify status filters show "Alle", "Aktiv", "Inaktiv"
4. Switch to English
5. Verify status filters show "All", "Active", "Inactive"

**Step 5: Commit i18n fix**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git add admin-frontend/src/components/forms/StatusFilterPills.tsx  # if modified
git commit -m "fix(admin-ui): Replace hardcoded status filter labels with i18n

- Add labels prop to StatusFilterPills component
- Pass translated labels from MembersPage
- Fixes German UI showing English filter labels

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 7: Terminal - RFID Scanning (Out of Scope)

**Note**: Terminal implementation is deferred. The terminal already syncs `card_uid` from backend and caches it locally. RFID scanning logic (hidden input field, member lookup) will be implemented in a future phase once admin UI is validated in production.

**Why deferred:**
- Admin UI must be deployed first to assign card UIDs to members
- RFID reader hardware setup and testing requires physical access
- Terminal lookup logic is straightforward once data is in place

**Design reference**: See `/Users/dg/dev/frgs-verenigsbar/docs/plans/2026-02-07-rfid-member-identification-design.md` Section 2 for terminal implementation details.

---

## Verification & Completion

### Task 8.1: Run full test suite

**Step 1: Run all backend API tests**

```bash
cd e2etests
npm test -- tests/api/members.spec.ts --workers=4
```

Expected: All tests PASS

**Step 2: Run all admin frontend tests**

```bash
cd e2etests
npm test -- tests/admin/members.spec.ts --workers=4
npm test -- tests/admin/members-sort-filter.spec.ts --workers=4
```

Expected: All tests PASS

**Step 3: Run regression tests**

```bash
cd e2etests
npm test -- --workers=4
```

Expected: All tests PASS (no regressions)

**Step 4: Manual verification checklist**

- [ ] Create member with card UID via admin UI
- [ ] Edit member to add/change card UID
- [ ] Filter members "With Card" → see only members with card_uid
- [ ] Filter members "Without Card" → see only members without card_uid
- [ ] Sort by card UID column
- [ ] Switch language to German → verify all labels translated
- [ ] Verify card UID validation (8-20 hex chars)
- [ ] Verify backend API returns card_uid in sync response

**Step 5: Create final commit summarizing completion**

```bash
git add .
git commit -m "feat(rfid): Complete RFID member identification (admin UI + backend)

Backend:
- Add has_card_uid filter to members API

Admin UI:
- Add card UID form field with validation
- Add card UID table column (sortable)
- Add card filter pills (All/With Card/Without Card)
- Fix status filter i18n (All/Active/Inactive)
- Add complete i18n translations (EN/DE)

E2E Tests:
- Backend filter tests (has_card_uid=true/false)
- Admin UI form field tests (create/edit with card_uid)
- Admin UI filter tests (card assignment filtering)

Terminal implementation deferred to future phase.

Design: docs/plans/2026-02-07-rfid-member-identification-design.md

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Success Criteria

- ✅ Backend filter `filters[has_card_uid]=true/false` works
- ✅ Admin UI displays card UID input field in form
- ✅ Admin UI displays card UID column in table
- ✅ Admin UI card filter pills work (All/With Card/Without Card)
- ✅ Admin UI status filter pills use i18n (All/Active/Inactive)
- ✅ All i18n strings translated (EN/DE)
- ✅ Card UID validation enforces 8-20 hex chars
- ✅ All E2E tests pass
- ✅ No regressions in existing functionality

---

## Notes

- Follow TDD: Write test → See it fail → Implement → See it pass → Commit
- Restart PHP-FPM after backend changes: `docker compose exec backend supervisorctl restart php-fpm:php-fpmd`
- Run tests with `--workers=1` for debugging, `--workers=4` for normal runs
- Check backend logs: `docker compose exec backend tail -100 /app/logs/$(date +%Y-%m-%d).log | jq .`
- Reference E2E Testing Patterns: `e2etests/patterns/README.md`
