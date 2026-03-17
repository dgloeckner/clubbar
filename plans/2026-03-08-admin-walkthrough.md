# Admin Frontend Video Walkthrough — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a Playwright-driven walkthrough that navigates the admin frontend like a real user, showcasing features in an entertaining demo flow — with video capture for screen recording.

**Architecture:** A dedicated Playwright project (`walkthrough`) in the existing `e2etests/` setup. Reuses all existing POMs and the `testTransactions` fixture for database seeding via the sync API. Playwright's built-in `video: 'on'` captures the browser session. Tests run sequentially (single worker) to produce a coherent narrative. A setup step seeds realistic member/product/transaction data so Journal, Settlements, Reports, and Dashboard all show meaningful content. Artificial pauses and deliberate interactions make the recording feel human-paced.

**Tech Stack:** Playwright (existing), existing POMs, Playwright video recording (`video: 'on'`), 1280×720 viewport.

---

## Demo Script / Narrative

**Total estimated recording time: ~3–4 minutes**

The walkthrough follows a "new admin's first day" story arc:

0. **Seed data** (API-only, no browser) → create 5 members, use existing products, push ~20 transactions via sync API, create a settlement — so Dashboard/Journal/Reports have real content
1. **Login** → land on Dashboard (10s)
2. **Dashboard tour** → see metrics (revenue, balances), terminals, alerts, recent transactions (15s)
3. **Members** → browse list, search, filter by SEPA status, open a member's edit form, close it (30s)
4. **Create a new member** → fill form with fun test data, submit, see it appear (20s)
5. **Products** → browse products, filter by category, sort by price (20s)
6. **Create a product** → multilingual name (DE+EN), pick icon, set price, see terminal preview (25s)
7. **Categories** → quick look, toggle a category status (10s)
8. **Journal** → view transactions (now populated!), use period filter, search for a member, show settlement column (20s)
9. **Reports** → switch tabs (Revenue → Member Ranking → Terminal Activity), see charts with real data (25s)
10. **Settings** → quick look at SEPA config, Admin Users tab, Terminals tab (15s)
11. **Audit Log** → expand a row to show change details (10s)
12. **Profile** → switch language to English, see UI update, switch back to German (15s)
13. **Logout** (5s)

---

## Task 1: Add `walkthrough` Playwright project to config

**Files:**
- Modify: `e2etests/playwright.config.ts`

**Step 1: Read the current config**

Read `e2etests/playwright.config.ts` to understand the exact structure.

**Step 2: Add the walkthrough project**

Add a new project to the `projects` array:

```typescript
{
  name: 'walkthrough',
  testDir: './tests/walkthrough',
  use: {
    ...devices['Desktop Chrome'],
    baseURL: 'http://localhost:5173',
    storageState: 'playwright/.auth/admin.json',
    video: {
      mode: 'on',
      size: { width: 1280, height: 720 },
    },
    viewport: { width: 1280, height: 720 },
    launchOptions: {
      slowMo: 150, // human-paced interactions
    },
  },
  dependencies: ['setup auth'],
},
```

Key decisions:
- `video: 'on'` — always record (this IS the purpose)
- `slowMo: 150` — adds 150ms between each Playwright action for visual smoothness
- `viewport: 1280×720` — standard HD for screen recording
- Depends on `setup auth` — reuses existing auth session
- Separate `testDir` — won't run with normal test suites

**Step 3: Commit**

```bash
git add e2etests/playwright.config.ts
git commit -m "feat(walkthrough): add dedicated Playwright project for admin demo recording"
```

---

## Task 2: Create seed data setup test

**Files:**
- Create: `e2etests/tests/walkthrough/seed-data.setup.ts`

**Step 1: Create the seed data setup**

This setup runs before the walkthrough and seeds realistic data via the existing `testTransactions` fixture (sync API + admin API). It creates members with German-sounding names, syncs product transactions against existing products, and creates a settlement — so Dashboard, Journal, Reports, and Settlements pages all show real content.

```typescript
import { test as setup } from '../../fixtures/auth.fixture';

const API_BASE = 'http://localhost:8080/api';

setup('seed walkthrough data', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
  // --- 1. Get existing products to use real product IDs ---
  const productsResp = await authenticatedRequest.get(`${API_BASE}/admin/products`);
  const productsData = await productsResp.json();
  const products = productsData.data || productsData;
  const activeProducts = products.filter((p: any) => p.is_active);

  if (activeProducts.length === 0) {
    throw new Error('No active products found — seed some products first');
  }

  // --- 2. Create 5 members with realistic names ---
  const memberNames = [
    { first: 'Thomas', last: 'Müller', email: 'thomas.mueller' },
    { first: 'Sandra', last: 'Weber', email: 'sandra.weber' },
    { first: 'Michael', last: 'Schmidt', email: 'michael.schmidt' },
    { first: 'Julia', last: 'Fischer', email: 'julia.fischer' },
    { first: 'Andreas', last: 'Wagner', email: 'andreas.wagner' },
  ];

  const memberIds: string[] = [];

  for (const m of memberNames) {
    const resp = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: m.first,
        last_name: m.last,
        email: `${m.email}@sportverein-demo.de`,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2025-06-15',
      },
    });

    if (resp.status() === 201) {
      const member = await resp.json();
      memberIds.push(member.id);
    } else {
      // Member may already exist from a previous run — skip
      console.log(`Skipping member ${m.first} ${m.last}: ${resp.status()}`);
    }
  }

  if (memberIds.length === 0) {
    // Try to fetch existing members instead
    const membersResp = await authenticatedRequest.get(`${API_BASE}/admin/members?per_page=10`);
    const membersData = await membersResp.json();
    const members = membersData.data || membersData;
    for (const m of members.slice(0, 5)) {
      memberIds.push(m.id);
    }
  }

  if (memberIds.length === 0) {
    throw new Error('No members available for seeding transactions');
  }

  // --- 3. Create ~20 transactions via sync API (simulating terminal usage) ---
  const generateUUID = () =>
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });

  const transactions: any[] = [];
  const transactionIds: string[] = [];

  // Spread transactions across the last 30 days
  for (let i = 0; i < 20; i++) {
    const memberId = memberIds[i % memberIds.length];
    const product = activeProducts[i % activeProducts.length];
    const daysAgo = Math.floor(Math.random() * 30);
    const date = new Date();
    date.setDate(date.getDate() - daysAgo);
    date.setHours(17 + Math.floor(Math.random() * 5), Math.floor(Math.random() * 60));

    const txnId = generateUUID();
    transactionIds.push(txnId);

    transactions.push({
      id: txnId,
      member_id: memberId,
      type: 'product',
      product_id: product.id,
      quantity: 1,
      unit_price_cents: product.price_cents,
      amount_cents: product.price_cents,
      notes: '',
      created_at: date.toISOString(),
    });
  }

  // Send transactions in batches of 5 (sync API style)
  for (let i = 0; i < transactions.length; i += 5) {
    const batch = transactions.slice(i, i + 5);
    const resp = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, {
      data: { transactions: batch },
    });

    if (resp.status() !== 201) {
      const error = await resp.text();
      console.log(`Transaction batch warning: ${resp.status()} — ${error}`);
    }
  }

  // --- 4. Create a settlement from the first 10 transactions ---
  const settlementTxnIds = transactionIds.slice(0, 10);
  if (settlementTxnIds.length > 0) {
    const today = new Date().toISOString().split('T')[0];
    const execDate = new Date();
    execDate.setDate(execDate.getDate() + 7);

    const resp = await authenticatedRequest.post(`${API_BASE}/admin/settlements`, {
      data: {
        settlement_type: 'sepa',
        transaction_ids: settlementTxnIds,
        settlement_date: today,
        execution_date: execDate.toISOString().split('T')[0],
        period_start: new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
        period_end: today,
      },
    });

    if (resp.status() === 201) {
      console.log('Settlement created successfully');
    } else {
      const error = await resp.text();
      console.log(`Settlement warning: ${resp.status()} — ${error}`);
    }
  }

  console.log(`Walkthrough data seeded: ${memberIds.length} members, ${transactions.length} transactions`);
});
```

**Step 2: Add `setup walkthrough-data` project to Playwright config**

In `playwright.config.ts`, add a setup project before the walkthrough project:

```typescript
{
  name: 'setup walkthrough-data',
  testMatch: /seed-data\.setup\.ts/,
  testDir: './tests/walkthrough',
  use: {
    baseURL: 'http://localhost:8080',
  },
  dependencies: ['setup auth'],
},
```

And update the `walkthrough` project to depend on it:

```typescript
dependencies: ['setup auth', 'setup walkthrough-data'],
```

**Step 3: Run to verify seeding works**

```bash
cd e2etests && npx playwright test --project="setup walkthrough-data" --workers=1
```

Expected: Seed completes, logs show "Walkthrough data seeded: 5 members, 20 transactions".

**Step 4: Commit**

```bash
git add e2etests/tests/walkthrough/seed-data.setup.ts e2etests/playwright.config.ts
git commit -m "feat(walkthrough): add seed data setup — members, transactions, settlement via sync API"
```

---

## Task 3: Create walkthrough fixture with pacing helpers

**Files:**
- Create: `e2etests/fixtures/walkthrough.ts`

**Step 1: Create the fixture file**

This fixture extends the existing POM fixtures and adds pacing utilities for the demo recording:

```typescript
import { test as pomTest } from './pageObjects';
export { expect } from '@playwright/test';

export const test = pomTest.extend<{
  pause: (ms?: number) => Promise<void>;
  narrationPause: () => Promise<void>;
  quickPause: () => Promise<void>;
}>({
  pause: async ({}, use) => {
    await use(async (ms = 1000) => {
      await new Promise(resolve => setTimeout(resolve, ms));
    });
  },
  narrationPause: async ({ pause }, use) => {
    await use(async () => {
      await pause(2000);
    });
  },
  quickPause: async ({ pause }, use) => {
    await use(async () => {
      await pause(600);
    });
  },
});
```

**Step 2: Commit**

```bash
git add e2etests/fixtures/walkthrough.ts
git commit -m "feat(walkthrough): add walkthrough fixture with pacing helpers"
```

---

## Task 4: Write Part 1 — Login, Dashboard, Members

**Files:**
- Create: `e2etests/tests/walkthrough/admin-walkthrough.spec.ts`

**Step 1: Create the test directory**

```bash
mkdir -p e2etests/tests/walkthrough
```

**Step 2: Write Part 1 of the walkthrough**

```typescript
import { test, expect } from '../../fixtures/walkthrough';

test.describe.configure({ mode: 'serial' });

test.describe('Admin Panel Walkthrough', () => {

  test('01 — Login and Dashboard', async ({
    page, authenticatedDashboardPage, pause, narrationPause, quickPause,
  }) => {
    // We're already authenticated and on the dashboard
    await narrationPause(); // let viewer take in the dashboard

    // Highlight metrics
    await authenticatedDashboardPage.expectMetricsVisible();
    await quickPause();

    // Recent transactions
    await authenticatedDashboardPage.expectRecentTransactionsVisible();
    await quickPause();

    // Terminal status
    await authenticatedDashboardPage.expectTerminalStatusVisible();
    await quickPause();

    // System status
    await authenticatedDashboardPage.expectSystemStatusVisible();
    await quickPause();

    // Alerts
    await authenticatedDashboardPage.expectAlertsVisible();
    await narrationPause();

    // Hit refresh to show live data
    await authenticatedDashboardPage.clickRefresh();
    await pause(1500);
  });

  test('02 — Members: browse, search, filter', async ({
    page, authenticatedMembersPage, pause, narrationPause, quickPause,
  }) => {
    // Already navigated to members page by fixture
    await authenticatedMembersPage.expectPageVisible();
    await narrationPause();

    // Show stats bar
    await authenticatedMembersPage.waitForStatsToLoad();
    await quickPause();

    // Browse the table
    await authenticatedMembersPage.expectTableVisible();
    await pause(1200);

    // Search for a member
    await authenticatedMembersPage.search('Max');
    await pause(1500);

    // Clear search
    await authenticatedMembersPage.clearSearch();
    await quickPause();

    // Filter by SEPA status
    await authenticatedMembersPage.setSepaFilter('valid');
    await pause(1200);

    // Reset filter
    await authenticatedMembersPage.setSepaFilter('all');
    await quickPause();

    // Filter by status
    await authenticatedMembersPage.setStatusFilter('active');
    await pause(1000);
    await authenticatedMembersPage.setStatusFilter('all');
    await quickPause();

    // Click edit on first member to show the form
    await authenticatedMembersPage.clickEditButtonAtRowIndex(0);
    await authenticatedMembersPage.expectFormModalVisible();
    await narrationPause();

    // Close it
    await authenticatedMembersPage.cancelForm();
    await authenticatedMembersPage.expectFormModalHidden();
    await quickPause();
  });

  test('03 — Members: create a new member', async ({
    page, authenticatedMembersPage, pause, narrationPause, quickPause,
  }) => {
    const testId = Date.now().toString().slice(-6);

    await authenticatedMembersPage.expectPageVisible();
    await quickPause();

    // Open create form
    await authenticatedMembersPage.openCreateModal();
    await authenticatedMembersPage.expectFormModalVisible();
    await pause(800);

    // Fill in fun demo data
    await authenticatedMembersPage.fillMemberForm(
      'Demo',
      `Walker${testId}`,
      'DE89370400440532013000',
      '2026-01-15',
      `demo${testId}@club.example`,
      'de',
    );
    await narrationPause(); // let viewer see the filled form

    // Submit
    await authenticatedMembersPage.submitForm();
    await authenticatedMembersPage.expectFormModalHidden();
    await pause(1000);

    // Verify the new member appears
    await authenticatedMembersPage.search('Demo');
    await pause(1500);
    await authenticatedMembersPage.clearSearch();
    await quickPause();
  });

});
```

**Step 3: Run it to verify video is captured**

```bash
cd e2etests && npx playwright test --project=walkthrough --workers=1
```

Expected: Tests pass, video files appear in `test-results/` directory.

**Step 4: Commit**

```bash
git add e2etests/tests/walkthrough/
git commit -m "feat(walkthrough): add login, dashboard, and members walkthrough scenes"
```

---

## Task 5: Write Part 2 — Products, Categories, Journal

**Files:**
- Modify: `e2etests/tests/walkthrough/admin-walkthrough.spec.ts`

**Step 1: Add products, categories, and journal scenes**

Append to the existing `test.describe` block:

```typescript
  test('04 — Products: browse, filter, sort', async ({
    page, authenticatedProductsPage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedProductsPage.expectPageVisible();
    await narrationPause();

    // Browse table
    await authenticatedProductsPage.expectTableVisible();
    await pause(1000);

    // Sort by price (high to low)
    await authenticatedProductsPage.sortBy('price');
    await pause(1000);

    // Sort by name
    await authenticatedProductsPage.sortBy('name');
    await quickPause();

    // Search
    await authenticatedProductsPage.search('Bier');
    await pause(1200);
    await authenticatedProductsPage.clearSearch();
    await quickPause();

    // Filter by status
    await authenticatedProductsPage.filterByStatus('active');
    await pause(800);
    await authenticatedProductsPage.filterByStatus('all');
    await quickPause();
  });

  test('05 — Products: create with multilingual name and icon', async ({
    page, authenticatedProductsPage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedProductsPage.expectPageVisible();
    await quickPause();

    // Open create modal
    await authenticatedProductsPage.openCreateModal();
    await authenticatedProductsPage.expectFormModalVisible();
    await pause(800);

    // Fill multilingual names
    await authenticatedProductsPage.fillProductFormMultilingual(
      { de: 'Demo Weizen', en: 'Demo Wheat Beer' },
      '3.50',
    );
    await quickPause();

    // Pick a category
    const categoryId = await authenticatedProductsPage.getFirstActiveCategoryId();
    if (categoryId) {
      await authenticatedProductsPage.selectCategory(categoryId);
      await quickPause();
    }

    // Pick an icon
    await authenticatedProductsPage.selectIcon('beer');
    await pause(1000);

    // Show the terminal preview (it's visible alongside the form)
    await narrationPause();

    // Submit
    await authenticatedProductsPage.submitForm();
    await authenticatedProductsPage.expectFormModalHidden();
    await pause(1000);
  });

  test('06 — Categories: quick tour', async ({
    page, authenticatedCategoriesPage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedCategoriesPage.expectPageVisible();
    await narrationPause();

    await authenticatedCategoriesPage.expectTableVisible();
    await pause(1000);
  });

  test('07 — Journal: transactions and filters', async ({
    page, authenticatedJournalPage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedJournalPage.expectPageVisible();
    await narrationPause();

    // Wait for table
    await authenticatedJournalPage.waitForTableToLoad();
    await pause(1000);

    // Switch period
    await authenticatedJournalPage.selectPeriod('3m');
    await pause(1000);

    await authenticatedJournalPage.selectPeriod('all');
    await quickPause();

    // Sort by amount
    await authenticatedJournalPage.sortBy('amount');
    await pause(800);

    // Sort by date (back to default)
    await authenticatedJournalPage.sortBy('date');
    await quickPause();

    // Search for a member
    await authenticatedJournalPage.search('Demo');
    await pause(1200);
    await authenticatedJournalPage.search('');
    await narrationPause();
  });
```

**Step 2: Run to verify**

```bash
cd e2etests && npx playwright test --project=walkthrough --workers=1
```

**Step 3: Commit**

```bash
git add e2etests/tests/walkthrough/
git commit -m "feat(walkthrough): add products, categories, and journal scenes"
```

---

## Task 6: Write Part 3 — Reports, Settings, Audit Log, Profile, Logout

**Files:**
- Modify: `e2etests/tests/walkthrough/admin-walkthrough.spec.ts`

**Step 1: Add remaining scenes**

Append to the `test.describe` block:

```typescript
  test('08 — Reports: revenue, ranking, terminal activity', async ({
    page, pause, narrationPause, quickPause,
  }) => {
    // Navigate to reports manually (no POM fixture for reports)
    await page.goto('/reports');
    await page.waitForLoadState('domcontentloaded');
    await narrationPause();

    // Revenue tab (default)
    await pause(1500);

    // Click Consumption tab
    const consumptionTab = page.getByRole('tab', { name: /consumption|verbrauch/i });
    if (await consumptionTab.isVisible()) {
      await consumptionTab.click();
      await pause(1200);
    }

    // Click Member Ranking tab
    const rankingTab = page.getByRole('tab', { name: /ranking/i });
    if (await rankingTab.isVisible()) {
      await rankingTab.click();
      await pause(1500);
    }

    // Click Terminal Activity tab
    const terminalTab = page.getByRole('tab', { name: /terminal/i });
    if (await terminalTab.isVisible()) {
      await terminalTab.click();
      await pause(1500);
    }

    // Back to Revenue
    const revenueTab = page.getByRole('tab', { name: /revenue|umsatz/i });
    if (await revenueTab.isVisible()) {
      await revenueTab.click();
      await quickPause();
    }
  });

  test('09 — Settings: SEPA, admin users, terminals', async ({
    page, authenticatedSettingsPage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedSettingsPage.expectPageVisible();
    await narrationPause();

    // SEPA tab (default)
    await authenticatedSettingsPage.expectSepaTabVisible();
    await pause(1200);

    // Admin Users tab
    await authenticatedSettingsPage.clickAdminUsersTab();
    await authenticatedSettingsPage.expectAdminUsersTabVisible();
    await pause(1200);

    // Terminals tab
    await authenticatedSettingsPage.clickTerminalsTab();
    await authenticatedSettingsPage.expectTerminalsTabVisible();
    await narrationPause();
  });

  test('10 — Audit Log: browse and expand details', async ({
    page, authenticatedAuditLogPage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedAuditLogPage.expectPageVisible();
    await narrationPause();

    await authenticatedAuditLogPage.expectTableVisible();
    await pause(1000);

    // Expand first entry to show details
    const firstEntryId = await authenticatedAuditLogPage.getFirstEntryId();
    if (firstEntryId) {
      await authenticatedAuditLogPage.expandDetails(firstEntryId);
      await authenticatedAuditLogPage.expectDetailsVisible(firstEntryId);
      await narrationPause();

      // Collapse it
      await authenticatedAuditLogPage.collapseDetails(firstEntryId);
      await quickPause();
    }
  });

  test('11 — Profile: language switch', async ({
    page, authenticatedProfilePage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedProfilePage.expectPageVisible();
    await narrationPause();

    // Show profile sections
    await authenticatedProfilePage.expectSectionsVisible();
    await pause(800);

    // Switch to English
    await authenticatedProfilePage.changeLanguage('en');
    await narrationPause(); // viewer sees the UI switch to English

    // Switch back to German
    await authenticatedProfilePage.changeLanguage('de');
    await pause(1500);
  });

  test('12 — Logout', async ({
    page, pause,
  }) => {
    // Click logout via nav
    const logoutButton = page.locator('[data-testid="logout-button"]');
    await logoutButton.click();
    await pause(2000); // final moment on login screen
  });

});
```

**Step 2: Run the full walkthrough**

```bash
cd e2etests && npx playwright test --project=walkthrough --workers=1
```

Expected: All 12 tests pass sequentially. Video files in `test-results/`.

**Step 3: Commit**

```bash
git add e2etests/tests/walkthrough/
git commit -m "feat(walkthrough): add reports, settings, audit log, profile, and logout scenes"
```

---

## Task 7: Add npm script and verify end-to-end

**Files:**
- Modify: `e2etests/package.json`

**Step 1: Add a convenience script**

Add to the `scripts` section in `e2etests/package.json`:

```json
"walkthrough": "npx playwright test --project=walkthrough --workers=1"
```

**Step 2: Run the full walkthrough and locate the video**

```bash
cd e2etests && npm run walkthrough
```

After completion, find the video:

```bash
find e2etests/test-results -name '*.webm' -o -name '*.mp4' | head -5
```

Playwright saves one video per test. Since we use `serial` mode, each scene is a separate clip. They can be concatenated with ffmpeg if a single video is needed:

```bash
# Example: concatenate all clips (if needed later)
# ls test-results/**/video/*.webm | sort > /tmp/clips.txt
# ffmpeg -f concat -safe 0 -i /tmp/clips.txt -c copy walkthrough.webm
```

**Step 3: Commit**

```bash
git add e2etests/package.json
git commit -m "feat(walkthrough): add npm run walkthrough convenience script"
```

---

## Task 8: Verify the walkthrough doesn't run with normal test suites

**Step 1: Verify isolation**

Run the normal test suite and confirm the walkthrough project is NOT included:

```bash
cd e2etests && npm test -- --workers=4
```

Expected: Only `api-tests`, `admin-chromium`, `admin-mobile` projects run. The `walkthrough` project should NOT appear because `npm test` doesn't include `--project=walkthrough`.

Check the Playwright config: since `walkthrough` has its own `testDir` (`./tests/walkthrough`), and other projects have their own `testDir` values, there should be no overlap. However, verify that `npm test` doesn't run all projects by default.

If it does run, add a `grep` filter or make the walkthrough project opt-in only (e.g., by checking an env var).

**Step 2: Commit if any fix was needed**

```bash
git add e2etests/playwright.config.ts
git commit -m "fix(walkthrough): ensure walkthrough project is excluded from normal test runs"
```

---

## Summary

| Task | What | Files |
|------|------|-------|
| 1 | Add Playwright project config | `playwright.config.ts` |
| 2 | Seed database via sync API (members, transactions, settlement) | `tests/walkthrough/seed-data.setup.ts` |
| 3 | Create walkthrough fixture with pacing helpers | `fixtures/walkthrough.ts` |
| 4 | Scenes 01–03: Login, Dashboard, Members | `tests/walkthrough/admin-walkthrough.spec.ts` |
| 5 | Scenes 04–07: Products, Categories, Journal | same file |
| 6 | Scenes 08–12: Reports, Settings, Audit, Profile, Logout | same file |
| 7 | Add npm script, verify video output | `package.json` |
| 8 | Verify isolation from normal test suite | config check |

**Running the walkthrough:**
```bash
cd e2etests && npm run walkthrough
```

**Output:** Video clips in `test-results/` — one per scene (~12 clips, ~15–25s each).
