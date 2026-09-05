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
import { ADULT_DATE_OF_BIRTH } from '../utils/transactions'

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
  private readonly sepaTemplateLinkButton = () => this.page.getByTestId('members-sepa-template-link-button')

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
  private readonly dateOfBirthInput = () => this.page.getByTestId('members-form-dob-input')
  // `DateField` shows the date the way the admin's locale writes it and keeps
  // the ISO value the API receives in a hidden input beside it. Assertions read
  // the ISO one, so a spec does not have to know whether the panel is in
  // German or English.
  private readonly dateOfBirthValue = () => this.page.getByTestId('members-form-dob-input-value')
  private readonly dateOfBirthHint = () => this.page.getByTestId('members-form-dob-input-hint')
  private readonly dateOfBirthAge = () => this.page.getByTestId('members-form-dob-input-age')
  private readonly dateOfBirthCalendarButton = () => this.page.getByTestId('members-form-dob-input-open-calendar')
  private readonly dateOfBirthCalendar = () => this.page.getByTestId('members-form-dob-input-calendar')
  private readonly mandateDateInput = () => this.page.getByTestId('members-form-mandate-date-input')
  private readonly mandateDateValue = () => this.page.getByTestId('members-form-mandate-date-input-value')
  private readonly languageSelect = () => this.page.getByTestId('members-form-language-select')
  // The member's own credit ceiling (ADR-0047, #563). Empty means "follow the
  // club default"; a typed 0 means "no ceiling for this member".
  private readonly creditLimitInput = () => this.page.getByTestId('members-form-credit-limit-input')
  private readonly creditLimitHelper = () => this.page.getByTestId('members-form-credit-limit-helper')
  private readonly creditLimitError = () => this.page.getByTestId('members-form-credit-limit-error')
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
  private readonly changeIbanButton = () => this.page.getByTestId('members-form-iban-change')
  private readonly cancelIbanChangeButton = () => this.page.getByTestId('members-form-iban-change-cancel')
  private readonly ibanRemovalPending = () => this.page.getByTestId('members-form-iban-removal-pending')
  private readonly undoRemoveStoredIbanButton = () => this.page.getByTestId('members-form-iban-remove-undo')

  // Mandate reference: assigned by the server unless one is typed (ADR-0006)
  private readonly mandateReferenceAssigned = () => this.page.getByTestId('members-form-mandate-reference-assigned')
  private readonly mandateReferenceAuto = () => this.page.getByTestId('members-form-mandate-reference-auto')
  private readonly enterMandateReferenceButton = () => this.page.getByTestId('members-form-mandate-reference-enter')
  private readonly changeMandateReferenceButton = () => this.page.getByTestId('members-form-mandate-reference-change')
  private readonly cancelMandateReferenceButton = () => this.page.getByTestId('members-form-mandate-reference-cancel')

  // Row action buttons (GDPR anonymize replaces delete, UC-DSGVO-02)
  private readonly anonymizeButtons = () => this.page.locator('[data-testid^="members-table-action-anonymize-"]')
  private readonly deleteButtons = () => this.page.locator('[data-testid^="members-table-action-delete-"]')

  // Filter controls
  private readonly clearFiltersBtn = () => this.page.getByTestId('members-clear-filters')

  // The form's status strip and its required-fields summary (#629, #830)
  private readonly statusStrip = () => this.page.getByTestId('members-form-status')
  private readonly statusRequired = () => this.page.getByTestId('members-form-status-required')
  private readonly statusRequiredText = () => this.page.getByTestId('members-form-status-required-text')
  private readonly formFooter = () => this.page.getByTestId('members-form-footer')
  private readonly submitButton = () => this.page.getByTestId('members-form-submit-button')
  private readonly formBody = () => this.page.getByTestId('members-form-body')

  // Datenqualität panel on the roster (#629)
  private readonly dataQualityPanel = () => this.page.getByTestId('members-data-quality')
  private readonly dataQualityHeadline = () => this.page.getByTestId('members-data-quality-headline')

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
   * The SEPA-Vorlage button opens the externally hosted registration form in
   * a new tab (#360/#456) rather than triggering a download, so tests assert
   * on its enabled state and target rather than a download event.
   */
  async expectSepaTemplateLinkEnabled() {
    await expect(this.sepaTemplateLinkButton()).toBeEnabled()
  }

  async expectSepaTemplateLinkDisabled() {
    await expect(this.sepaTemplateLinkButton()).toBeDisabled()
  }

  /** Resolves the `href`-equivalent target: the URL the button would open. */
  async clickSepaTemplateLinkButton(): Promise<Page> {
    const [newPage] = await Promise.all([
      this.page.context().waitForEvent('page'),
      this.sepaTemplateLinkButton().click(),
    ])
    return newPage
  }

  /**
   * Fill the member form.
   *
   * `iban` and `mandateDate` accept an empty string, for the member who has not
   * brought their bank details yet — the list calls that state "SEPA: Missing"
   * and the form accepts it (#131).
   *
   * The date of birth is mandatory (ADR-0045), so it is filled by default
   * rather than passed by every caller. An edit form arrives with the stored
   * value already in the input; overwriting it there would silently change data
   * the test never mentioned, so it is only written when the field is empty or
   * when the caller asked for a specific date — which is what a Jugendschutz
   * test does to make a member young.
   */
  async fillMemberForm(
    firstName: string,
    lastName: string,
    iban: string,
    mandateDate: string,
    email?: string,
    language?: string,
    dateOfBirth?: string
  ) {
    await this.firstNameInput().fill(firstName)
    await this.lastNameInput().fill(lastName)
    if (email) {
      await this.emailInput().fill(email)
    }
    if (iban) {
      await this.revealIbanInput()
      await this.ibanInput().fill(iban.toUpperCase())
    }
    if (dateOfBirth || !(await this.dateOfBirthInput().inputValue())) {
      await this.dateOfBirthInput().fill(dateOfBirth ?? ADULT_DATE_OF_BIRTH)
    }
    if (mandateDate) {
      await this.mandateDateInput().fill(mandateDate)
    }
    if (language) {
      await this.selectLanguage(language)
    }
  }

  // ── The member's own credit ceiling (ADR-0047, #563) ────────────────────

  /**
   * Type an amount in euros, or clear the field.
   *
   * Clearing is the case worth having a method for: it must reach the API as
   * `null` — "follow the club default" — and never as `0`, which would grant
   * this member unlimited credit.
   */
  async setCreditLimit(euros: string) {
    await this.creditLimitInput().fill(euros)
  }

  async getCreditLimit(): Promise<string> {
    return await this.creditLimitInput().inputValue()
  }

  /** What the field's placeholder offers — the club figure this member inherits. */
  async getCreditLimitPlaceholder(): Promise<string> {
    return (await this.creditLimitInput().getAttribute('placeholder')) ?? ''
  }

  /**
   * The helper line under the credit limit.
   *
   * Since #830 it appears **only** for a typed `0` — the state that looks like
   * an ordinary number and means the opposite of one (ADR-0047). The empty
   * state says what it means through the placeholder, and the long explanation
   * is behind the label's info icon.
   */
  async getCreditLimitHelperText(): Promise<string> {
    return (await this.creditLimitHelper().textContent()) ?? ''
  }

  async expectCreditLimitHelperHidden() {
    await expect(this.creditLimitHelper()).toHaveCount(0)
  }

  /**
   * The field's error line, which the form renders only once a submit has been
   * attempted — so call this after `submitForm()`, not straight after typing.
   */
  async expectCreditLimitRejected() {
    await expect(this.creditLimitError()).toBeVisible()
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

  async createMember(firstName: string, lastName: string, iban: string, mandateDate: string, email?: string, language?: string, dateOfBirth?: string) {
    await this.openCreateModal()
    await this.expectFormModalVisible()
    await this.fillMemberForm(firstName, lastName, iban, mandateDate, email, language, dateOfBirth)
    await this.submitForm()
  }

  /** The ISO date of birth the form would submit. */
  async getDateOfBirthValue(): Promise<string> {
    return (await this.dateOfBirthValue().inputValue()) || ''
  }

  /** What the date-of-birth field shows, in the admin's locale format. */
  async getDateOfBirthText(): Promise<string> {
    return (await this.dateOfBirthInput().inputValue()) || ''
  }

  /** The line under the field — only ever an error since #830. */
  async getDateOfBirthHint(): Promise<string> {
    return (await this.dateOfBirthHint().textContent())?.trim() ?? ''
  }

  /**
   * The age, which rides inside the field beside the calendar button (#830).
   *
   * It moved off the line below to save a line of dialog height — a
   * restatement of the value already in the box is a lot to pay a whole row
   * for, in a dialog whose Speichern button was below the fold.
   */
  async getDateOfBirthAge(): Promise<string> {
    return (await this.dateOfBirthAge().textContent())?.trim() ?? ''
  }

  /** Type into the date of birth field as a person would, without the picker. */
  async typeDateOfBirth(text: string) {
    await this.dateOfBirthInput().click()
    await this.dateOfBirthInput().fill('')
    await this.dateOfBirthInput().pressSequentially(text)
  }

  async blurDateOfBirth() {
    await this.dateOfBirthInput().blur()
  }

  async openDateOfBirthCalendar() {
    await this.dateOfBirthCalendarButton().click()
    await expect(this.dateOfBirthCalendar()).toBeVisible()
  }

  async expectDateOfBirthCalendarHidden() {
    await expect(this.dateOfBirthCalendar()).toBeHidden()
  }

  /**
   * Pick a date through the calendar the way the UI intends it: year block →
   * year → month → day, which is what makes a birth date three taps instead of
   * a chevron marathon.
   */
  async pickDateOfBirth(year: number, month: number, day: number) {
    await this.openDateOfBirthCalendar()
    // An empty birth-date field already opens on the year grid; one that holds
    // a date opens on its month, so the year jump is a click away.
    const yearRange = this.page.getByTestId('members-form-dob-input-calendar-year-range')
    if (!(await yearRange.isVisible())) {
      await this.page.getByTestId('members-form-dob-input-calendar-year-view-button').click()
    }
    await this.pageYearsUntilVisible(year)
    await this.page.getByTestId(`members-form-dob-input-calendar-year-${year}`).click()
    await this.page.getByTestId(`members-form-dob-input-calendar-month-${month}`).click()
    const iso = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
    await this.page.getByTestId(`members-form-dob-input-calendar-day-${iso}`).click()
  }

  /** Page the year grid backwards until the wanted year is on screen. */
  private async pageYearsUntilVisible(year: number) {
    const target = this.page.getByTestId(`members-form-dob-input-calendar-year-${year}`)
    for (let attempt = 0; attempt < 12; attempt++) {
      if (await target.isVisible()) return
      await this.page.getByTestId('members-form-dob-input-calendar-prev').click()
    }
    await expect(target).toBeVisible()
  }

  /** Whether a day cell is offered at all — a future birth date must not be. */
  async isDateOfBirthDayDisabled(iso: string): Promise<boolean> {
    return this.page.getByTestId(`members-form-dob-input-calendar-day-${iso}`).isDisabled()
  }

  /**
   * Find a member by name and open its edit form.
   *
   * Clears the search first: `search()` waits for a request carrying its query,
   * and re-filling the box with the value it already holds fires no change
   * event and therefore no request. Tests that save and reopen the same member
   * hit exactly that.
   */
  async reopenMemberForm(firstName: string) {
    // Both helpers wait for a request their own edit triggers, so calling
    // either with the value the box already holds fires no change event and
    // then waits out its timeout. Only clear when there is something to clear.
    if ((await this.getSearchValue()) !== '') {
      await this.clearSearch()
    }
    await this.search(firstName)
    await this.expectMemberVisibleInTable(firstName)
    await this.clickEditButtonForMember(firstName)
    await this.expectFormModalVisible()
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

  /**
   * What the IBAN input holds, or '' when it is not showing at all — which is
   * the normal state for a member whose account is already on file. Prefer
   * `expectIbanInputHidden()` for that case; it says why.
   */
  async getFormIbanValue(): Promise<string> {
    if (!(await this.ibanInput().isVisible())) return ''
    return await this.ibanInput().inputValue() || ''
  }

  /**
   * The ISO mandate date the form would submit — read from `DateField`'s hidden
   * value input, not from the visible one, which renders in the admin's locale
   * (`01.02.2025`).
   */
  async getFormMandateDateValue(): Promise<string> {
    return await this.mandateDateValue().inputValue() || ''
  }

  async getFormAccountHolderNameValue(): Promise<string> {
    return await this.accountHolderNameInput().inputValue() || ''
  }

  /**
   * The mandate reference as the form currently holds it — from the input when
   * one is showing, otherwise from the read-only box that displays the
   * reference the server assigned (ADR-0006).
   */
  async getFormMandateReferenceValue(): Promise<string> {
    if (await this.mandateReferenceInput().isVisible()) {
      return await this.mandateReferenceInput().inputValue() || ''
    }
    // The assigned box also carries a copy button, so read the value element
    // rather than the box's text.
    const value = this.page.getByTestId('members-form-mandate-reference-value')
    if (await value.isVisible()) {
      return (await value.textContent() || '').trim()
    }
    return ''
  }

  async fillAccountHolderName(name: string) {
    await this.accountHolderNameInput().fill(name)
  }

  /**
   * Typing a reference is the migration case, so the input is behind an action
   * ("Enter an existing reference" / "Change"). Reveal it if it is not showing,
   * so callers keep expressing intent rather than UI state.
   */
  async fillMandateReference(reference: string) {
    await this.revealMandateReferenceInput()
    await this.mandateReferenceInput().fill(reference.toUpperCase())
  }

  private async revealMandateReferenceInput() {
    if (await this.mandateReferenceInput().isVisible()) return
    if (await this.changeMandateReferenceButton().isVisible()) {
      await this.changeMandateReferenceButton().click()
    } else {
      await this.enterMandateReferenceButton().click()
    }
    await expect(this.mandateReferenceInput()).toBeVisible()
  }

  async beginMandateReferenceEntry() {
    await this.revealMandateReferenceInput()
  }

  async cancelMandateReferenceEntry() {
    await this.cancelMandateReferenceButton().click()
  }

  async expectMandateReferenceAutoVisible() {
    await expect(this.mandateReferenceAuto()).toBeVisible()
  }

  async expectMandateReferenceAssignedContains(text: string) {
    await expect(this.mandateReferenceAssigned()).toContainText(text)
  }

  async expectMandateReferenceInputHidden() {
    await expect(this.mandateReferenceInput()).toBeHidden()
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
   * The Daten column for one member. Since #629 it reports all four gaps
   * rather than SEPA alone, so a member with no bank details reads "SEPA"
   * (a gap chip) and a complete one reads "Vollständig".
   *
   * A member created without bank details showing a SEPA chip is the
   * supported state, not a failure (#131).
   */
  async getSepaBadgeTextForMember(memberId: string): Promise<string> {
    return (await this.page.getByTestId(`members-table-cell-data-${memberId}`).textContent()) || ''
  }

  /** Whether the roster row reports this specific gap (#629). */
  async expectMemberGapVisible(memberId: string, gap: 'card_uid' | 'sepa' | 'email' | 'date_of_birth') {
    await expect(this.page.getByTestId(`members-table-cell-data-gap-${gap}-${memberId}`)).toBeVisible()
  }

  async expectMemberGapHidden(memberId: string, gap: 'card_uid' | 'sepa' | 'email' | 'date_of_birth') {
    await expect(this.page.getByTestId(`members-table-cell-data-gap-${gap}-${memberId}`)).not.toBeVisible()
  }

  async expectMemberDataComplete(memberId: string) {
    await expect(this.page.getByTestId(`members-table-cell-data-complete-${memberId}`)).toBeVisible()
  }

  // ── Form field markers and the status strip (#629, #830) ───────────────

  /**
   * A field's requirement marker state: `open` (required, still empty),
   * `conditional` (a quiet note naming a capability) or `optional`.
   *
   * `null` where the field carries no marker at all, which since #830 is what
   * a *satisfied* required field looks like: green is the outcome in the
   * strip, not a tick on every label.
   */
  async getRequirementMarkerState(field: string): Promise<string | null> {
    const marker = this.page.getByTestId(`members-form-${field}-label-marker`)
    if ((await marker.count()) === 0) return null
    return marker.getAttribute('data-state')
  }

  /** The right-hand end of the strip's caption row: what still stops the save. */
  async getRequiredSummaryText(): Promise<string> {
    return (await this.statusRequiredText().textContent()) || ''
  }

  /** `success`, `warning` or `danger` — the summary's tone. */
  async getRequiredSummaryTone(): Promise<string | null> {
    return this.statusRequired().getAttribute('data-tone')
  }

  /** `incomplete`, `complete`, or `blocked` once a submit has been refused. */
  async getRequirementsPanelState(): Promise<string | null> {
    return this.statusStrip().getAttribute('data-state')
  }

  /**
   * One tile's tone: `ok`, `partial`, `gap`, `pending` or `losing` (#830).
   *
   * This is the assertion that matters most about the strip — it is the whole
   * reason the tile previews the save instead of reporting the load.
   */
  async getStatusTileTone(tile: 'terminal' | 'sepa' | 'reachable'): Promise<string | null> {
    return this.page.getByTestId(`members-form-status-tile-${tile}`).getAttribute('data-tone')
  }

  async getStatusTileText(tile: 'terminal' | 'sepa' | 'reachable'): Promise<string> {
    return (await this.page.getByTestId(`members-form-status-tile-${tile}-message`).textContent()) || ''
  }

  /** The link inside a tile that takes you to the field that would close the gap. */
  async jumpToStatusGap(field: string) {
    await this.page.getByTestId(`members-form-status-gap-${field}`).click()
  }

  async expectStatusGapListed(field: string) {
    await expect(this.page.getByTestId(`members-form-status-gap-${field}`)).toBeVisible()
  }

  async expectStatusGapNotListed(field: string) {
    await expect(this.page.getByTestId(`members-form-status-gap-${field}`)).toHaveCount(0)
  }

  /** The summary's chip for a still-missing field; clicking it focuses the field. */
  async jumpToMissingField(field: string) {
    await this.page.getByTestId(`members-form-requirements-missing-${field}`).click()
  }

  async expectMissingFieldListed(field: string) {
    await expect(this.page.getByTestId(`members-form-requirements-missing-${field}`)).toBeVisible()
  }

  async expectMissingFieldNotListed(field: string) {
    await expect(this.page.getByTestId(`members-form-requirements-missing-${field}`)).toHaveCount(0)
  }

  // ── The dialog's three bands (#830) ────────────────────────────────────

  /**
   * That *Speichern* is reachable without scrolling the form.
   *
   * `toBeInViewport` rather than `toBeVisible`: the old dialog's button was
   * "visible" by every DOM measure and 800px below the fold, which is the bug
   * the sticky footer exists to fix.
   */
  async expectSubmitButtonInViewport() {
    await expect(this.submitButton()).toBeInViewport()
  }

  async expectFooterVisible() {
    await expect(this.formFooter()).toBeVisible()
  }

  /** Scrolls the form body to the bottom, as a phone would. */
  async scrollFormToBottom() {
    await this.formBody().evaluate((element) => {
      element.scrollTop = element.scrollHeight
    })
  }

  /** The compact line the pinned mobile header shows once the strip is gone. */
  async expectMobileStatusSummaryVisible() {
    await expect(this.page.getByTestId('members-form-status-summary')).toBeVisible()
  }

  async expectMobileStatusSummaryHidden() {
    await expect(this.page.getByTestId('members-form-status-summary')).toHaveCount(0)
  }

  /** "Keine Änderungen" / "n Felder geändert" beside Speichern. */
  async getChangeCountText(): Promise<string> {
    return (await this.page.getByTestId('members-form-change-count').textContent()) || ''
  }

  /** Opens a field's info popover and reads it — the helper text moved here (#830). */
  async openFieldInfo(field: string): Promise<string> {
    await this.page.getByTestId(`members-form-${field}-label-info`).click()
    const content = this.page.getByTestId(`members-form-${field}-label-info-content`)
    await expect(content).toBeVisible()
    return (await content.textContent()) || ''
  }

  /** Empties a required field, to prove an edit cannot silently blank it. */
  async clearFirstName() {
    await this.firstNameInput().clear()
  }

  /**
   * The `data-testid` of whatever currently has focus.
   *
   * The page object keeps `page` private (Pattern 006), so a test cannot
   * assert focus directly — and "the summary's chip moved the caret to the
   * field it names" is precisely what needs asserting.
   */
  async getFocusedFieldTestId(): Promise<string | null> {
    return this.page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? null)
  }

  async expectRequiredFieldError(field: string) {
    await expect(this.page.getByTestId(`members-form-${field}-error`)).toBeVisible()
  }

  /**
   * That the strip's summary reports the pending deletion (#131).
   *
   * It shares the one slot with the required-fields count, and a refusal
   * outranks it — the deletion cannot happen until the refusal is cleared —
   * so this is only asserted on a form whose required fields are all present.
   */
  async expectClearingSummaryVisible() {
    await expect(this.statusRequired()).toHaveAttribute('data-tone', 'warning')
    await expect(this.statusRequiredText()).toContainText(/1|gel|delet/i)
  }

  // ── "will be deleted on save" notices (#629) ───────────────────────────

  async expectClearedNoticeVisible(field: 'card-uid' | 'account-holder' | 'mandate-date') {
    await expect(this.page.getByTestId(`members-form-${field}-cleared`)).toBeVisible()
  }

  async expectClearedNoticeHidden(field: 'card-uid' | 'account-holder' | 'mandate-date') {
    await expect(this.page.getByTestId(`members-form-${field}-cleared`)).not.toBeVisible()
  }

  async getClearedNoticeText(field: 'card-uid' | 'account-holder' | 'mandate-date'): Promise<string> {
    return (await this.page.getByTestId(`members-form-${field}-cleared`).textContent()) || ''
  }

  async restoreClearedValue(field: 'card-uid' | 'account-holder' | 'mandate-date') {
    await this.page.getByTestId(`members-form-${field}-cleared-restore`).click()
  }

  // ── Datenqualität panel (#629) ─────────────────────────────────────────

  async expectDataQualityVisible() {
    await expect(this.dataQualityPanel()).toBeVisible()
  }

  /** `incomplete` while any active member has a gap, else `complete`. */
  async getDataQualityState(): Promise<string | null> {
    return this.dataQualityPanel().getAttribute('data-state')
  }

  async getDataQualityHeadline(): Promise<string> {
    return (await this.dataQualityHeadline().textContent()) || ''
  }

  /** The count the panel prints for one gap, as a number. */
  async getDataQualityCount(gap: 'card_uid' | 'sepa' | 'email' | 'date_of_birth'): Promise<number> {
    const text = await this.page.getByTestId(`members-data-quality-count-${gap}`).textContent()
    return Number.parseInt(text ?? '0', 10)
  }

  /** Applies the filter behind one gap's count and waits for the list to settle. */
  async showDataQualityGap(gap: 'card_uid' | 'sepa' | 'email' | 'date_of_birth') {
    await this.page.getByTestId(`members-data-quality-show-${gap}`).click()
    await this.page.locator('[data-testid="members-table"], [data-testid="members-empty-state"], [data-testid="members-mobile-cards"]').first().waitFor({ timeout: 5000 })
  }

  async showAllIncomplete() {
    await this.page.getByTestId('members-data-quality-show-all').click()
    await this.page.locator('[data-testid="members-table"], [data-testid="members-empty-state"], [data-testid="members-mobile-cards"]').first().waitFor({ timeout: 5000 })
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
   * The stored account, shown *in place of* the IBAN input when editing a
   * member who has one — '****3000 · Commerzbank'. The IBAN itself is sealed
   * and never returned (ADR-0036), so this box is the whole of what the form
   * can display, and showing it instead of an empty field is what makes
   * "leave it alone to keep the account" visible rather than explained (#392).
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

  /** Take back a queued removal before saving. */
  async undoRemoveStoredIban() {
    await this.undoRemoveStoredIbanButton().click()
  }

  async expectIbanRemovalPendingVisible() {
    await expect(this.ibanRemovalPending()).toBeVisible()
  }

  async expectIbanRemovalPendingHidden() {
    await expect(this.ibanRemovalPending()).toBeHidden()
  }

  /** Reveal the IBAN input for a member whose account is already on file. */
  async beginIbanChange() {
    await this.changeIbanButton().click()
    await expect(this.ibanInput()).toBeVisible()
  }

  /** Abandon a replacement — the stored account stays. */
  async cancelIbanChange() {
    await this.cancelIbanChangeButton().click()
    await expect(this.ibanInput()).toBeHidden()
  }

  async expectIbanInputVisible() {
    await expect(this.ibanInput()).toBeVisible()
  }

  async expectIbanInputHidden() {
    await expect(this.ibanInput()).toBeHidden()
  }

  private async revealIbanInput() {
    if (await this.ibanInput().isVisible()) return
    await this.beginIbanChange()
  }

  /** Type an IBAN, revealing the input first if a stored account is standing in for it. */
  async fillIban(iban: string) {
    await this.revealIbanInput()
    await this.ibanInput().fill(iban.toUpperCase())
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

  /** Whichever row's anonymize action is showing — used when the table's
   *  population is not test-controlled (#146), so no specific member ID is known. */
  async expectAnyAnonymizeButtonVisible() {
    await expect(this.anonymizeButtons().first()).toBeVisible()
  }

  async getAnonymizeButtonCount(): Promise<number> {
    return await this.anonymizeButtons().count()
  }

  /** GDPR anonymize replaces delete outright — a delete action must never render (UC-DSGVO-02). */
  async getDeleteButtonCount(): Promise<number> {
    return await this.deleteButtons().count()
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
