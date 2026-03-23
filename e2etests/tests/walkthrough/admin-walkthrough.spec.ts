import { test, expect } from '../../fixtures/walkthrough';

test.describe.configure({ mode: 'serial' });

test.describe('Admin Panel Walkthrough', () => {

  test.describe('German scene', () => {
    test.use({ walkthroughLang: 'de' });

    test('01 — Dashboard (German)', async ({
      page, authenticatedDashboardPage, capture,
    }) => {
      await authenticatedDashboardPage.expectMetricsVisible();
      await authenticatedDashboardPage.expectRecentTransactionsVisible();
      await authenticatedDashboardPage.expectTerminalStatusVisible();
      await authenticatedDashboardPage.expectSystemStatusVisible();
      await authenticatedDashboardPage.expectAlertsVisible();
      await capture('Dashboard — Deutsch');
    });
  });

  test('02 — Switch to English', async ({
    page, authenticatedProfilePage, capture,
  }) => {
    await authenticatedProfilePage.expectPageVisible();
    await authenticatedProfilePage.expectSectionsVisible();
    await capture('User Profile');

    // Navigate to dashboard to show it in English
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await capture('Dashboard — English');
  });

  test('03 — Members: browse, search, filter', async ({
    page, authenticatedMembersPage, capture,
  }) => {
    await authenticatedMembersPage.expectPageVisible();
    await authenticatedMembersPage.expectTableVisible();
    await page.waitForTimeout(500);
    await capture('Members Overview');

    await authenticatedMembersPage.search('Thomas');
    await page.waitForTimeout(500);
    await capture('Search Members: "Thomas"');

    await authenticatedMembersPage.clearSearch();
    await authenticatedMembersPage.setSepaFilter('missing');
    await page.waitForTimeout(500);
    await capture('Filter: SEPA Missing');

    await authenticatedMembersPage.setSepaFilter('all');
    await authenticatedMembersPage.setStatusFilter('inactive');
    await page.waitForTimeout(500);
    await capture('Filter: Inactive Members');

    await authenticatedMembersPage.setStatusFilter('all');

    await authenticatedMembersPage.clickEditButtonAtRowIndex(0);
    await authenticatedMembersPage.expectFormModalVisible();
    await capture('Edit Member Details');

    await authenticatedMembersPage.cancelForm();
    await authenticatedMembersPage.expectFormModalHidden();
  });

  test('04 — Members: create a new member', async ({
    page, authenticatedMembersPage, capture,
  }) => {
    const testId = Date.now().toString().slice(-6);
    await authenticatedMembersPage.expectPageVisible();

    await authenticatedMembersPage.openCreateModal();
    await authenticatedMembersPage.expectFormModalVisible();

    await authenticatedMembersPage.fillMemberForm(
      'Demo',
      `Walker${testId}`,
      'DE89370400440532013000',
      '2026-01-15',
      `demo${testId}@club.example`,
      'de',
    );
    await capture('Create New Member');

    await authenticatedMembersPage.submitForm();
    await authenticatedMembersPage.expectFormModalHidden();
    await page.waitForTimeout(500);

    await authenticatedMembersPage.search('Demo');
    await page.waitForTimeout(500);
    await capture('New Member Created');

    await authenticatedMembersPage.clearSearch();
  });

  test('05 — Products: browse, filter, sort', async ({
    page, authenticatedProductsPage, capture,
  }) => {
    await authenticatedProductsPage.expectPageVisible();
    await authenticatedProductsPage.expectTableVisible();
    await capture('Product Catalog');

    await authenticatedProductsPage.sortBy('price');
    await page.waitForTimeout(500);
    await capture('Sort Products by Price');

    await authenticatedProductsPage.sortBy('name');

    await authenticatedProductsPage.search('Weizen');
    await page.waitForTimeout(500);
    await capture('Search Products: "Weizen"');

    await authenticatedProductsPage.clearSearch();

    await authenticatedProductsPage.filterByStatus('inactive');
    await page.waitForTimeout(500);
    await capture('Filter: Inactive Products');

    await authenticatedProductsPage.filterByStatus('all');
  });

  test('06 — Products: create with multilingual name and icon', async ({
    page, authenticatedProductsPage, capture,
  }) => {
    await authenticatedProductsPage.expectPageVisible();

    await authenticatedProductsPage.openCreateModal();
    await authenticatedProductsPage.expectFormModalVisible();

    // Fill German name first and capture
    await authenticatedProductsPage.fillProductForm('Demo Weizen', '3.50');
    const categoryId = await authenticatedProductsPage.getFirstActiveCategoryId();
    if (categoryId) {
      await authenticatedProductsPage.selectCategory(categoryId);
    }
    await capture('Produktname: Deutsch');

    // Switch to English tab, fill English name and capture
    const enTab = page.getByTestId('products-form-name-tab-en');
    await enTab.click();
    const enInput = page.getByTestId('products-form-name-input-en');
    await expect(enInput).toBeVisible({ timeout: 5000 });
    await enInput.fill('Demo Wheat Beer');
    await capture('Product Name: English');

    await authenticatedProductsPage.selectIcon('beer-pils');
    await page.waitForTimeout(300);
    await capture('Icon Selection & Terminal Preview');

    await authenticatedProductsPage.submitForm();
    await authenticatedProductsPage.expectFormModalHidden();
    await page.waitForTimeout(500);
    await capture('New Product Created');
  });

  test('07 — Categories: quick tour', async ({
    page, authenticatedCategoriesPage, capture,
  }) => {
    await authenticatedCategoriesPage.expectPageVisible();
    await authenticatedCategoriesPage.expectTableVisible();
    await capture('Category Management');
  });

  test('08 — Journal: transactions and filters', async ({
    page, authenticatedJournalPage, capture,
  }) => {
    await authenticatedJournalPage.waitForPageLoad();
    await authenticatedJournalPage.waitForTableToLoad();
    await capture('Transaction Journal');

    // Filter for open (unsettled) transactions
    const openFilter = page.getByTestId('journal-settlement-status-filter-open');
    await openFilter.click();
    await page.waitForResponse(resp => resp.url().includes('/api/') && resp.status() === 200);
    await page.waitForTimeout(300);
    await capture('Filter: Open Transactions');

    // Reset settlement filter
    const allFilter = page.getByTestId('journal-settlement-status-filter-all');
    await allFilter.click();
    await page.waitForResponse(resp => resp.url().includes('/api/') && resp.status() === 200);
    await page.waitForTimeout(300);

    const headerAmount = page.getByTestId('journal-header-amount');
    await headerAmount.click();
    await page.waitForTimeout(500);
    await capture('Sort by Amount');

    const headerDate = page.getByTestId('journal-header-date');
    await headerDate.click();
    await page.waitForTimeout(500);

    const searchInput = page.getByTestId('journal-search-input');
    await searchInput.fill('Thomas');
    await page.waitForResponse(resp => resp.url().includes('/api/') && resp.status() === 200);
    await page.waitForTimeout(300);
    await capture('Search Transactions: "Thomas"');

    await searchInput.clear();
  });

  test('09 — Journal: create settlement from selected transactions', async ({
    page, authenticatedJournalPage, capture,
  }) => {
    await authenticatedJournalPage.waitForPageLoad();
    await authenticatedJournalPage.waitForTableToLoad();

    // Filter to open transactions only
    await authenticatedJournalPage.filterBySettlementStatus('open');
    await page.waitForTimeout(500);

    // Enter settlement selection mode
    await authenticatedJournalPage.enterSettlementMode();
    await page.waitForTimeout(300);

    // Select all visible open transactions
    const selectAll = page.getByTestId('journal-select-all-checkbox');
    await selectAll.check();
    await page.waitForTimeout(300);
    await capture('Select Transactions for Settlement');

    // Click conclude to open confirmation modal
    const concludeBtn = page.getByTestId('journal-settlement-conclude-btn');
    await concludeBtn.click();
    await expect(page.getByTestId('journal-settlement-confirm-modal')).toBeVisible();
    await page.waitForTimeout(300);
    await capture('Settlement Confirmation');

    // Confirm the settlement
    const confirmBtn = page.getByTestId('journal-settlement-confirm-submit-btn');
    await confirmBtn.click();
    await page.waitForResponse(
      resp => resp.url().includes('/api/admin/settlements') && resp.status() === 201
    );
    await page.waitForTimeout(500);
    await capture('Settlement Created');
  });

  test('10 — Settlements: view and SEPA export', async ({
    page, authenticatedSettlementsPage, capture,
  }) => {
    await authenticatedSettlementsPage.waitForPageLoad();
    await capture('Settlements Overview');

    // Find the first settlement row and trigger SEPA export
    const sepaBtn = page.locator('[data-testid^="settlements-export-sepa-btn-"]').first();
    if (await sepaBtn.isVisible()) {
      const responsePromise = page.waitForResponse(
        resp => resp.url().includes('/export/sepa-xml')
      );
      await sepaBtn.click();
      await responsePromise;
      await page.waitForTimeout(500);
      await capture('SEPA XML Exported');
    }
  });

  test('11 — Reports: revenue, ranking, terminal activity', async ({
    page, capture,
  }) => {
    await page.goto('/reports');
    await page.waitForLoadState('networkidle');
    await capture('Reports & Analytics');

    const consumptionTab = page.getByRole('tab', { name: /consumption|verbrauch/i });
    if (await consumptionTab.isVisible()) {
      await consumptionTab.click();
      await page.waitForTimeout(500);
      await capture('Consumption Analysis');
    }

    const rankingTab = page.getByRole('tab', { name: /ranking/i });
    if (await rankingTab.isVisible()) {
      await rankingTab.click();
      await page.waitForTimeout(500);
      await capture('Member Ranking');
    }

    const terminalTab = page.getByRole('tab', { name: /terminal/i });
    if (await terminalTab.isVisible()) {
      await terminalTab.click();
      await page.waitForTimeout(500);
      await capture('Terminal Activity');
    }
  });

  test('12 — Settings: admin users, SEPA, terminals', async ({
    page, authenticatedSettingsPage, capture,
  }) => {
    await authenticatedSettingsPage.expectPageVisible();

    await authenticatedSettingsPage.clickAdminUsersTab();
    await authenticatedSettingsPage.expectAdminUsersTabVisible();
    await page.waitForTimeout(500);
    await capture('Admin Users');

    await authenticatedSettingsPage.clickSepaTab();
    await authenticatedSettingsPage.expectSepaTabVisible();
    await page.waitForTimeout(500);
    await capture('SEPA Configuration');

    await authenticatedSettingsPage.clickTerminalsTab();
    await authenticatedSettingsPage.expectTerminalsTabVisible();
    await page.waitForTimeout(500);
    await capture('Terminal Management');
  });

  test('13 — Audit Log: browse and expand details', async ({
    page, authenticatedAuditLogPage, capture,
  }) => {
    await authenticatedAuditLogPage.expectPageVisible();
    await authenticatedAuditLogPage.expectTableVisible();
    await capture('Audit Log');

    const firstEntryId = await authenticatedAuditLogPage.getFirstEntryId();
    if (firstEntryId) {
      await authenticatedAuditLogPage.expandDetails(firstEntryId);
      await authenticatedAuditLogPage.expectDetailsVisible(firstEntryId);
      await capture('Audit Log Entry Details');

      await authenticatedAuditLogPage.collapseDetails(firstEntryId);
    }
  });

  test('14 — Logout', async ({
    page, capture,
  }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await capture('Dashboard Before Logout');

    const logoutBtn = page.locator('[data-testid="header-logout-button"]');
    await logoutBtn.click();
    await page.waitForTimeout(800);
    await capture('Session Logout');
  });

});
