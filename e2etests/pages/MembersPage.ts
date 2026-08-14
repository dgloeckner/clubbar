/**
 * Members Page Object
 *
 * Encapsulates all interactions with the members management page.
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
 *   const modal = page.locator('[role="dialog"]')
 *   const table = page.getByTestId('members-table')
 *
 * GOOD (tests call page object methods):
 *   await membersPage.expectFormModalVisible()
 *   await membersPage.expectTableVisible()
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class MembersPage extends BasePage {
  // Stats cards
  private readonly statCardMitglieder = () => this.page.getByTestId('stat-card-aktive-mitglieder')
  private readonly statCardOffenePosten = () => this.page.getByTestId('stat-card-offene-posten')
  private readonly statCardLetzteAbrechnung = () => this.page.getByTestId('stat-card-letztes-abrechnungsdatum')
  private readonly statCardMitgliederValue = () => this.page.getByTestId('stat-card-aktive-mitglieder-value')
  private readonly statCardOffenePostenValue = () => this.page.getByTestId('stat-card-offene-posten-value')
  private readonly metricsError = () => this.page.getByTestId('members-metrics-error')
  private readonly metricsRetryBtn = () => this.page.getByTestId('members-metrics-retry')

  // Search and filter
  private readonly searchInput = () => this.page.getByTestId('members-search-input')
  private readonly createBtn = () => this.page.getByTestId('members-create-button')

  // Table elements
  private readonly table = () => this.page.getByTestId('members-table')
  private readonly tableRows = () => this.page.locator('[data-testid^="members-table-row-"]')
  private readonly emptyState = () => this.page.getByTestId('members-empty-state')
  private readonly loadingIndicator = () => this.page.getByTestId('members-loading')
  private readonly errorMessage = () => this.page.getByTestId('members-error-message')

  // Modal elements
  private readonly formModal = () => this.page.getByTestId('members-form-modal')
  private readonly formModalContent = () => this.page.getByTestId('members-form-modal-content')
  private readonly formTitle = () => this.page.getByTestId('members-form-title')
  private readonly firstNameInput = () => this.page.getByTestId('members-form-first-name-input')
  private readonly lastNameInput = () => this.page.getByTestId('members-form-last-name-input')
  private readonly emailInput = () => this.page.getByTestId('members-form-email-input')
  private readonly ibanInput = () => this.page.getByTestId('members-form-iban-input')
  private readonly accountHolderNameInput = () => this.page.getByTestId('members-form-account-holder-name-input')
  private readonly mandateReferenceInput = () => this.page.getByTestId('members-form-mandate-reference-input')
  private readonly mandateDateInput = () => this.page.getByTestId('members-form-mandate-date-input')
  private readonly languageSelect = () => this.page.getByTestId('members-form-language-select')
  private readonly formSubmitBtn = () => this.page.getByTestId('members-form-submit-button')
  private readonly formCancelBtn = () => this.page.getByTestId('members-form-cancel-button')
  private readonly formExportBtn = () => this.page.getByTestId('members-form-export-button')

  // Card UID field
  private readonly cardUidInput = () => this.page.getByTestId('member-form-card-uid')
  private readonly cardUidFormatError = () => this.page.getByTestId('member-form-card-uid-format-error')
  private readonly cardUidDuplicateError = () => this.page.getByTestId('member-form-card-uid-error')

  // IBAN validation
  private readonly ibanValidationIndicator = () => this.page.getByTestId('members-form-iban-validation')
  private readonly ibanError = () => this.page.getByTestId('members-form-iban-error')
  private readonly emailError = () => this.page.getByTestId('members-form-email-error')
  private readonly bankNameDisplay = () => this.page.getByTestId('members-form-bank-name')
  private readonly storedIbanDisplay = () => this.page.getByTestId('members-form-iban-stored')
  private readonly removeStoredIbanButton = () => this.page.getByTestId('members-form-iban-remove')

  // Filter controls
  private readonly clearFiltersBtn = () => this.page.getByTestId('members-clear-filters')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to members page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/members')
  }

  /**
   * VISIBILITY EXPECTATIONS (Pattern 008: Use expect() for assertions)
   */

  async expectPageVisible() {
    await expect(this.page.getByTestId('members-page')).toBeVisible()
    // Wait for table or empty state to load
    await this.page.locator('[data-testid="members-table"], [data-testid="members-empty-state"]').first().waitFor({ timeout: 5000 })
  }

  async expectTableVisible() {
    await expect(this.table()).toBeVisible()
  }

  async expectFormModalVisible() {
    await expect(this.formModal()).toBeVisible()
  }

  async expectFormModalHidden() {
    await expect(this.formModal()).not.toBeVisible()
  }

  async expectEmptyStateVisible() {
    await expect(this.emptyState()).toBeVisible()
  }

  async waitForLoadingToComplete() {
    // Wait for loading indicator to disappear
    await expect(this.loadingIndicator()).not.toBeVisible({ timeout: 10000 })
  }

  /**
   * TABLE INTERACTIONS (Pattern 006: Semantic actions)
   */

  async getMemberRowCount(): Promise<number> {
    // Wait for table or empty state to be visible first
    await this.page.locator('[data-testid="members-table"], [data-testid="members-empty-state"]').first().waitFor({ timeout: 5000 })
    return await this.tableRows().count()
  }

  async getMemberNameAtRowIndex(rowIndex: number): Promise<string> {
    // Get member name at specific row index (for sorting verification)
    const nameCell = this.page.locator('[data-testid^="members-table-cell-name-"]').nth(rowIndex)
    return await nameCell.textContent() || ''
  }

  async getMemberFirstNameInTable(firstName: string): Promise<string | null> {
    // Search for member by first name in the table
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(firstName)) {
        return text
      }
    }

    return null
  }

  /** Wait (auto-retry) for a member with the given first name to appear in the table. */
  async expectMemberVisibleInTable(firstName: string) {
    // Pattern 008: use expect() for auto-waiting, not count() which is instant.
    await expect(
      this.page.locator('[data-testid^="members-table-cell-name-"]').filter({ hasText: firstName })
    ).toBeVisible({ timeout: 10000 })
  }

  /**
   * SEARCH & FILTER (Pattern 006: Semantic actions)
   */

  async search(query: string) {
    // Set up response watcher BEFORE fill to avoid race condition where fast API response is missed.
    // Use URL.searchParams to decode the URL properly (handles both %20 and + encoding).
    const responsePromise = this.page.waitForResponse(
      (resp) => {
        if (!resp.url().includes('/api/admin/members') || resp.status() !== 200) return false
        try {
          return new URL(resp.url()).searchParams.get('search') === query
        } catch {
          return false
        }
      },
      { timeout: 10000 }
    )
    await this.searchInput().fill(query)
    await responsePromise
    // Wait for loading indicator to disappear (Pattern 008, same as JournalPage).
    // waitForResponse resolves when the HTTP response arrives, but React may not have
    // re-rendered yet. If members-empty-state is still in the DOM from a prior state,
    // getMemberRowCount() / count() would read stale 0. Waiting for loading to be hidden
    // ensures the DOM has fully settled with the search results.
    await expect(this.loadingIndicator()).toBeHidden({ timeout: 10000 })
  }

  async clearSearch() {
    // Wait for the unfiltered list response (search param absent or empty) before returning.
    const responsePromise = this.page.waitForResponse(
      (resp) => {
        if (!resp.url().includes('/api/admin/members') || resp.status() !== 200) return false
        try {
          const search = new URL(resp.url()).searchParams.get('search')
          return !search || search === ''
        } catch {
          return false
        }
      },
      { timeout: 10000 }
    )
    await this.searchInput().clear()
    await responsePromise
    await expect(this.loadingIndicator()).toBeHidden({ timeout: 10000 })
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

  /**
   * Fill the member form.
   *
   * `iban` and `mandateDate` accept an empty string, for the member who has not
   * brought their bank details yet — the list calls that state "SEPA: Missing"
   * and the form accepts it (#131).
   */
  async fillMemberForm(
    firstName: string,
    lastName: string,
    iban: string,
    mandateDate: string,
    email?: string,
    language?: string
  ) {
    await this.firstNameInput().fill(firstName)
    await this.lastNameInput().fill(lastName)
    if (email) {
      await this.emailInput().fill(email)
    }
    if (iban) {
      await this.ibanInput().fill(iban.toUpperCase())
    }
    if (mandateDate) {
      await this.mandateDateInput().fill(mandateDate)
    }
    if (language) {
      await this.selectLanguage(language)
    }
  }

  /**
   * Click the form modal backdrop (outside the dialog).
   *
   * Nine fields of typed work must survive this — see #131.
   */
  async clickFormModalBackdrop() {
    await this.formModal().click({ position: { x: 5, y: 5 } })
  }

  async submitForm() {
    // Wait for the API response (POST 201 for create, PATCH 200 for edit).
    // This prevents race conditions where the subsequent search fires before
    // the backend has committed the new/updated member.
    // Uses Promise.race with a 1s timeout to handle client-side validation
    // that blocks the API call (e.g., invalid IBAN) — in that case no network
    // request is made and we return after the timeout instead of waiting 15s.
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/members') &&
        (resp.request().method() === 'POST' || resp.request().method() === 'PATCH') &&
        (resp.status() === 200 || resp.status() === 201),
      { timeout: 15000 }
    )
    await this.formSubmitBtn().click()
    await Promise.race([responsePromise, this.page.waitForTimeout(1000)])
  }

  async cancelForm() {
    await this.formCancelBtn().click()
  }

  async createMember(firstName: string, lastName: string, iban: string, mandateDate: string, email?: string, language?: string) {
    await this.openCreateModal()
    await this.expectFormModalVisible()
    await this.fillMemberForm(firstName, lastName, iban, mandateDate, email, language)
    await this.submitForm()
  }

  async openEditModalForMember(memberId: string) {
    const editBtn = this.page.getByTestId(`members-table-action-edit-${memberId}`)
    await editBtn.click()
  }

  async clickEditButtonAtRowIndex(rowIndex: number) {
    // Click edit button on member at specific row index
    const editButtons = this.page.locator('[data-testid^="members-table-action-edit-"]')
    await editButtons.nth(rowIndex).click()
  }

  async clickEditButtonForMember(firstName: string) {
    // Find member by first name and click edit button
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(firstName)) {
        // Extract member ID from the name cell's test ID
        const nameCell = nameLocators.nth(i)
        const testId = await nameCell.getAttribute('data-testid')

        if (testId) {
          const memberId = testId.replace('members-table-cell-name-', '')

          // Click the edit button for this member
          const editButton = this.page.getByTestId(`members-table-action-edit-${memberId}`)
          await expect(editButton).toBeVisible()
          await editButton.click()
          return
        }
      }
    }

    throw new Error(`Member with first name "${firstName}" not found in table`)
  }

  /**
   * GDPR EXPORT
   */

  async expectExportButtonVisible() {
    await expect(this.formExportBtn()).toBeVisible()
  }

  async expectExportButtonHidden() {
    await expect(this.formExportBtn()).not.toBeVisible()
  }

  async clickExportButton() {
    await this.formExportBtn().click()
  }

  /**
   * ERROR HANDLING
   */

  async getErrorMessage(): Promise<string | null> {
    // Use count() (non-waiting) to check existence first - avoids 30s auto-wait anti-pattern
    const count = await this.errorMessage().count()
    if (count === 0) return null
    return await this.errorMessage().textContent({ timeout: 1000 })
  }

  /** The page-level banner. Lives above the mobile/desktop split, so this is
   *  the same element in both layouts (#132). */
  async expectErrorMessageVisible() {
    await expect(this.errorMessage()).toBeVisible()
  }

  /**
   * The member list reached a real outcome — rows or a genuine "no members"
   * — and reported no error. Pattern 003: says nothing about how many members
   * the database happens to hold.
   */
  async expectListSettledWithoutError() {
    await expect(this.loadingIndicator()).toBeHidden()
    await expect(
      this.page.locator('[data-testid="members-table"], [data-testid="members-empty-state"]').first()
    ).toBeVisible()
    await expect(this.errorMessage()).toBeHidden()
  }

  /**
   * FORM FIELD HELPERS
   */

  async getFormFirstNameValue(): Promise<string> {
    return await this.firstNameInput().inputValue() || ''
  }

  async getFormLastNameValue(): Promise<string> {
    return await this.lastNameInput().inputValue() || ''
  }

  async getFormEmailValue(): Promise<string> {
    return await this.emailInput().inputValue() || ''
  }

  async getFormIbanValue(): Promise<string> {
    return await this.ibanInput().inputValue() || ''
  }

  async getFormMandateDateValue(): Promise<string> {
    return await this.mandateDateInput().inputValue() || ''
  }

  async getFormAccountHolderNameValue(): Promise<string> {
    return await this.accountHolderNameInput().inputValue() || ''
  }

  async getFormMandateReferenceValue(): Promise<string> {
    return await this.mandateReferenceInput().inputValue() || ''
  }

  async fillAccountHolderName(name: string) {
    await this.accountHolderNameInput().fill(name)
  }

  async fillMandateReference(reference: string) {
    await this.mandateReferenceInput().fill(reference.toUpperCase())
  }

  async expectIbanValidVisible() {
    await expect(this.ibanValidationIndicator()).toBeVisible()
    await expect(this.ibanValidationIndicator()).toContainText('✓')
  }

  async expectIbanInvalidVisible() {
    await expect(this.ibanValidationIndicator()).toBeVisible()
    await expect(this.ibanValidationIndicator()).toContainText('✗')
  }

  async expectIbanValidationHidden() {
    await expect(this.ibanValidationIndicator()).not.toBeVisible()
  }

  async expectIbanErrorVisible() {
    await expect(this.ibanError()).toBeVisible()
  }

  async expectIbanErrorHidden() {
    await expect(this.ibanError()).not.toBeVisible()
  }

  async getIbanErrorText(): Promise<string> {
    return await this.ibanError().textContent() || ''
  }

  async expectEmailErrorVisible() {
    await expect(this.emailError()).toBeVisible()
  }

  /**
   * The SEPA column for one member, as the list renders it: "Valid" or
   * "Missing" (localised). A member created without bank details reads
   * "Missing" — that is the supported state, not a failure (#131).
   */
  async getSepaBadgeTextForMember(memberId: string): Promise<string> {
    return (await this.page.getByTestId(`members-table-cell-sepa-${memberId}`).textContent()) || ''
  }

  // Bank name display (resolved from IBAN via BLZ lookup)
  async expectBankNameVisible() {
    await expect(this.bankNameDisplay()).toBeVisible()
  }

  async expectBankNameHidden() {
    await expect(this.bankNameDisplay()).not.toBeVisible()
  }

  async getBankNameText(): Promise<string> {
    return await this.bankNameDisplay().textContent() || ''
  }

  async expectBankNameContains(text: string) {
    await expect(this.bankNameDisplay()).toContainText(text)
  }

  /**
   * The stored account shown beside the (empty) IBAN field when editing a
   * member who has one — '****3000 · Commerzbank'. The IBAN itself is sealed
   * and never returned (ADR-0036), so this line is the whole of what the form
   * can display, and it is why leaving the field blank keeps the account.
   */
  async expectStoredIbanContains(text: string) {
    await expect(this.storedIbanDisplay()).toContainText(text)
  }

  async expectStoredIbanHidden() {
    await expect(this.storedIbanDisplay()).toBeHidden()
  }

  /** Queue removal of the stored bank details — the explicit revoke path. */
  async removeStoredIban() {
    await this.removeStoredIbanButton().click()
  }

  async selectLanguage(language: string) {
    // Click the trigger button to open dropdown
    const trigger = this.page.getByTestId('members-form-language-select-trigger')
    await expect(trigger).toBeVisible()
    await trigger.click()

    // Click the option in the dropdown
    const option = this.page.getByTestId(`members-form-language-select-option-${language}`)
    await expect(option).toBeVisible()
    await option.click()

    // Wait for React to process the change
    await this.page.waitForTimeout(300)
  }

  async getFormLanguageValue(): Promise<string> {
    // Read the trigger button text to determine selected language
    const trigger = this.page.getByTestId('members-form-language-select-trigger')
    const text = await trigger.textContent() || ''

    if (text.includes('English')) return 'en'
    return 'de'  // Default to German
  }

  /**
   * STAT CARDS (Pattern 006: Semantic queries)
   */

  async getMemberCount(): Promise<string> {
    const text = await this.statCardMitglieder().textContent()
    // Extract the number from the stat card
    const match = text?.match(/\d+/)
    return match ? match[0] : '0'
  }

  async getOpenBalance(): Promise<string> {
    const text = await this.statCardOffenePosten().textContent()
    return text || '0,00 €'
  }

  async getLastSettlementDate(): Promise<string> {
    const text = await this.statCardLetzteAbrechnung().textContent()
    return text || ''
  }

  /**
   * The rendered value of a stat card, whatever it says — including the "—" the
   * cards fall back to when the metrics request failed (#132). Deliberately not
   * the digit-extracting getMemberCount(), which would turn "—" into "0" and
   * hide the very thing under test.
   */
  async getMemberCountCardValue(): Promise<string> {
    return ((await this.statCardMitgliederValue().textContent()) ?? '').trim()
  }

  async getOpenBalanceCardValue(): Promise<string> {
    return ((await this.statCardOffenePostenValue().textContent()) ?? '').trim()
  }

  async expectMetricsErrorVisible() {
    await expect(this.metricsError()).toBeVisible()
  }

  async expectMetricsErrorHidden() {
    await expect(this.metricsError()).toBeHidden()
  }

  async clickMetricsRetry() {
    await this.metricsRetryBtn().click()
  }

  /** Pattern 008: auto-retry until the member count stat card contains a digit.
   * Call this after waitForResponse resolves — the HTTP response arrives before
   * React re-renders the stat cards, so textContent() would return the "—" placeholder.
   */
  async waitForStatsToLoad() {
    await expect(this.statCardMitglieder()).toContainText(/\d+/, { timeout: 10000 })
  }

  /**
   * SORTING AND FILTERING CONTROLS
   */

  async setSepaFilter(filterOption: 'all' | 'valid' | 'missing') {
    const btn = this.page.getByTestId(`filter-sepa-${filterOption}`)
    await expect(btn).toBeVisible()
    await btn.click()
    await this.page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)
  }

  async setStatusFilter(filterOption: 'all' | 'active' | 'inactive') {
    // Click the corresponding filter button directly
    const btn = this.page.getByTestId(`members-filter-status-${filterOption}`)
    await expect(btn).toBeVisible()

    // Check if already selected (aria-pressed="true") — skip click and API wait if so
    const pressedAttr = await btn.getAttribute('aria-pressed')
    if (pressedAttr === 'true') {
      return // Already selected, no state change, no API call
    }

    await btn.click()

    // Wait for API response
    await this.page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)
  }

  async setSortBy(sortValue: string) {
    // Click the trigger button to open dropdown
    const trigger = this.page.getByTestId('members-sort-trigger')
    await expect(trigger).toBeVisible()
    await trigger.click()

    // Click the option in the dropdown
    const option = this.page.getByTestId(`members-sort-option-${sortValue}`)
    await expect(option).toBeVisible()
    await option.click()

    // Wait for React to process the change
    await this.page.waitForTimeout(300)
  }

  async getStatusFilterValue(): Promise<'all' | 'active' | 'inactive'> {
    // Check which button has aria-pressed="true"
    const activeBtn = this.page.getByTestId('members-filter-status-active')
    const inactiveBtn = this.page.getByTestId('members-filter-status-inactive')

    const activePressedAttr = await activeBtn.getAttribute('aria-pressed')
    if (activePressedAttr === 'true') return 'active'

    const inactivePressedAttr = await inactiveBtn.getAttribute('aria-pressed')
    if (inactivePressedAttr === 'true') return 'inactive'

    return 'all'
  }

  /**
   * CREATED DATE COLUMN INTERACTIONS
   */

  async expectSortDropdownHidden() {
    // Pattern 008: Verify dropdown is not present (removed as obsolete)
    const dropdown = this.page.getByTestId('members-sort-trigger')
    await expect(dropdown).not.toBeVisible()
  }

  async expectCreatedColumnHeaderVisible() {
    // Pattern 008: Use expect() for visibility assertions
    const header = this.page.getByTestId('members-table-header-created')
    await expect(header).toBeVisible()
  }

  async clickCreatedColumnHeader(expectedSortBy: 'created_at_asc' | 'created_at_desc' = 'created_at_asc') {
    await this.clickSortableHeader('members-table-header-created', expectedSortBy)
  }

  async clickCardUidColumnHeader(expectedSortBy: 'card_uid_asc' | 'card_uid_desc' = 'card_uid_asc') {
    await this.clickSortableHeader('members-table-header-card-uid', expectedSortBy)
  }

  /**
   * DECKEL COLUMN (#371)
   */

  async expectBalanceColumnHeaderVisible() {
    await expect(this.page.getByTestId('members-table-header-balance')).toBeVisible()
  }

  async clickBalanceColumnHeader(expectedSortBy: 'balance_asc' | 'balance_desc' = 'balance_asc') {
    await this.clickSortableHeader('members-table-header-balance', expectedSortBy)
  }

  /** The Deckel rendered on one member's row, as the treasurer reads it. */
  async getMemberBalance(memberId: string): Promise<string> {
    const cell = this.page.getByTestId(`members-table-cell-balance-${memberId}`)
    await expect(cell).toBeVisible()
    return (await cell.textContent()) || ''
  }

  /** The Deckel of the visible rows, top to bottom, as rendered. */
  async getMemberBalances(): Promise<string[]> {
    return await this.page.locator('[data-testid^="members-table-cell-balance-"]').allTextContents()
  }

  /**
   * Click a sortable column header and wait for the list it asks the API for.
   *
   * The expected `sort_by` is part of the contract, not a detail: the Card-UID
   * header used to send `created_at_desc` whatever it displayed (#125).
   *
   * Waiting for the response is not enough to read the rows afterwards. While
   * `loading` is true the page renders the loading div *instead of* the table,
   * so a caller that reads cells between the response arriving and React
   * re-rendering sees no rows at all — which reads as "no member has a card
   * UID" rather than as a timing problem. Same guard as `search()`.
   */
  private async clickSortableHeader(testId: string, expectedSortBy: string) {
    const header = this.page.getByTestId(testId)
    await expect(header).toBeVisible()

    const responsePromise = this.page.waitForResponse((resp) => {
      try {
        const url = new URL(resp.url())
        return (
          url.pathname.includes('/api/admin/members') &&
          url.searchParams.get('sort_by') === expectedSortBy &&
          resp.status() === 200
        )
      } catch {
        return false
      }
    })

    await header.click()
    await responsePromise
    await expect(this.loadingIndicator()).toBeHidden({ timeout: 10000 })
  }

  async getMemberCreatedDateAtRowIndex(rowIndex: number): Promise<string> {
    // Get created date at specific row index
    const dateCell = this.page.locator('[data-testid^="members-table-cell-created-"]').nth(rowIndex)
    return await dateCell.textContent() || ''
  }

  /** The created dates of the visible rows, top to bottom, as rendered. */
  async getMemberCreatedDates(): Promise<string[]> {
    return await this.page.locator('[data-testid^="members-table-cell-created-"]').allTextContents()
  }

  /** The card UIDs of the visible rows, top to bottom; cardless rows render an em dash. */
  async getMemberCardUids(): Promise<string[]> {
    return await this.page.getByTestId('member-card-uid').allTextContents()
  }

  /**
   * CARD UID INTERACTIONS
   */

  async fillCardUid(uid: string) {
    await this.cardUidInput().fill(uid)
  }

  async getFormCardUidValue(): Promise<string> {
    return await this.cardUidInput().inputValue() || ''
  }

  async clearCardUid() {
    await this.cardUidInput().clear()
  }

  async blurCardUid() {
    await this.cardUidInput().blur()
  }

  async expectCardUidFormatErrorVisible() {
    await expect(this.cardUidFormatError()).toBeVisible()
  }

  async expectCardUidFormatErrorHidden() {
    await expect(this.cardUidFormatError()).not.toBeVisible()
  }

  async expectCardUidDuplicateErrorVisible() {
    await expect(this.cardUidDuplicateError()).toBeVisible()
  }

  async expectCardUidDuplicateErrorHidden() {
    await expect(this.cardUidDuplicateError()).not.toBeVisible()
  }

  async getCardUidDuplicateErrorText(): Promise<string> {
    return await this.cardUidDuplicateError().textContent() || ''
  }

  /**
   * The card UID field's submit-time complaint — the same element that carries
   * the API's "already in use", now also used when the form rejects a malformed
   * UID before sending anything (#131).
   */
  async expectCardUidSubmitErrorVisible() {
    await expect(this.cardUidDuplicateError()).toBeVisible()
  }

  /**
   * CARD FILTER
   */

  async setCardFilter(option: 'all' | 'with' | 'without') {
    const btn = this.page.getByTestId(`filter-card-${option}`)
    await expect(btn).toBeVisible()
    await btn.click()
    await this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/members') && resp.status() === 200
    )
  }

  /**
   * STATUS TOGGLE (activate / deactivate from the table row)
   */

  /**
   * Click the status toggle. Deactivating opens a ConfirmDialog (#130), so
   * this only clicks — use confirmDeactivate()/cancelDeactivate() next, or
   * reactivateMember() for the direction that needs no confirmation.
   */
  async toggleStatusForMember(memberId: string) {
    const toggle = this.page.getByTestId(`members-status-toggle-${memberId}`)
    await expect(toggle).toBeVisible()
    await toggle.click()
  }

  async expectDeactivateConfirmVisible() {
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible()
  }

  async expectDeactivateConfirmHidden() {
    await expect(this.page.getByTestId('confirm-dialog')).toBeHidden()
  }

  async getDeactivateConfirmMessage(): Promise<string> {
    return (await this.page.getByTestId('confirm-dialog-message').textContent()) ?? ''
  }

  async confirmDeactivate() {
    await this.page.getByTestId('confirm-dialog-ok').click()
    await this.expectDeactivateConfirmHidden()
  }

  async cancelDeactivate() {
    await this.page.getByTestId('confirm-dialog-cancel').click()
    await this.expectDeactivateConfirmHidden()
  }

  /**
   * Deactivate a member end to end: click the toggle, confirm the dialog.
   */
  async deactivateMember(memberId: string) {
    await this.toggleStatusForMember(memberId)
    await this.expectDeactivateConfirmVisible()
    await this.confirmDeactivate()
  }

  /** Reactivating restores access only, so it fires on the click (#130). */
  async reactivateMember(memberId: string) {
    await this.toggleStatusForMember(memberId)
    await this.expectDeactivateConfirmHidden()
  }

  async getMemberStatus(memberId: string): Promise<'active' | 'inactive'> {
    const toggle = this.page.getByTestId(`members-status-toggle-${memberId}`)
    await expect(toggle).toBeVisible()
    return (await toggle.getAttribute('aria-checked')) === 'true' ? 'active' : 'inactive'
  }

  /**
   * CLEAR ALL FILTERS
   */

  async clearAllFilters() {
    await this.clearFiltersBtn().click()
    await this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/members') && resp.status() === 200
    )
  }

  /**
   * GDPR ANONYMIZE (UC-DSGVO-02)
   */

  async clickAnonymizeButtonForMember(memberId: string) {
    const btn = this.page.getByTestId(`members-table-action-anonymize-${memberId}`)
    await expect(btn).toBeVisible()
    await btn.click()
  }

  async expectAnonymizeConfirmVisible() {
    await expect(this.page.getByTestId('members-anonymize-confirm-modal')).toBeVisible()
  }

  async expectAnonymizeConfirmHidden() {
    await expect(this.page.getByTestId('members-anonymize-confirm-modal')).not.toBeVisible()
  }

  async getAnonymizeConfirmMessage(): Promise<string> {
    return await this.page.getByTestId('members-anonymize-confirm-message').textContent() || ''
  }

  async confirmAnonymize() {
    await this.page.getByTestId('members-anonymize-confirm-ok').click()
  }

  async cancelAnonymize() {
    await this.page.getByTestId('members-anonymize-confirm-cancel').click()
  }

  /**
   * Find member ID from a table row by first name
   */
  async getMemberIdByFirstName(firstName: string): Promise<string | null> {
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()
    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(firstName)) {
        const testId = await nameLocators.nth(i).getAttribute('data-testid')
        if (testId) return testId.replace('members-table-cell-name-', '')
      }
    }
    return null
  }

  /**
   * NEGATIVE VISIBILITY ASSERTION
   */

  async expectMemberNotVisibleInTable(firstName: string) {
    await expect(
      this.page.locator('[data-testid^="members-table-cell-name-"]').filter({ hasText: firstName })
    ).not.toBeVisible()
  }

  private readonly sepaTemplateDownloadButton = () =>
    this.page.getByTestId('members-sepa-template-download-button')

  async clickSepaTemplateDownloadButton(): Promise<void> {
    await this.sepaTemplateDownloadButton().click()
  }

  /**
   * ACCESSIBLE NAMES (#138)
   *
   * The row actions are icon-only, so their accessible name is the only thing
   * a screen reader can announce — and it has to name the member, or every row
   * offers the same two anonymous controls.
   */

  async expectRowActionsNameTheMember(memberId: string, memberName: string) {
    await expect(this.page.getByTestId(`members-table-action-edit-${memberId}`)).toHaveAccessibleName(
      new RegExp(memberName)
    )
    await expect(
      this.page.getByTestId(`members-table-action-anonymize-${memberId}`)
    ).toHaveAccessibleName(new RegExp(memberName))
  }
}
