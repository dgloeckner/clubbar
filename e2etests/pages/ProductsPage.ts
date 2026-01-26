/**
 * Products Page Object
 *
 * Encapsulates all interactions with the products page.
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions (no visibility helpers)
 *
 * Key principle: Expose semantic user actions, return values tests need.
 * Tests use expect() directly for visibility checks.
 */

import { Page } from '@playwright/test'
import { BasePage } from './BasePage'

export class ProductsPage extends BasePage {
  // Main page locators
  private readonly heading = () => this.page.locator('h1:has-text("Products")')
  private readonly searchInput = () => this.page.locator('input[placeholder*="Search"]')
  private readonly createBtn = () => this.page.locator('button:has-text("Create Product")')
  private readonly table = () => this.page.locator('table, [role="table"]')
  private readonly tableRows = () => this.page.locator('tbody tr, [role="row"]')
  private readonly noProductsMessage = () => this.page.locator('text=/no products found/i')
  private readonly errorMessage = () => this.page.locator('[class*="error"], text=/failed|error/i')

  // Modal locators
  private readonly modal = () => this.page.locator('[role="dialog"], [class*="modal"]')
  private readonly modalHeading = () => this.page.locator('h2:has-text("Create Product"), [role="dialog"] h2')
  private readonly productNameInput = () => this.page.locator('input[placeholder*="Product name"]')
  private readonly priceInput = () => this.page.locator('input[type="number"], input[placeholder*="Price"]')
  private readonly cancelBtn = () => this.page.locator('button:has-text("Cancel")')
  private readonly createSubmitBtn = () => this.page.getByRole('button', { name: /^Create$/ }).last()

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to products page and wait for products to load
   */
  async navigate() {
    await super.navigate('http://localhost:5173/products')
    // Wait for page to finish loading (either shows table or no products message)
    await Promise.race([
      this.table().waitFor({ state: 'visible', timeout: 10000 }).catch(() => null),
      this.noProductsMessage().waitFor({ state: 'visible', timeout: 10000 }).catch(() => null),
    ])
  }

  /**
   * Get count of product rows in table
   * Pattern 008: Return value tests actually use (number, not boolean)
   */
  async getProductCount(): Promise<number> {
    return await this.tableRows().count()
  }

  /**
   * Get current search input value
   */
  async getSearchValue(): Promise<string | null> {
    try {
      return await this.searchInput().inputValue()
    } catch {
      return null
    }
  }

  /**
   * Search/filter products by term
   * Includes debounce wait for API call
   */
  async search(term: string) {
    await this.searchInput().fill(term)
    await this.waitForDebounce(500)
  }

  /**
   * Clear search input
   */
  async clearSearch() {
    await this.searchInput().clear()
    await this.waitForDebounce(500)
  }

  /**
   * Open create product modal
   * Pattern 008: Use expect().toBeVisible() in test instead of isCreateModalOpen()
   */
  async openCreateModal() {
    await this.createBtn().click()
    // Let test use: await expect(modalHeading).toBeVisible()
  }

  /**
   * Fill product form fields
   */
  async fillProductForm(name: string, price: string) {
    await this.productNameInput().fill(name)
    await this.priceInput().fill(price)
  }

  /**
   * Fill only product name
   */
  async fillProductName(name: string) {
    await this.productNameInput().fill(name)
  }

  /**
   * Fill only price
   */
  async fillPrice(price: string) {
    await this.priceInput().fill(price)
  }

  /**
   * Submit product creation form
   */
  async submitProductForm() {
    await this.createSubmitBtn().click()
  }

  /**
   * Cancel create modal without submitting
   * Pattern 008: Test uses await expect(modal).toBeHidden() instead of this check
   */
  async cancelCreateModal() {
    await this.cancelBtn().click()
    // Let test use: await expect(modal).toBeHidden()
  }

  /**
   * Create product in single action
   * Opens modal, fills form, and submits
   */
  async createProduct(name: string, price: string) {
    await this.openCreateModal()
    await this.fillProductForm(name, price)
    await this.submitProductForm()
  }

  /**
   * Get error message text if any
   * Pattern 008: Return value tests need, not boolean
   */
  async getErrorMessage(): Promise<string | null> {
    try {
      return await this.errorMessage().textContent()
    } catch {
      return null
    }
  }

  /**
   * Get current page URL
   */
  async isOnProductsPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/products')
  }

  // *** DO NOT ADD ***
  // The following methods would violate Pattern 008:
  // - isLoaded() - Use: await expect(page.locator('h1')).toBeVisible()
  // - isTableVisible() - Use: await expect(table).toBeVisible()
  // - isNoProductsMessageVisible() - Use: await expect(noProductsMsg).toBeVisible()
  // - isCreateModalOpen() - Use: await expect(modal).toBeVisible()
  // - isErrorVisible() - Use: await expect(error).toBeVisible()
}
