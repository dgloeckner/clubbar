import { test, expect } from '../../fixtures/pageObjects'
import { csrfHeaders } from '../../utils/csrf'

const API_BASE = 'http://localhost:8080/api'

/**
 * Admin Frontend — Form Lifecycle E2E Tests (#131)
 *
 * Four related problems affecting every data-entry modal:
 *
 * 1. A backdrop click discarded typed work with no dirty check. Data-entry
 *    modals now ignore the backdrop entirely; they close through Cancel or a
 *    successful save. Read-only modals (a one-time secret, a transaction list)
 *    are unaffected.
 * 2. Cancelled entries and stale errors survived into the next open.
 * 3. Client-side rules were rendered but not enforced, so invalid values still
 *    reached the API.
 * 4. The member form demanded SEPA details, although "SEPA: Missing" is a
 *    state the list has a filter and a badge for.
 *
 * Patterns: 001 (test data isolation), 004 (parallel safety),
 *           005 (test IDs), 006 (page object), 007 (fixtures), 008 (expect)
 */

test.describe('Form lifecycle: backdrop click keeps typed work (#131)', () => {

  test('member form survives a backdrop click with every field intact', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    const typed = {
      firstName: `Bdrop${ts}`,
      lastName: `Last${ts}`,
      email: `bdrop-${ts}@test.com`,
      iban: 'DE89370400440532013000',
      mandateDate: '2025-03-04',
    }

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.fillMemberForm(
      typed.firstName, typed.lastName, typed.iban, typed.mandateDate, typed.email, 'de',
    )

    await authenticatedMembersPage.clickFormModalBackdrop()

    // The modal is still open and still holds everything that was typed
    await authenticatedMembersPage.expectFormModalVisible()
    expect(await authenticatedMembersPage.getFormFirstNameValue()).toBe(typed.firstName)
    expect(await authenticatedMembersPage.getFormLastNameValue()).toBe(typed.lastName)
    expect(await authenticatedMembersPage.getFormEmailValue()).toBe(typed.email)
    expect(await authenticatedMembersPage.getFormIbanValue()).toBe(typed.iban)
    expect(await authenticatedMembersPage.getFormMandateDateValue()).toBe(typed.mandateDate)

    // Cancel still closes it — the deliberate way out
    await authenticatedMembersPage.cancelForm()
    await authenticatedMembersPage.expectFormModalHidden()
  })

  test('product form survives a backdrop click', async ({ authenticatedProductsPage }) => {
    const ts = Date.now()
    const name = `BdropProd${ts}`

    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.fillProductForm(name, '3.50')

    await authenticatedProductsPage.clickFormModalBackdrop()

    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getFormNameValue()).toBe(name)
    expect(await authenticatedProductsPage.getFormPriceValue()).toBe('3.50')

    await authenticatedProductsPage.cancelForm()
    await authenticatedProductsPage.expectFormModalHidden()
  })

  test('category form survives a backdrop click', async ({ authenticatedCategoriesPage }) => {
    const ts = Date.now()
    const name = `BdropCat${ts}`

    await authenticatedCategoriesPage.openCreateModal()
    await authenticatedCategoriesPage.expectFormModalVisible()
    await authenticatedCategoriesPage.fillCategoryName('de', name)

    await authenticatedCategoriesPage.clickFormModalBackdrop()

    await authenticatedCategoriesPage.expectFormModalVisible()
    expect(await authenticatedCategoriesPage.getCategoryNameValue('de')).toBe(name)

    await authenticatedCategoriesPage.cancelForm()
    await authenticatedCategoriesPage.expectFormModalHidden()
  })

  test('create-admin modal survives a backdrop click', async ({ authenticatedSettingsPage }) => {
    const ts = Date.now()
    const typed = { email: `bdrop-${ts}@test.com`, display_name: `Bdrop ${ts}` }

    await authenticatedSettingsPage.clickAdminUsersTab()
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(typed)

    await authenticatedSettingsPage.clickCreateAdminModalBackdrop()

    expect(await authenticatedSettingsPage.isCreateAdminModalVisible()).toBe(true)
    expect(await authenticatedSettingsPage.getCreateAdminFormValues()).toEqual(typed)

    await authenticatedSettingsPage.closeCreateAdminModal()
  })
})


test.describe('Form lifecycle: no stale state on reopen (#131)', () => {

  test('a cancelled create-admin entry is gone the next time the modal opens', async ({
    authenticatedSettingsPage,
  }) => {
    const ts = Date.now()

    await authenticatedSettingsPage.clickAdminUsersTab()
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm({
      email: `abandoned-${ts}@test.com`,
      display_name: `Abandoned ${ts}`,
    })
    await authenticatedSettingsPage.closeCreateAdminModal()

    await authenticatedSettingsPage.clickCreateAdminButton()
    expect(await authenticatedSettingsPage.getCreateAdminFormValues()).toEqual({
      email: '',
      display_name: '',
    })

    await authenticatedSettingsPage.closeCreateAdminModal()
  })

  test('a cancelled create-terminal entry is gone the next time the modal opens', async ({
    authenticatedSettingsPage,
  }) => {
    const ts = Date.now()

    await authenticatedSettingsPage.clickTerminalsTab()
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm({
      name: `Abandoned ${ts}`,
      device_id: `abandoned-${ts}`,
    })
    await authenticatedSettingsPage.closeCreateTerminalModal()

    await authenticatedSettingsPage.clickCreateTerminalButton()
    expect(await authenticatedSettingsPage.getCreateTerminalFormValues()).toEqual({
      name: '',
      device_id: '',
    })

    await authenticatedSettingsPage.closeCreateTerminalModal()
  })

  test('a card-UID error from an abandoned create does not follow the next member into edit', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    // Hex only — the field strips anything else as you type
    const cardUid = `DAD${ts.toString().slice(-9)}`

    // A member that owns the card UID, so the next create collides with it
    await authenticatedMembersPage.createMember(
      `Owner${ts}`, 'Test', 'DE89370400440532013000', '2024-01-15', `owner-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.expectFormModalHidden()
    await authenticatedMembersPage.search(`Owner${ts}`)
    await authenticatedMembersPage.clickEditButtonForMember(`Owner${ts}`)
    await authenticatedMembersPage.fillCardUid(cardUid)
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // Create a second member with the same card UID → 422, modal stays open
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.fillMemberForm(
      `Clash${ts}`, 'Test', 'DE02120300000000202051', '2024-01-15', `clash-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.fillCardUid(cardUid)
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.expectCardUidDuplicateErrorVisible()

    // Give up and go edit an entirely different member. The search from before
    // is still applied, so the table still lists only that one.
    await authenticatedMembersPage.cancelForm()
    await authenticatedMembersPage.expectFormModalHidden()
    await authenticatedMembersPage.clickEditButtonForMember(`Owner${ts}`)
    await authenticatedMembersPage.expectFormModalVisible()

    // The complaint belonged to the abandoned form, not to this member
    await authenticatedMembersPage.expectCardUidDuplicateErrorHidden()

    await authenticatedMembersPage.cancelForm()
  })

  /**
   * A guard, not a reproduction: today `handleCancel` clears the icon on every
   * close path, so the gap in the create buttons is masked and this passes
   * either way. It is here so that folding the reset back into the per-button
   * handlers — where it was missing — cannot quietly reintroduce it.
   */
  test('"Create product" does not open carrying the previously edited icon', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const ts = Date.now()
    const name = `IconProd${ts}`

    // Own category and product, created through the API (Pattern 001) — the
    // seed ships none, and the icon is what this test is about
    const category = await page.request.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `IconCat${ts}`, en: `IconCat${ts}` } },
      headers: await csrfHeaders(page),
    })
    expect(category.status()).toBe(201)
    const categoryId = (await category.json()).id

    const product = await page.request.post(`${API_BASE}/admin/products`, {
      data: { names: { de: name }, price_cents: 250, category_id: categoryId, icon_name: 'beer-pils' },
      headers: await csrfHeaders(page),
    })
    expect(product.status()).toBe(201)
    const productId = (await product.json()).id

    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.search(name)

    // Open it for edit — the form now holds its icon — then abandon that
    await authenticatedProductsPage.clickEditButton(productId)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getSelectedIconName()).not.toBeNull()
    await authenticatedProductsPage.cancelForm()
    await authenticatedProductsPage.expectFormModalHidden()

    // A fresh create form starts with no icon
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getSelectedIconName()).toBeNull()

    await authenticatedProductsPage.cancelForm()
  })
})


test.describe('Form lifecycle: client-side rules are enforced (#131)', () => {

  test('a card UID shorter than 8 hex digits is rejected before the request goes out', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()

    // Fail the request if the form lets a 4-character UID through
    let posted = false
    await page.route('**/api/admin/members', async (route, request) => {
      if (request.method() === 'POST') posted = true
      await route.continue()
    })

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.fillMemberForm(
      `Short${ts}`, 'Test', 'DE89370400440532013000', '2024-01-15', `short-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.fillCardUid('ABCD')
    await authenticatedMembersPage.submitForm()

    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.expectCardUidSubmitErrorVisible()
    expect(posted).toBe(false)

    // Correcting it lets the same form through
    await authenticatedMembersPage.fillCardUid(`AB${ts.toString().slice(-8)}`)
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.search(`Short${ts}`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`Short${ts}`)
  })

  test('Enter in the category name field submits the form', async ({
    authenticatedCategoriesPage,
  }) => {
    const ts = Date.now()
    const name = `EnterCat${ts}`

    await authenticatedCategoriesPage.openCreateModal()
    await authenticatedCategoriesPage.expectFormModalVisible()
    await authenticatedCategoriesPage.fillCategoryName('de', name)

    await authenticatedCategoriesPage.pressEnterInCategoryName('de')

    // The modal closed because the form actually submitted, and the category
    // is in the list — this used to do nothing at all
    await authenticatedCategoriesPage.expectFormModalHidden()
    await authenticatedCategoriesPage.waitForLoadingToComplete()
    await authenticatedCategoriesPage.expectCategoryVisibleByName(name)
  })
})


test.describe('Form lifecycle: a member without SEPA data can be created (#131)', () => {

  test('create succeeds with no IBAN and no mandate date, and reads "SEPA: Missing"', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    const firstName = `NoSepa${ts}`

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()
    // Empty IBAN and mandate date — the volunteer signing this member up does
    // not have their bank details to hand
    await authenticatedMembersPage.fillMemberForm(
      firstName, `Last${ts}`, '', '', `nosepa-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.search(firstName)
    await authenticatedMembersPage.expectMemberVisibleInTable(firstName)

    const memberId = await authenticatedMembersPage.getMemberIdByFirstName(firstName)
    expect(memberId).not.toBeNull()

    // The list reports the missing bank details rather than the form refusing
    // to create the member at all
    const sepaBadge = await authenticatedMembersPage.getSepaBadgeTextForMember(memberId!)
    expect(sepaBadge.toLowerCase()).toMatch(/(missing|fehlt)/)

    // And the member is reachable through the "SEPA: Missing" filter
    await authenticatedMembersPage.setSepaFilter('missing')
    await authenticatedMembersPage.expectMemberVisibleInTable(firstName)
  })
})
