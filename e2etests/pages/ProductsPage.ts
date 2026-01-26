/**
 * Products Page Object
 *
 * Encapsulates all interactions with the products page.
 * Implements E2E Testing Pattern 005: Page Object Model
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
   * Check if products page is loaded
   */
  async isLoaded(): Promise<boolean> {
    // Page is loaded if heading is visible
    const headingVisible = await this.isElementVisible(this.heading())
    // And either table or no-products message is visible
    const contentVisible = await this.isTableVisible() || await this.isNoProductsMessageVisible()
    return headingVisible && contentVisible
  }

  /**
   * Get count of product rows in table
   */
  async getProductCount(): Promise<number> {
    return await this.getElementCount(this.tableRows())
  }

  /**
   * Check if products table is visible
   */
  async isTableVisible(): Promise<boolean> {
    return await this.isElementVisible(this.table())
  }

  /**
   * Check if "no products" message is shown
   */
  async isNoProductsMessageVisible(): Promise<boolean> {
    return await this.isElementVisible(this.noProductsMessage())
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
   */
  async openCreateModal() {
    await this.createBtn().click()
    await this.waitForElement(this.modalHeading(), 5000)
  }

  /**
   * Check if create modal is open
   */
  async isCreateModalOpen(): Promise<boolean> {
    return await this.isElementVisible(this.modalHeading())
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
   */
  async cancelCreateModal() {
    await this.cancelBtn().click()
    await this.waitForElementHidden(this.modal(), 5000)
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
   */
  async getErrorMessage(): Promise<string | null> {
    return await this.getElementText(this.errorMessage())
  }

  /**
   * Check if error message is visible
   */
  async isErrorVisible(): Promise<boolean> {
    return await this.isElementVisible(this.errorMessage())
  }

  /**
   * Get current page URL
   */
  async isOnProductsPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/products')
  }
}
