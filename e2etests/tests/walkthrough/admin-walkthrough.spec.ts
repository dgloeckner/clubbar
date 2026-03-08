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
    await authenticatedMembersPage.expectPageVisible();
    await narrationPause();

    // Show stats bar
    await authenticatedMembersPage.waitForStatsToLoad();
    await quickPause();

    // Browse the table
    await authenticatedMembersPage.expectTableVisible();
    await pause(1200);

    // Search for a member
    await authenticatedMembersPage.search('Thomas');
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

    // Search for a seeded member
    await authenticatedJournalPage.search('Thomas');
    await pause(1200);
    await authenticatedJournalPage.search('');
    await narrationPause();
  });

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
