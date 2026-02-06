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
    members: 'Mitglieder',
    products: 'Produkte',
    categories: 'Kategorien',
    journal: 'Buchungsjournal',
    settlements: 'Abrechnungen',
    statistics: 'Statistik',
    settings: 'Einstellungen',
    auditLog: 'Audit-Log',
  },
  en: {
    members: 'Members',
    products: 'Products',
    categories: 'Categories',
    journal: 'Journal',
    settlements: 'Settlements',
    statistics: 'Statistics',
    settings: 'Settings',
    auditLog: 'Audit Log',
  },
}

export class MainLayoutPage extends BasePage {
  // Navigation locators
  private readonly navMembers = () => this.page.locator('[data-testid="nav-members"]')
  private readonly navProducts = () => this.page.locator('[data-testid="nav-products"]')
  private readonly navCategories = () => this.page.locator('[data-testid="nav-categories"]')
  private readonly navJournal = () => this.page.locator('[data-testid="nav-journal"]')
  private readonly navSettlements = () => this.page.locator('[data-testid="nav-settlements"]')
  private readonly navStatistics = () => this.page.locator('[data-testid="nav-statistics"]')
  private readonly navSettings = () => this.page.locator('[data-testid="nav-settings"]')
  private readonly navAuditLog = () => this.page.locator('[data-testid="nav-audit-log"]')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Wait for the navigation to be visible
   */
  async waitForNavigation() {
    await this.navMembers().waitFor({ state: 'visible', timeout: 10000 })
  }

  /**
   * Verify all navigation labels are in the expected language
   */
  async expectNavigationInLanguage(lang: 'de' | 'en') {
    const labels = NAV_LABELS[lang]
    await expect(this.navMembers()).toContainText(labels.members)
    await expect(this.navProducts()).toContainText(labels.products)
    await expect(this.navCategories()).toContainText(labels.categories)
    await expect(this.navJournal()).toContainText(labels.journal)
    await expect(this.navSettlements()).toContainText(labels.settlements)
    await expect(this.navStatistics()).toContainText(labels.statistics)
    await expect(this.navSettings()).toContainText(labels.settings)
    await expect(this.navAuditLog()).toContainText(labels.auditLog)
  }
}
