/**
 * Products Page Object
 *
 * Encapsulates all interactions with the products page.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions (no visibility helpers)
 *
 * **CRITICAL PATTERN PRINCIPLES:**
 * 1. Page object provides HIGH-LEVEL SEMANTIC METHODS (not raw locators)
 * 2. Tests use page object methods, NOT page.locator() or page.getByTestId()
 * 3. All locators are PRIVATE and hidden from tests
 * 4. Page object handles data-testid selection internally
 *
 * BAD (tests shouldn't do this):
 *   const table = page.locator('table, [role="table"]')
 *   const modal = page.getByTestId('products-form-modal')
 *
 * GOOD (tests call page object methods):
 *   await productsPage.expectTableVisible()
 *   await productsPage.expectFormModalVisible()
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class ProductsPage extends BasePage {
  // Main page locators (PRIVATE - hidden from tests)
  private readonly table = () => this.page.getByTestId('products-table')
  private readonly tableRows = () => this.page.locator('[data-testid^="products-table-row-"]')
  private readonly searchInput = () => this.page.getByTestId('products-search-input')
  private readonly createBtn = () => this.page.getByTestId('products-create-button')
  private readonly emptyState = () => this.page.getByTestId('products-empty-state')
  private readonly errorMessage = () => this.page.getByTestId('products-error-message')

  // Modal locators (PRIVATE)
  private readonly formModal = () => this.page.getByTestId('products-form-modal')
  private readonly formModalContent = () => this.page.getByTestId('products-form-modal-content')
  private readonly formTitle = () => this.page.getByTestId('products-form-title')
  private readonly nameInput = () => this.page.getByTestId('products-form-name-input')
  private readonly categorySelect = () => this.page.getByTestId('products-form-category-select')
  private readonly priceInput = () => this.page.getByTestId('products-form-price-input')
  private readonly formSubmitBtn = () => this.page.getByTestId('products-form-submit-button')
  private readonly formCancelBtn = () => this.page.getByTestId('products-form-cancel-button')
  private readonly formError = () => this.page.getByTestId('products-form-error')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to products page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/products')
  }

  /**
   * VISIBILITY EXPECTATIONS (Pattern 008: Use expect() for assertions)
   */

  async expectPageVisible() {
    await expect(this.page.getByTestId('products-page')).toBeVisible()
  }

  async expectTableVisible() {
    await expect(this.table()).toBeVisible()
  }

  async expectTableHidden() {
    await expect(this.table()).not.toBeVisible()
  }

  async expectFormModalVisible() {
    await expect(this.formModal()).toBeVisible()
  }

  async expectFormModalHidden() {
    await expect(this.formModal()).not.toBeVisible({ timeout: 15000 })
  }

  async expectEmptyStateVisible() {
    await expect(this.emptyState()).toBeVisible()
  }

  async expectErrorMessageVisible() {
    await expect(this.errorMessage()).toBeVisible()
  }

  async expectProductRowVisible(productId: string) {
    await expect(this.page.getByTestId(`products-table-row-${productId}`)).toBeVisible()
  }

  /**
   * TABLE INTERACTIONS (Pattern 006: Semantic actions)
   */

  async getProductCount(): Promise<number> {
    return await this.tableRows().count()
  }

  async getProductNameInRow(productId: string): Promise<string> {
    const name = await this.page
      .getByTestId(`products-table-cell-name-${productId}`)
      .textContent()
    return name || ''
  }

  async getProductPriceInRow(productId: string): Promise<string> {
    const price = await this.page
      .getByTestId(`products-table-cell-price-${productId}`)
      .textContent()
    return price || ''
  }

  async getProductCategoryInRow(productId: string): Promise<string> {
    const category = await this.page
      .getByTestId(`products-table-cell-category-${productId}`)
      .textContent()
    return category || ''
  }

  /**
   * SEARCH & FILTER (Pattern 006: Semantic actions)
   */

  async search(term: string) {
    await this.searchInput().fill(term)
    await this.waitForDebounce(500)
  }

  async clearSearch() {
    await this.searchInput().clear()
    await this.waitForDebounce(500)
  }

  async getSearchValue(): Promise<string> {
    return await this.searchInput().inputValue() || ''
  }

  /**
   * FORM MODAL INTERACTIONS (Pattern 006: Semantic actions)
   */

  async openCreateModal() {
    await this.createBtn().click()
  }

  async fillProductForm(name: string, price: string) {
    await this.nameInput().fill(name)
    await this.priceInput().fill(price)
  }

  async selectCategory(categoryId: string) {
    await this.categorySelect().selectOption(categoryId)
  }

  async getSelectedCategory(): Promise<string> {
    return await this.categorySelect().inputValue() || ''
  }

  async getFirstActiveCategoryId(): Promise<string> {
    // Get all options and find the last one (should be most recently created)
    const options = await this.categorySelect().locator('option')
    const count = await options.count()

    if (count > 1) {
      // Try to find a "Product Test Category" option (from current/recent tests)
      // These are most likely to be active
      let foundValue = ''

      for (let i = count - 1; i > 0; i--) {
        const option = options.nth(i)
        const text = await option.textContent()
        const value = await option.getAttribute('value')

        // Look for test category names
        if (text && text.includes('Product Test Category')) {
          foundValue = value || ''
          break
        }
      }

      // If we found a test category, return it
      if (foundValue) {
        return foundValue
      }

      // Fallback: get the last non-placeholder option
      const lastOption = options.nth(count - 1)
      const value = await lastOption.getAttribute('value')
      return value || ''
    }

    return ''
  }

  async submitForm() {
    await this.formSubmitBtn().click()
  }

  async cancelForm() {
    await this.formCancelBtn().click()
  }

  async createProduct(name: string, price: string, categoryId?: string) {
    await this.openCreateModal()
    await this.expectFormModalVisible()
    await this.fillProductForm(name, price)

    // Select category - either the provided one or first available
    if (categoryId) {
      await this.selectCategory(categoryId)
    } else {
      // Auto-select first available category (now that modal is open)
      const autoSelectId = await this.getFirstActiveCategoryId()
      if (autoSelectId) {
        await this.selectCategory(autoSelectId)
      }
    }

    await this.submitForm()
    // Wait for API call to complete and modal to close
    await this.page.waitForLoadState('networkidle', { timeout: 15000 })
    await this.page.waitForTimeout(500)
    await this.expectFormModalHidden()
  }

  /**
   * FORM FIELD HELPERS
   */

  async getFormNameValue(): Promise<string> {
    return await this.nameInput().inputValue() || ''
  }

  async getFormPriceValue(): Promise<string> {
    return await this.priceInput().inputValue() || ''
  }

  /**
   * ERROR HANDLING
   */

  async getErrorMessage(): Promise<string | null> {
    try {
      return await this.errorMessage().textContent()
    } catch {
      return null
    }
  }

  async getFormErrorMessage(): Promise<string | null> {
    try {
      return await this.formError().textContent()
    } catch {
      return null
    }
  }

  /**
   * PAGE STATE VERIFICATION
   */

  async isOnProductsPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/products')
  }
}
