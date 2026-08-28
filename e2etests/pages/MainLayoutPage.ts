/**
 * Main Layout Page Object
 *
 * Encapsulates interactions with the main layout navigation and header.
 * Implements E2E Testing Pattern 006: Page Object Model
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

// Navigation labels by language
const NAV_LABELS = {
  de: {
    dashboard: 'Dashboard',
    members: 'Mitglieder',
    products: 'Produkte',
    journal: 'Buchungsjournal',
    settlements: 'Abrechnungen',
    reports: 'Berichte',
    settings: 'Einstellungen',
    auditLog: 'Audit-Log',
  },
  en: {
    dashboard: 'Dashboard',
    members: 'Members',
    products: 'Products',
    journal: 'Journal',
    settlements: 'Settlements',
    reports: 'Reports',
    settings: 'Settings',
    auditLog: 'Audit Log',
  },
}

export class MainLayoutPage extends BasePage {
  // Navigation locators
  private readonly navDashboard = () => this.page.locator('[data-testid="nav-dashboard"]')
  private readonly navMembers = () => this.page.locator('[data-testid="nav-members"]')
  private readonly navProducts = () => this.page.locator('[data-testid="nav-products"]')
  private readonly navJournal = () => this.page.locator('[data-testid="nav-journal"]')
  private readonly navSettlements = () => this.page.locator('[data-testid="nav-settlements"]')
  private readonly navReports = () => this.page.locator('[data-testid="nav-reports"]')
  private readonly navSettings = () => this.page.locator('[data-testid="nav-settings"]')
  private readonly navAuditLog = () => this.page.locator('[data-testid="nav-audit-log"]')
  private readonly headerUserBadge = () => this.page.locator('[data-testid="header-user-badge"]')
  private readonly insufficientRole = () => this.page.locator('[data-testid="insufficient-role-page"]')
  private readonly insufficientRoleHomeLink = () =>
    this.page.locator('[data-testid="insufficient-role-home-link"]')
  private readonly headerLogoutButton = () =>
    this.page.locator('[data-testid="header-logout-button"], [data-testid="header-logout-button-mobile"]').first()

  constructor(page: Page) {
    super(page)
  }

  /**
   * Wait for the navigation to be visible
   */
  async waitForNavigation() {
    await this.navMembers().waitFor({ state: 'visible', timeout: 10000 })
  }

  async clickProducts() {
    await this.navProducts().click()
    await this.page.waitForURL('**/products', { timeout: 5000 })
  }

  async clickDashboard() {
    await this.navDashboard().click()
    await this.page.waitForURL('**/dashboard', { timeout: 5000 })
  }

  async expectHeaderVisible() {
    await expect(this.navMembers()).toBeVisible()
  }

  async expectUserBadgeContainsText(text: string) {
    await expect(this.headerUserBadge()).toContainText(text)
  }

  /**
   * The badge is the only route to /profile between 769px and 1500px — the nav
   * carries no profile entry and BottomTabBar renders on mobile only (#138).
   */
  async expectUserBadgeVisible() {
    await expect(this.headerUserBadge()).toBeVisible()
  }

  async getUserBadgeAccessibleName(): Promise<string | null> {
    return this.headerUserBadge().getAttribute('aria-label')
  }

  async clickUserBadge() {
    await this.headerUserBadge().click()
    await this.page.waitForURL('**/profile', { timeout: 5000 })
  }

  async clickLogout() {
    await this.headerLogoutButton().click()
  }

  private readonly navMore = () => this.page.locator('[data-testid="nav-more"]')
  private readonly navMoreMenu = () => this.page.locator('[data-testid="nav-more-menu"]')

  /**
   * Run `body` with the header's overflow menu open, if there is one (#742).
   *
   * Which entries are inline and which are behind "More" depends on the window
   * width, the language and the offices the account holds, so a test that
   * names an entry cannot know where it will be. Opening the menu first makes
   * the question "is this section reachable", which is the one the nav has to
   * answer, rather than "did it happen to fit".
   */
  private async withNavOverflowOpen<T>(body: () => Promise<T>): Promise<T> {
    const opened = (await this.navMore().count()) > 0
    if (opened) {
      await this.navMore().click()
      await this.navMoreMenu().waitFor({ state: 'visible', timeout: 5000 })
    }
    try {
      return await body()
    } finally {
      if (opened) {
        await this.page.keyboard.press('Escape')
        await this.navMoreMenu().waitFor({ state: 'hidden', timeout: 5000 })
      }
    }
  }

  /**
   * The sections this session can navigate to, by their test IDs (ADR-0044).
   *
   * Read off the rendered nav rather than asserted one locator at a time, so a
   * test can state the whole set an office sees — and fail when an entry the
   * role must not see appears, which per-entry assertions quietly miss.
   *
   * Includes what the overflow menu holds, in nav order: an entry behind
   * "More" is one this session can reach, and counting only the inline ones
   * would make the set a function of the window width.
   */
  async getVisibleNavTestIds(): Promise<string[]> {
    await this.page.locator('[data-testid="desktop-nav"]').waitFor({ state: 'visible', timeout: 10000 })
    return this.withNavOverflowOpen(() =>
      this.page
        .locator('[data-testid="desktop-nav"] [data-testid^="nav-"]')
        .evaluateAll((nodes) =>
          nodes
            .map((node) => node.getAttribute('data-testid') ?? '')
            // The overflow control itself is not a section: `nav-more` is the
            // button and `nav-more-menu` the panel it opens.
            .filter((testId) => !testId.startsWith('nav-more'))
        )
    )
  }

  /** The inline entries only — what the header shows without opening "More". */
  async getInlineNavTestIds(): Promise<string[]> {
    await this.page.locator('[data-testid="desktop-nav"]').waitFor({ state: 'visible', timeout: 10000 })
    return this.page
      .locator('[data-testid="desktop-nav"] [data-testid^="nav-"]')
      .evaluateAll((nodes) =>
        nodes
          .map((node) => node.getAttribute('data-testid') ?? '')
          .filter((testId) => !testId.startsWith('nav-more'))
      )
  }

  /**
   * Follow a nav entry by test ID, wherever the header put it — inline, or
   * behind "More" (#742).
   */
  async openNavItem(testId: string) {
    const entry = this.page.locator(`[data-testid="desktop-nav"] [data-testid="${testId}"]`)
    if ((await entry.count()) === 0) {
      await this.navMore().click()
      await this.navMoreMenu().waitFor({ state: 'visible', timeout: 5000 })
    }
    await expect(entry).toBeVisible()
    await entry.click()
  }

  /** The named refusal screen a role-gated page renders (ADR-0044, #516). */
  async expectInsufficientRoleScreen() {
    await expect(this.insufficientRole()).toBeVisible()
  }

  async expectNoInsufficientRoleScreen() {
    await expect(this.insufficientRole()).toBeHidden()
  }

  async clickInsufficientRoleHomeLink() {
    await this.insufficientRoleHomeLink().click()
  }

  /**
   * Verify all navigation labels are in the expected language
   */
  async expectNavigationInLanguage(lang: 'de' | 'en') {
    const labels = NAV_LABELS[lang]
    await this.withNavOverflowOpen(async () => {
      await this.expectNavLabels(labels)
    })
  }

  private async expectNavLabels(labels: (typeof NAV_LABELS)['de']) {
    await expect(this.navDashboard()).toContainText(labels.dashboard)
    await expect(this.navMembers()).toContainText(labels.members)
    await expect(this.navProducts()).toContainText(labels.products)
    await expect(this.navJournal()).toContainText(labels.journal)
    await expect(this.navSettlements()).toContainText(labels.settlements)
    await expect(this.navReports()).toContainText(labels.reports)
    await expect(this.navSettings()).toContainText(labels.settings)
    await expect(this.navAuditLog()).toContainText(labels.auditLog)
  }
}
