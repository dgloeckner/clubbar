import { test, expect } from '../../fixtures/walkthrough';

test.describe.configure({ mode: 'serial' });

test.describe('Admin Panel Walkthrough', () => {

  test('01 — Login and Dashboard', async ({
    page, authenticatedDashboardPage, pause, narrationPause, quickPause, showCursor,
  }) => {
    await test.step('subtitle: Dashboard Overview', async () => {
      await narrationPause();
      await authenticatedDashboardPage.expectMetricsVisible();
      await quickPause();
    });

    await test.step('subtitle: Recent Activity & System Status', async () => {
      await authenticatedDashboardPage.expectRecentTransactionsVisible();
      await quickPause();
      await authenticatedDashboardPage.expectTerminalStatusVisible();
      await quickPause();
      await authenticatedDashboardPage.expectSystemStatusVisible();
      await quickPause();
    });

    await test.step('subtitle: Alerts & Live Refresh', async () => {
      await authenticatedDashboardPage.expectAlertsVisible();
      await narrationPause();
      const refreshBtn = page.locator('[data-testid="dashboard-refresh-button"]');
      await showCursor(refreshBtn);
      await refreshBtn.click();
      await pause(1200);
    });
  });

  test('02 — Members: browse, search, filter', async ({
    page, authenticatedMembersPage, pause, narrationPause, quickPause,
  }) => {
    await test.step('subtitle: Members Overview', async () => {
      await authenticatedMembersPage.expectPageVisible();
      await authenticatedMembersPage.waitForStatsToLoad();
      await authenticatedMembersPage.expectTableVisible();
      await narrationPause();
    });

    await test.step('subtitle: Search & Filter Members', async () => {
      await authenticatedMembersPage.search('Thomas');
      await pause(800);
      await authenticatedMembersPage.clearSearch();
      await quickPause();

      await authenticatedMembersPage.setSepaFilter('valid');
      await pause(800);
      await authenticatedMembersPage.setSepaFilter('all');
      await quickPause();

      await authenticatedMembersPage.setStatusFilter('active');
      await pause(600);
      await authenticatedMembersPage.setStatusFilter('all');
      await quickPause();
    });

    await test.step('subtitle: Edit Member Details', async () => {
      await authenticatedMembersPage.clickEditButtonAtRowIndex(0);
      await authenticatedMembersPage.expectFormModalVisible();
      await narrationPause();
      await authenticatedMembersPage.cancelForm();
      await authenticatedMembersPage.expectFormModalHidden();
      await quickPause();
    });
  });

  test('03 — Members: create a new member', async ({
    page, authenticatedMembersPage, pause, narrationPause, quickPause,
  }) => {
    const testId = Date.now().toString().slice(-6);
    await authenticatedMembersPage.expectPageVisible();

    await test.step('subtitle: Create New Member', async () => {
      await authenticatedMembersPage.openCreateModal();
      await authenticatedMembersPage.expectFormModalVisible();
      await quickPause();

      await authenticatedMembersPage.fillMemberForm(
        'Demo',
        `Walker${testId}`,
        'DE89370400440532013000',
        '2026-01-15',
        `demo${testId}@club.example`,
        'de',
      );
      await narrationPause();
    });

    await test.step('subtitle: Save & Verify Member', async () => {
      await authenticatedMembersPage.submitForm();
      await authenticatedMembersPage.expectFormModalHidden();
      await pause(600);

      await authenticatedMembersPage.search('Demo');
      await pause(800);
      await authenticatedMembersPage.clearSearch();
      await quickPause();
    });
  });

  test('04 — Products: browse, filter, sort', async ({
    page, authenticatedProductsPage, pause, narrationPause, quickPause,
  }) => {
    await test.step('subtitle: Product Catalog', async () => {
      await authenticatedProductsPage.expectPageVisible();
      await authenticatedProductsPage.expectTableVisible();
      await narrationPause();
    });

    await test.step('subtitle: Sort & Search Products', async () => {
      await authenticatedProductsPage.sortBy('price');
      await pause(600);
      await authenticatedProductsPage.sortBy('name');
      await quickPause();

      await authenticatedProductsPage.search('Bier');
      await pause(800);
      await authenticatedProductsPage.clearSearch();
      await quickPause();

      await authenticatedProductsPage.filterByStatus('active');
      await pause(600);
      await authenticatedProductsPage.filterByStatus('all');
      await quickPause();
    });
  });

  test('05 — Products: create with multilingual name and icon', async ({
    page, authenticatedProductsPage, pause, narrationPause, quickPause,
  }) => {
    await authenticatedProductsPage.expectPageVisible();

    await test.step('subtitle: Create Multilingual Product', async () => {
      await authenticatedProductsPage.openCreateModal();
      await authenticatedProductsPage.expectFormModalVisible();
      await quickPause();

      await authenticatedProductsPage.fillProductFormMultilingual(
        { de: 'Demo Weizen', en: 'Demo Wheat Beer' },
        '3.50',
      );
      await quickPause();

      const categoryId = await authenticatedProductsPage.getFirstActiveCategoryId();
      if (categoryId) {
        await authenticatedProductsPage.selectCategory(categoryId);
        await quickPause();
      }
    });

    await test.step('subtitle: Icon Selection & Terminal Preview', async () => {
      await authenticatedProductsPage.selectIcon('beer-pils');
      await pause(600);
      await narrationPause();

      await authenticatedProductsPage.submitForm();
      await authenticatedProductsPage.expectFormModalHidden();
      await pause(600);
    });
  });

  test('06 — Categories: quick tour', async ({
    page, authenticatedCategoriesPage, pause, narrationPause, quickPause,
  }) => {
    await test.step('subtitle: Category Management', async () => {
      await authenticatedCategoriesPage.expectPageVisible();
      await authenticatedCategoriesPage.expectTableVisible();
      await narrationPause();
    });
  });

  test('07 — Journal: transactions and filters', async ({
    page, authenticatedJournalPage, pause, narrationPause, quickPause, showCursor,
  }) => {
    await test.step('subtitle: Transaction Journal', async () => {
      await authenticatedJournalPage.waitForPageLoad();
      await authenticatedJournalPage.waitForTableToLoad();
      await narrationPause();
    });

    await test.step('subtitle: Filter by Period & Sort', async () => {
      const picker3m = page.getByTestId('journal-period-picker-3m');
      await showCursor(picker3m);
      await picker3m.click();
      await pause(800);
      const pickerAll = page.getByTestId('journal-period-picker-all');
      await showCursor(pickerAll);
      await pickerAll.click();
      await pause(800);

      const headerAmount = page.getByTestId('journal-header-amount');
      await showCursor(headerAmount);
      await headerAmount.click();
      await pause(600);
      const headerDate = page.getByTestId('journal-header-date');
      await showCursor(headerDate);
      await headerDate.click();
      await pause(600);
    });

    await test.step('subtitle: Search Transactions', async () => {
      const searchInput = page.getByTestId('journal-search-input');
      await showCursor(searchInput);
      await searchInput.fill('Thomas');
      await pause(800);
      await searchInput.clear();
      await narrationPause();
    });
  });

  test('08 — Reports: revenue, ranking, terminal activity', async ({
    page, pause, narrationPause, quickPause, showCursor,
  }) => {
    await test.step('subtitle: Reports & Analytics', async () => {
      await page.goto('/reports');
      await page.waitForLoadState('networkidle');
      await narrationPause();
    });

    await test.step('subtitle: Consumption & Member Ranking', async () => {
      const consumptionTab = page.getByRole('tab', { name: /consumption|verbrauch/i });
      if (await consumptionTab.isVisible()) {
        await showCursor(consumptionTab);
        await consumptionTab.click();
        await pause(800);
      }

      const rankingTab = page.getByRole('tab', { name: /ranking/i });
      if (await rankingTab.isVisible()) {
        await showCursor(rankingTab);
        await rankingTab.click();
        await pause(800);
      }
    });

    await test.step('subtitle: Terminal Activity', async () => {
      const terminalTab = page.getByRole('tab', { name: /terminal/i });
      if (await terminalTab.isVisible()) {
        await showCursor(terminalTab);
        await terminalTab.click();
        await pause(800);
      }

      const revenueTab = page.getByRole('tab', { name: /revenue|umsatz/i });
      if (await revenueTab.isVisible()) {
        await showCursor(revenueTab);
        await revenueTab.click();
        await quickPause();
      }
    });
  });

  test('09 — Settings: SEPA, admin users, terminals', async ({
    page, authenticatedSettingsPage, pause, narrationPause, quickPause,
  }) => {
    await test.step('subtitle: SEPA Configuration', async () => {
      await authenticatedSettingsPage.expectPageVisible();
      await authenticatedSettingsPage.expectSepaTabVisible();
      await narrationPause();
    });

    await test.step('subtitle: Admin Users & Terminals', async () => {
      await authenticatedSettingsPage.clickAdminUsersTab();
      await authenticatedSettingsPage.expectAdminUsersTabVisible();
      await pause(800);

      await authenticatedSettingsPage.clickTerminalsTab();
      await authenticatedSettingsPage.expectTerminalsTabVisible();
      await narrationPause();
    });
  });

  test('10 — Audit Log: browse and expand details', async ({
    page, authenticatedAuditLogPage, pause, narrationPause, quickPause,
  }) => {
    await test.step('subtitle: Audit Log', async () => {
      await authenticatedAuditLogPage.expectPageVisible();
      await authenticatedAuditLogPage.expectTableVisible();
      await narrationPause();
    });

    await test.step('subtitle: Expand Log Entry Details', async () => {
      const firstEntryId = await authenticatedAuditLogPage.getFirstEntryId();
      if (firstEntryId) {
        await authenticatedAuditLogPage.expandDetails(firstEntryId);
        await authenticatedAuditLogPage.expectDetailsVisible(firstEntryId);
        await narrationPause();

        await authenticatedAuditLogPage.collapseDetails(firstEntryId);
        await quickPause();
      }
    });
  });

  test('11 — Profile: language switch', async ({
    page, authenticatedProfilePage, pause, narrationPause, quickPause,
  }) => {
    await test.step('subtitle: User Profile', async () => {
      await authenticatedProfilePage.expectPageVisible();
      await authenticatedProfilePage.expectSectionsVisible();
      await narrationPause();
    });

    await test.step('subtitle: Language Switching', async () => {
      await authenticatedProfilePage.changeLanguage('en');
      await narrationPause();
      await authenticatedProfilePage.changeLanguage('de');
      await pause(800);
    });
  });

  test('12 — Logout', async ({
    page, pause, narrationPause, showCursor,
  }) => {
    await test.step('subtitle: Session Logout', async () => {
      await page.goto('/dashboard');
      await page.waitForLoadState('networkidle');
      await narrationPause();

      const logoutBtn = page.locator('[data-testid="header-logout-button"]');
      await showCursor(logoutBtn);
      await logoutBtn.click();
      await pause(1200);
    });
  });

});
