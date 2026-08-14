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

  /**
   * Verify all navigation labels are in the expected language
   */
  async expectNavigationInLanguage(lang: 'de' | 'en') {
    const labels = NAV_LABELS[lang]
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
