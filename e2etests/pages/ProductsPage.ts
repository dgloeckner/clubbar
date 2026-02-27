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
  // Table rows have test IDs that are the product UUID directly (no prefix)
  // Select all <tr> elements in the table body that have a data-testid attribute
  private readonly tableRows = () => this.table().locator('tbody tr[data-testid]')
  private readonly createBtn = () => this.page.getByTestId('products-create-button')
  private readonly emptyState = () => this.page.getByTestId('products-empty-state')
  private readonly errorMessage = () => this.page.getByTestId('products-error-message')
  private readonly globalLoadingIndicator = () => this.page.getByTestId('products-global-loading')
  private readonly searchInput = () => this.page.getByTestId('products-search-input')

  // Modal locators (PRIVATE)
  private readonly formModal = () => this.page.getByTestId('products-form-modal')
  private readonly formModalContent = () => this.page.getByTestId('products-form-modal-content')
  private readonly formTitle = () => this.page.getByTestId('products-form-title')
  // Language tabs for multilingual name input
  private readonly nameTabDe = () => this.page.getByTestId('products-form-name-tab-de')
  private readonly nameTabEn = () => this.page.getByTestId('products-form-name-tab-en')
  private readonly nameInputDe = () => this.page.getByTestId('products-form-name-input-de')
  private readonly nameInputEn = () => this.page.getByTestId('products-form-name-input-en')
  private readonly categorySelect = () => this.page.getByTestId('products-form-category-select')
  private readonly priceInput = () => this.page.getByTestId('products-form-price-input')
  private readonly requiresDispenserCheckbox = () => this.page.getByTestId('products-form-requires-dispenser-checkbox')
  private readonly iconSelectTrigger = () => this.page.getByTestId('products-form-icon-select-trigger')
  private readonly iconSelectDropdown = () => this.page.getByTestId('products-form-icon-select-dropdown')
  private readonly iconSelectOption = (iconName: string) =>
    this.page.getByTestId(`products-form-icon-select-option-${iconName}`)
  private readonly formSubmitBtn = () => this.page.getByTestId('products-form-submit-button')
  private readonly formCancelBtn = () => this.page.getByTestId('products-form-cancel-button')
  private readonly formError = () => this.page.getByTestId('products-form-error')

  // Action button locators (PRIVATE)
  private readonly editButton = (productId: string) =>
    this.page.getByTestId(`products-edit-button-${productId}`)
  private readonly deleteButton = (productId: string) =>
    this.page.getByTestId(`products-delete-button-${productId}`)
  private readonly statusToggle = (productId: string) =>
    this.page.getByTestId(`products-status-toggle-${productId}`)

  // Confirmation dialog locators (PRIVATE)
  private readonly confirmDialog = () =>
    this.page.getByTestId('confirm-dialog')
  private readonly confirmOkBtn = () =>
    this.page.getByTestId('confirm-dialog-ok')
  private readonly confirmCancelBtn = () =>
    this.page.getByTestId('confirm-dialog-cancel')

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
    await expect(this.formModal()).not.toBeVisible({ timeout: 30000 })
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

  async getFirstProductId(): Promise<string> {
    const firstRow = await this.tableRows().first()
    const rowId = await firstRow.getAttribute('data-testid')
    const productId = rowId?.replace('products-table-row-', '') || ''
    if (!productId) throw new Error('No products found in table')
    return productId
  }

  /**
   * Get product ID by name - returns null if not found (instead of throwing)
   * Pattern 006: Nullable results for cleaner test assertions
   *
   * Relies on frontend loading indicator being complete.
   * Caller must ensure waitForLoadingToComplete() has been called.
   */
  async getProductIdByName(productName: string): Promise<string | null> {
    // Ensure loading indicator is not visible (data should be loaded)
    await expect(this.globalLoadingIndicator()).not.toBeVisible({ timeout: 30000 })

    const rows = await this.tableRows().all()
    for (const row of rows) {
      // Get the name cell text specifically
      const nameCell = row.locator('[data-testid*="products-table-cell-name-"]')
      const nameText = await nameCell.textContent()
      if (nameText?.trim().includes(productName)) {
        // The row's test ID is the product ID directly (no prefix)
        const productId = await row.getAttribute('data-testid')
        if (productId) return productId
      }
    }

    // Not found - return null instead of throwing
    return null
  }

  async getProductNameInRow(productId: string): Promise<string> {
    const name = await this.page
      .getByTestId(`products-table-cell-name-${productId}`)
      .first()
      .textContent()
    return name || ''
  }

  async getProductPriceInRow(productId: string): Promise<string> {
    const price = await this.page
      .getByTestId(`products-table-cell-price-${productId}`)
      .first()
      .textContent()
    return price || ''
  }

  async getProductCategoryInRow(productId: string): Promise<string> {
    const category = await this.page
      .getByTestId(`products-table-cell-category-${productId}`)
      .first()
      .textContent()
    return category || ''
  }

  async getProductIdInRow(productId: string): Promise<string> {
    const id = await this.page
      .getByTestId(`products-table-cell-id-${productId}`)
      .textContent()
    return id || ''
  }

  /**
   * Get complete row data as JSON object (for nice test assertions)
   * Pattern 006: Return structured data for better test readability
   * Accepts nullable productId - returns null if id is null or row not found
   */
  async getRowDataByProductId(productId: string | null): Promise<{
    id: string
    name: string
    price: string
    category: string
    isActive: boolean
  } | null> {
    if (!productId) {
      return null
    }

    const row = this.page.locator(`[data-testid="${productId}"]`)
    const rowCount = await row.count()

    if (rowCount === 0) {
      return null
    }

    const name = await this.page
      .getByTestId(`products-table-cell-name-${productId}`)
      .first()
      .textContent()

    const price = await this.page
      .getByTestId(`products-table-cell-price-${productId}`)
      .first()
      .textContent()

    const category = await this.page
      .getByTestId(`products-table-cell-category-${productId}`)
      .first()
      .textContent()

    const toggle = this.page.getByTestId(`products-status-toggle-${productId}`)
    const isActive = await toggle.first().getAttribute('aria-checked') === 'true'

    return {
      id: productId,
      name: name?.trim() || '',
      price: price?.trim() || '',
      category: category?.trim() || '',
      isActive,
    }
  }

  /**
   * Get row data or null if not found (for defensive checks)
   * Pattern 006: Nullable results to avoid try/catch in tests
   */
  async getRowDataByProductIdOrNull(productId: string): Promise<{
    id: string
    name: string
    price: string
    category: string
    isActive: boolean
  } | null> {
    const row = this.page.locator(`[data-testid="${productId}"]`)
    const rowCount = await row.count()

    if (rowCount === 0) {
      return null
    }

    const name = await this.page
      .getByTestId(`products-table-cell-name-${productId}`)
      .first()
      .textContent()

    const price = await this.page
      .getByTestId(`products-table-cell-price-${productId}`)
      .first()
      .textContent()

    const category = await this.page
      .getByTestId(`products-table-cell-category-${productId}`)
      .first()
      .textContent()

    const toggle = this.page.getByTestId(`products-status-toggle-${productId}`)
    const isActive = await toggle.first().getAttribute('aria-checked') === 'true'

    return {
      id: productId,
      name: name?.trim() || '',
      price: price?.trim() || '',
      category: category?.trim() || '',
      isActive,
    }
  }

  /**
   * LOADING STATE (Pattern 006: Semantic actions)
   */

  async waitForLoadingToComplete() {
    await expect(this.globalLoadingIndicator()).not.toBeVisible({ timeout: 30000 })
  }

  /**
   * FORM MODAL INTERACTIONS (Pattern 006: Semantic actions)
   */

  async openCreateModal() {
    await this.createBtn().click()
  }

  /**
   * Fill product form with a single-language name (German by default)
   */
  async fillProductForm(name: string, price: string) {
    await this.nameTabDe().click()
    await expect(this.nameInputDe()).toBeVisible({ timeout: 5000 })
    await this.nameInputDe().fill(name)
    await this.priceInput().fill(price)
  }

  /**
   * Fill product form with multilingual names
   */
  async fillProductFormMultilingual(names: { de?: string; en?: string }, price: string) {
    if (names.de) {
      await this.nameTabDe().click()
      await expect(this.nameInputDe()).toBeVisible({ timeout: 5000 })
      await this.nameInputDe().fill(names.de)
    }
    if (names.en) {
      await this.nameTabEn().click()
      await expect(this.nameInputEn()).toBeVisible({ timeout: 5000 })
      await this.nameInputEn().fill(names.en)
    }
    await this.priceInput().fill(price)
  }

  /**
   * Get the current value of the German name input
   */
  async getFormNameDe(): Promise<string> {
    await this.nameTabDe().click()
    await expect(this.nameInputDe()).toBeVisible({ timeout: 5000 })
    return await this.nameInputDe().inputValue()
  }

  /**
   * Get the current value of the English name input
   */
  async getFormNameEn(): Promise<string> {
    await this.nameTabEn().click()
    await expect(this.nameInputEn()).toBeVisible({ timeout: 5000 })
    return await this.nameInputEn().inputValue()
  }

  async selectCategory(categoryId: string) {
    // CategorySelect is a custom dropdown component, not native <select>
    // Click the trigger button to open the dropdown
    const trigger = this.page.getByTestId('products-form-category-select-trigger')
    await trigger.click()

    // Wait for dropdown to open
    const dropdown = this.page.getByTestId('products-form-category-select-dropdown')
    await expect(dropdown).toBeVisible({ timeout: 5000 })

    // Wait for the specific option to appear - it may need scrolling into view
    const option = this.page.getByTestId(`products-form-category-select-option-${categoryId}`)

    // First try to find it, scrolling if needed
    try {
      await option.scrollIntoViewIfNeeded({ timeout: 5000 })
    } catch {
      // If scrolling fails, the option might be rendered but not scrolled, try waiting longer
    }
    await expect(option).toBeVisible({ timeout: 10000 })

    // Click the option button for the category
    await option.click()

    // Wait for dropdown to close after selection
    await expect(dropdown).not.toBeVisible({ timeout: 5000 })
  }

  async getSelectedCategory(): Promise<string> {
    // CategorySelect displays the selected category name in the trigger button
    const trigger = this.page.getByTestId('products-form-category-select-trigger')
    const text = await trigger.textContent()
    return text?.trim() || ''
  }

  async getFirstActiveCategoryId(): Promise<string> {
    // Get all category options from the dropdown
    // First, open the dropdown by clicking the trigger
    const trigger = this.page.getByTestId('products-form-category-select-trigger')
    await trigger.click()

    // Wait for dropdown to appear
    const dropdown = this.page.getByTestId('products-form-category-select-dropdown')
    await expect(dropdown).toBeVisible({ timeout: 5000 })

    // Wait for at least one option to appear
    await this.page.waitForSelector('[data-testid^="products-form-category-select-option-"]', { timeout: 5000 })

    // Get all option buttons
    const options = await this.page.locator('[data-testid^="products-form-category-select-option-"]').all()

    if (options.length === 0) {
      // Close dropdown and return empty
      await trigger.click()
      return ''
    }

    // Get the last option (most recently created category)
    const lastOption = options[options.length - 1]
    const testId = await lastOption.getAttribute('data-testid')
    const categoryId = testId?.replace('products-form-category-select-option-', '') || ''

    // Close dropdown by clicking trigger again
    await trigger.click()

    return categoryId
  }

  async submitForm() {
    // Set up response promise BEFORE clicking to capture the products list reload
    const productsReloadPromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    await this.formSubmitBtn().click()
    // Wait for products list reload response (triggered after form submission)
    await productsReloadPromise
    // Wait for loading indicator to clear
    await this.waitForLoadingToComplete()
  }

  async submitFormWithoutWaitingForClose() {
    // Click submit and wait for loading indicator to disappear
    await this.formSubmitBtn().click()
    // Wait for the global loading indicator to disappear (this is the actual UI feedback)
    await this.waitForLoadingToComplete()
  }

  async cancelForm() {
    await this.formCancelBtn().click()
  }

  async selectIcon(iconName: string) {
    // Click trigger to open dropdown
    await this.iconSelectTrigger().click()
    await expect(this.iconSelectDropdown()).toBeVisible()

    // Click the icon option
    await this.iconSelectOption(iconName).click()
  }

  async clearIcon() {
    // Click trigger to open dropdown
    await this.iconSelectTrigger().click()
    await expect(this.iconSelectDropdown()).toBeVisible()

    // Click clear option
    await this.page.getByTestId('products-form-icon-select-option-clear').click()
  }

  async expectIconDropdownVisible() {
    await expect(this.iconSelectDropdown()).toBeVisible()
  }

  async expectIconDropdownHidden() {
    await expect(this.iconSelectDropdown()).not.toBeVisible()
  }

  async getSelectedIconName(): Promise<string | null> {
    const text = await this.iconSelectTrigger().textContent()
    if (!text || text.includes('Select icon')) {
      return null
    }
    return text.trim()
  }

  async createProduct(name: string, price: string, categoryId?: string, iconName?: string) {
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

    // Select icon if provided
    if (iconName) {
      await this.selectIcon(iconName)
    }

    await this.submitForm()
    // submitForm already waits for loading to complete
    await this.expectFormModalHidden()
  }

  /**
   * FORM FIELD HELPERS
   */

  /**
   * Get the current value of the active name input (German tab by default)
   */
  async getFormNameValue(): Promise<string> {
    await this.nameTabDe().click()
    return await this.nameInputDe().inputValue() || ''
  }

  async getFormPriceValue(): Promise<string> {
    return await this.priceInput().inputValue() || ''
  }

  /**
   * DISPENSER CHECKBOX INTERACTIONS
   */

  async checkRequiresDispenser() {
    // Check if checkbox is already checked
    const isChecked = await this.requiresDispenserCheckbox().isChecked()
    if (!isChecked) {
      await this.requiresDispenserCheckbox().check()
    }
  }

  async uncheckRequiresDispenser() {
    // Check if checkbox is currently checked
    const isChecked = await this.requiresDispenserCheckbox().isChecked()
    if (isChecked) {
      await this.requiresDispenserCheckbox().uncheck()
    }
  }

  async isRequiresDispenserChecked(): Promise<boolean> {
    return await this.requiresDispenserCheckbox().isChecked()
  }

  /**
   * Get dispenser badge for a product in the list
   * Returns null if badge is not visible
   */
  async getDispenserBadge(productId: string) {
    const badge = this.page.getByTestId(`products-list-dispenser-badge-${productId}`)
    const badgeCount = await badge.count()
    return badgeCount > 0 ? badge : null
  }

  /**
   * Check if dispenser badge is visible for a product
   */
  async hasDispenserBadge(productId: string): Promise<boolean> {
    const badge = await this.getDispenserBadge(productId)
    return badge !== null
  }

  /**
   * ERROR HANDLING
   */

  async getErrorMessage(): Promise<string | null> {
    if (await this.errorMessage().count() === 0) return null
    return this.errorMessage().textContent()
  }

  async getFormErrorMessage(): Promise<string | null> {
    if (await this.formError().count() === 0) return null
    return this.formError().textContent()
  }

  /**
   * EDIT INTERACTIONS
   */

  async clickEditButton(productId: string) {
    await this.editButton(productId).first().click()
  }

  async editProduct(productId: string, name: string, price: string, categoryId?: string) {
    await this.clickEditButton(productId)
    await this.expectFormModalVisible()

    await this.fillProductForm(name, price)

    if (categoryId) {
      await this.selectCategory(categoryId)
    }

    await this.submitForm()
    await this.expectFormModalHidden()
  }

  async expectEditMode() {
    const title = await this.formTitle().textContent()
    expect(title).toContain('Produkt bearbeiten') // German: Edit Product
  }

  /**
   * DELETE INTERACTIONS
   */

  async clickDeleteButton(productId: string) {
    await this.deleteButton(productId).first().click()
  }

  async expectConfirmDialogVisible() {
    await expect(this.confirmDialog()).toBeVisible()
  }

  async expectConfirmDialogHidden() {
    await expect(this.confirmDialog()).not.toBeVisible()
  }

  async confirmDelete() {
    // Set up response promise BEFORE clicking to capture the products list reload
    const productsReloadPromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    await this.confirmOkBtn().click()
    await this.expectConfirmDialogHidden()
    // Wait for products list to reload after deletion
    await productsReloadPromise
    await this.waitForLoadingToComplete()
  }

  async cancelDelete() {
    await this.confirmCancelBtn().click()
    await this.expectConfirmDialogHidden()
  }

  /**
   * STATUS TOGGLE INTERACTIONS
   */

  async clickStatusToggle(productId: string) {
    await this.statusToggle(productId).first().click()
  }

  async toggleProductStatus(productId: string) {
    await this.clickStatusToggle(productId)
    await this.waitForLoadingToComplete()
  }

  async getProductStatus(productId: string): Promise<'active' | 'inactive'> {
    const statusText = await this.statusToggle(productId).first().textContent()
    return statusText?.includes('Active') ? 'active' : 'inactive'
  }

  /**
   * SORTING INTERACTIONS (Pattern 006: Semantic actions)
   */

  private readonly sortHeaderName = () => this.page.getByTestId('products-table-header-name')
  private readonly sortHeaderPrice = () => this.page.getByTestId('products-table-header-price')
  private readonly sortHeaderCategory = () => this.page.getByTestId('products-table-header-category')
  private readonly categoryFilterTrigger = () => this.page.getByTestId('products-search-sort-category-trigger')
  private readonly categoryFilterDropdown = () => this.page.getByTestId('products-search-sort-category-dropdown')
  private readonly categoryFilterOptionAll = () => this.page.getByTestId('products-search-sort-category-option-all')
  private readonly categoryFilterOption = (categoryId: string) =>
    this.page.getByTestId(`products-search-sort-category-option-${categoryId}`)

  // Status filter locators (PRIVATE)
  private readonly statusFilterPills = () => this.page.getByTestId('products-filter-status')
  private readonly statusFilterAll = () => this.page.getByTestId('products-filter-status-all')
  private readonly statusFilterActive = () => this.page.getByTestId('products-filter-status-active')
  private readonly statusFilterInactive = () => this.page.getByTestId('products-filter-status-inactive')

  /**
   * Click a sort header and wait for the API response.
   * Uses waitForResponse to avoid race conditions with React's useEffect.
   */
  async sortBy(field: 'name' | 'price' | 'category') {
    const header = field === 'name'
      ? this.sortHeaderName()
      : field === 'price'
        ? this.sortHeaderPrice()
        : this.sortHeaderCategory()

    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.url().includes('sort_by='),
      { timeout: 10000 }
    )
    await header.click()
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * Get all product names in current display order.
   * Returns array of name strings matching table row order.
   */
  async getAllProductNamesInOrder(): Promise<string[]> {
    const rows = await this.tableRows().all()
    const names: string[] = []
    for (const row of rows) {
      const nameCell = row.locator('[data-testid*="products-table-cell-name-"]')
      const text = await nameCell.textContent()
      names.push(text?.trim() || '')
    }
    return names
  }

  /**
   * Get all product prices in current display order.
   * Returns array of price strings (e.g. "5,00 €") matching table row order.
   */
  async getAllProductPricesInOrder(): Promise<string[]> {
    const rows = await this.tableRows().all()
    const prices: string[] = []
    for (const row of rows) {
      const priceCell = row.locator('[data-testid*="products-table-cell-price-"]')
      const text = await priceCell.textContent()
      prices.push(text?.trim() || '')
    }
    return prices
  }

  /**
   * Filter products by category using the category filter dropdown.
   * Waits for API response after selection.
   */
  async filterByCategory(categoryId: string) {
    await this.categoryFilterTrigger().click()
    await expect(this.categoryFilterDropdown()).toBeVisible({ timeout: 5000 })

    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.url().includes(`category_id=${categoryId}`),
      { timeout: 10000 }
    )
    await this.categoryFilterOption(categoryId).click()
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * Reset category filter to "All Categories".
   * Waits for API response after selection.
   */
  async filterByAllCategories() {
    await this.categoryFilterTrigger().click()
    await expect(this.categoryFilterDropdown()).toBeVisible({ timeout: 5000 })

    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products'),
      { timeout: 10000 }
    )
    await this.categoryFilterOptionAll().click()
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * STATUS FILTER INTERACTIONS (Pattern 006: Semantic actions)
   */

  /**
   * Filter products by status (all, active, inactive).
   * Clicks the status pill and waits for API response.
   */
  async filterByStatus(status: 'all' | 'active' | 'inactive') {
    const pill = status === 'all'
      ? this.statusFilterAll()
      : status === 'active'
        ? this.statusFilterActive()
        : this.statusFilterInactive()

    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products'),
      { timeout: 10000 }
    )
    await pill.click()
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * SEARCH INTERACTIONS (Pattern 006: Semantic actions)
   */

  /**
   * Search for products by name. Clears existing value first to ensure
   * the change event fires even if searching for the same term.
   * Waits for debounced API response with the search term in URL.
   */
  async search(term: string) {
    await this.searchInput().clear()
    // Wait for any pending requests to settle
    await this.page.waitForTimeout(100)
    const responsePromise = this.page.waitForResponse(
      (resp) => {
        if (!resp.url().includes('/api/admin/products')) return false
        try {
          return new URL(resp.url()).searchParams.get('search') === term
        } catch {
          return false
        }
      },
      { timeout: 10000 }
    )
    await this.searchInput().fill(term)
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * Clear the search input and wait for unfiltered results.
   */
  async clearSearch() {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products'),
      { timeout: 10000 }
    )
    await this.searchInput().clear()
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * PAGE REFRESH & RELOAD
   */

  async reloadPage() {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.status() === 200,
      { timeout: 15000 }
    )
    await this.page.reload()
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * PAGE STATE VERIFICATION
   */

  async isOnProductsPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/products')
  }
}
