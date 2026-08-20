import { test, expect } from '../../fixtures/pageObjects'
import { csrfHeaders } from '../../utils/csrf'

/**
 * Admin Frontend - Products Page E2E Tests (Consolidated)
 *
 * Three flow-based tests covering product CRUD, list features, and special features:
 * 1. CRUD lifecycle: create → verify persistence → edit → cancel edit → deactivate → reactivate → delete
 * 2. List features: search, sort, status filter
 * 3. Special features: icons, dispenser toggle, i18n translations
 *
 * Patterns: 001 (test data isolation), 004 (parallel safety),
 *           005 (test IDs), 006 (page object), 007 (fixtures), 008 (expect assertions), 009 (user flows)
 */

const API_BASE = 'http://localhost:8080/api'

/** Helper: create a category via API, returns { id, names } */
async function createCategoryViaApi(page: import('@playwright/test').Page, nameDe: string, nameEn: string) {
  const resp = await page.request.post(`${API_BASE}/admin/categories`, {
    data: { names: { de: nameDe, en: nameEn } },
    headers: await csrfHeaders(page),
  })
  expect(resp.status()).toBe(201)
  return resp.json()
}

/**
 * The product's `min_age` as the API reports it.
 *
 * Read through the list rather than a single-item GET, because that is the only
 * shape the panel has: `listProducts()` returns full products and there is no
 * `GET /admin/products/{id}`.
 */
async function minAgeOf(page: import('@playwright/test').Page, productId: string): Promise<number | null> {
  const resp = await page.request.get(`${API_BASE}/admin/products`, { params: { per_page: 100 } })
  expect(resp.status()).toBe(200)
  const row = (await resp.json()).data.find((p: { id: string }) => p.id === productId)
  expect(row, 'the product must be in the list').toBeTruthy()

  return row.min_age ?? null
}

/** Helper: create a product via API, returns full product object */
async function createProductViaApi(
  page: import('@playwright/test').Page,
  nameDe: string,
  priceCents: number,
  categoryId: string,
  overrides: Record<string, unknown> = {},
) {
  const resp = await page.request.post(`${API_BASE}/admin/products`, {
    data: { names: { de: nameDe }, price_cents: priceCents, category_id: categoryId, ...overrides },
    headers: await csrfHeaders(page),
  })
  expect(resp.status()).toBe(201)
  return resp.json()
}

/** Helper: deactivate a product via API */
async function deactivateProductViaApi(page: import('@playwright/test').Page, productId: string) {
  const resp = await page.request.patch(`${API_BASE}/admin/products/${productId}/status`, {
    data: { is_active: false },
    headers: await csrfHeaders(page),
  })
  expect(resp.ok()).toBeTruthy()
}

test.describe('Admin Products Page', () => {

  // #366: Categories moved from a top-level nav entry to a tab of Products.
  test('should switch to the Categories tab', async ({ authenticatedProductsPage, page }) => {
    await authenticatedProductsPage.expectProductsTabActive()
    await authenticatedProductsPage.clickCategoriesTab()
    await expect(page).toHaveURL(/\/products\/categories/)
  })

  test('product CRUD lifecycle: create, verify persistence, edit, cancel edit, deactivate, reactivate, delete', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const ts = Date.now()
    const prefix = `Prd${ts}`

    // ── Setup: create category via API, reload so frontend has it ─
    const cat = await createCategoryViaApi(page, `${prefix}Kat`, `${prefix}Cat`)
    await authenticatedProductsPage.reloadPage()

    // ── Create: open modal, verify empty fields ─────────────────
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    expect(await authenticatedProductsPage.getFormNameValue()).toBe('')
    expect(await authenticatedProductsPage.getFormPriceValue()).toBe('')

    // ── Fill form and submit ────────────────────────────────────
    const productName = `${prefix}Apfel`
    await authenticatedProductsPage.fillProductForm(productName, '5.99')
    await authenticatedProductsPage.selectCategory(cat.id)
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    // ── Verify: find product in table ───────────────────────────
    await authenticatedProductsPage.search(productName)
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()

    const rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData).not.toBeNull()
    expect(rowData!.name).toBe(productName)
    expect(rowData!.price).toMatch(/5[.,]99/)
    expect(rowData!.isActive).toBe(true)

    // ── Edit pre-fill: open edit, verify form pre-populated ─────
    await authenticatedProductsPage.clickEditButton(productId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getFormNameValue()).toBe(productName)
    expect(await authenticatedProductsPage.getFormPriceValue()).not.toBe('')

    // ── Edit: change name + price, submit ───────────────────────
    const editedName = `${prefix}Birne`
    await authenticatedProductsPage.fillProductForm(editedName, '7.50')
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    // ── Verify edit persisted ───────────────────────────────────
    await authenticatedProductsPage.search(editedName)
    const editedRow = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(editedRow!.name).toBe(editedName)
    expect(editedRow!.price).toMatch(/7[.,]50/)

    // ── Cancel edit: open, change, cancel, verify originals ─────
    await authenticatedProductsPage.clickEditButton(productId!)
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.fillProductForm(`${prefix}Temp`, '99.99')
    await authenticatedProductsPage.cancelForm()

    await authenticatedProductsPage.search(editedName)
    const unchangedRow = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(unchangedRow!.name).toBe(editedName)

    // ── Deactivate: toggle status ───────────────────────────────
    await authenticatedProductsPage.clickStatusToggle(productId!)
    await authenticatedProductsPage.waitForLoadingToComplete()
    const deactivatedRow = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(deactivatedRow!.isActive).toBe(false)

    // ── Reactivate: toggle again ────────────────────────────────
    await authenticatedProductsPage.clickStatusToggle(productId!)
    await authenticatedProductsPage.waitForLoadingToComplete()
    const reactivatedRow = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(reactivatedRow!.isActive).toBe(true)

    // ── Delete: click delete, confirm dialog, verify removed ────
    await authenticatedProductsPage.clickDeleteButton(productId!)
    await authenticatedProductsPage.expectConfirmDialogVisible()
    await authenticatedProductsPage.confirmDelete()

    const deletedId = await authenticatedProductsPage.getProductIdByName(editedName)
    expect(deletedId).toBeNull()
  })


  test('product list features: search, sort, status filter', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const ts = Date.now()
    const prefix = `Lst${ts}`

    // ── Setup: create category + 3 products via API ─────────────
    const cat = await createCategoryViaApi(page, `${prefix}Kat`, `${prefix}Cat`)

    await createProductViaApi(page, `${prefix}Alpha`, 100, cat.id)
    await createProductViaApi(page, `${prefix}Bravo`, 300, cat.id)
    const pCharlie = await createProductViaApi(page, `${prefix}Charlie`, 500, cat.id)

    // Deactivate Charlie for status filter testing
    await deactivateProductViaApi(page, pCharlie.id)

    // Reload so frontend has new category and products
    await authenticatedProductsPage.reloadPage()

    // ── Navigate and filter to our test category ────────────────
    await authenticatedProductsPage.filterByCategory(cat.id)

    // ── Search: exact name → one result ─────────────────────────
    await authenticatedProductsPage.search(`${prefix}Alpha`)
    const alphaId = await authenticatedProductsPage.getProductIdByName(`${prefix}Alpha`)
    expect(alphaId).not.toBeNull()

    // ── Search: partial match → multiple results ────────────────
    await authenticatedProductsPage.search(prefix)
    const allNames = await authenticatedProductsPage.getAllProductNamesInOrder()
    const matchingNames = allNames.filter(n => n.includes(prefix))
    expect(matchingNames.length).toBeGreaterThanOrEqual(3)

    // ── Search: clear → all return ──────────────────────────────
    await authenticatedProductsPage.clearSearch()
    const afterClear = await authenticatedProductsPage.getAllProductNamesInOrder()
    expect(afterClear.length).toBeGreaterThanOrEqual(3)

    // ── Search: case-insensitive (names column uses utf8mb4_bin) ─
    await authenticatedProductsPage.search(prefix.toLowerCase())
    const ciLower = await authenticatedProductsPage.getAllProductNamesInOrder()
    expect(ciLower.filter(n => n.includes(prefix)).length).toBeGreaterThanOrEqual(3)

    await authenticatedProductsPage.search(prefix.toUpperCase())
    const ciUpper = await authenticatedProductsPage.getAllProductNamesInOrder()
    expect(ciUpper.filter(n => n.includes(prefix)).length).toBeGreaterThanOrEqual(3)

    await authenticatedProductsPage.clearSearch()

    // ── Search: no results → empty state ────────────────────────
    await authenticatedProductsPage.search(`${prefix}NoExist999`)
    const emptyNames = await authenticatedProductsPage.getAllProductNamesInOrder()
    expect(emptyNames.filter(n => n.includes(prefix))).toHaveLength(0)
    await authenticatedProductsPage.clearSearch()

    // ── Sort: name asc → name desc ──────────────────────────────
    await authenticatedProductsPage.sortBy('name')
    const namesAsc = await authenticatedProductsPage.getAllProductNamesInOrder()
    const ourNamesAsc = namesAsc.filter(n => n.includes(prefix))
    for (let i = 1; i < ourNamesAsc.length; i++) {
      expect(ourNamesAsc[i].localeCompare(ourNamesAsc[i - 1])).toBeGreaterThanOrEqual(0)
    }

    await authenticatedProductsPage.sortBy('name')
    const namesDesc = await authenticatedProductsPage.getAllProductNamesInOrder()
    const ourNamesDesc = namesDesc.filter(n => n.includes(prefix))
    for (let i = 1; i < ourNamesDesc.length; i++) {
      expect(ourNamesDesc[i].localeCompare(ourNamesDesc[i - 1])).toBeLessThanOrEqual(0)
    }

    // ── Sort: price asc → price desc ────────────────────────────
    await authenticatedProductsPage.sortBy('price')
    const pricesAsc = await authenticatedProductsPage.getAllProductPricesInOrder()
    expect(pricesAsc.length).toBeGreaterThanOrEqual(3)

    await authenticatedProductsPage.sortBy('price')
    const pricesDesc = await authenticatedProductsPage.getAllProductPricesInOrder()
    expect(pricesDesc.length).toBeGreaterThanOrEqual(3)

    // ── Status filter: active only ──────────────────────────────
    await authenticatedProductsPage.filterByStatus('active')
    const activeNames = await authenticatedProductsPage.getAllProductNamesInOrder()
    const activeMatching = activeNames.filter(n => n.includes(prefix))
    expect(activeMatching.some(n => n.includes('Alpha'))).toBe(true)
    expect(activeMatching.some(n => n.includes('Bravo'))).toBe(true)
    expect(activeMatching.some(n => n.includes('Charlie'))).toBe(false)

    // ── Status filter: inactive only ────────────────────────────
    await authenticatedProductsPage.filterByStatus('inactive')
    const inactiveNames = await authenticatedProductsPage.getAllProductNamesInOrder()
    const inactiveMatching = inactiveNames.filter(n => n.includes(prefix))
    expect(inactiveMatching.some(n => n.includes('Charlie'))).toBe(true)
    expect(inactiveMatching.some(n => n.includes('Alpha'))).toBe(false)

    // ── Status filter: all ──────────────────────────────────────
    await authenticatedProductsPage.filterByStatus('all')
    const allNamesAgain = await authenticatedProductsPage.getAllProductNamesInOrder()
    const allMatching = allNamesAgain.filter(n => n.includes(prefix))
    expect(allMatching.length).toBeGreaterThanOrEqual(3)
  })


  /**
   * The Getränkewart's half of the Jugendschutz check (epic #582 M5, ADR-0045).
   *
   * One flow, because the states only mean anything relative to each other:
   * unrestricted is the default a drinks list mostly sits in, a number is what
   * makes a product refusable at the terminal, and **clearing it back to
   * unrestricted has to actually reach the row** — that is the write that makes
   * a product *more* available, so a form that silently kept the old age would
   * leave a drink restricted with the UI claiming otherwise.
   */
  test('product minimum age: unrestricted by default, set, corrected, and cleared again', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const ts = Date.now()
    const prefix = `Age${ts}`

    const cat = await createCategoryViaApi(page, `${prefix}Kat`, `${prefix}Cat`)
    await authenticatedProductsPage.reloadPage()

    // ── Default: the field is empty, and empty means unrestricted ──
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getMinAgeValue()).toBe('')

    const schorle = `${prefix}Schorle`
    await authenticatedProductsPage.fillProductForm(schorle, '2.20')
    await authenticatedProductsPage.selectCategory(cat.id)
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(schorle)
    const schorleId = await authenticatedProductsPage.getProductIdByName(schorle)
    expect(schorleId).not.toBeNull()
    expect(await minAgeOf(page, schorleId!)).toBeNull()

    // ── Set: a spirit at 18 ─────────────────────────────────────
    const korn = `${prefix}Korn`
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.fillProductForm(korn, '2.50')
    await authenticatedProductsPage.selectCategory(cat.id)
    await authenticatedProductsPage.setMinAge(18)
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(korn)
    const kornId = await authenticatedProductsPage.getProductIdByName(korn)
    expect(kornId).not.toBeNull()
    expect(await minAgeOf(page, kornId!)).toBe(18)

    // ── Prefill: the edit form shows the stored age ──────────────
    // An admin who cannot see the number on file cannot notice a wrong one,
    // and a wrong one reads as a lawful refusal at the bar.
    await authenticatedProductsPage.clickEditButton(kornId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getMinAgeValue()).toBe('18')

    // ── Correct: 18 → 16 ────────────────────────────────────────
    await authenticatedProductsPage.setMinAge(16)
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(korn)
    expect(await minAgeOf(page, kornId!)).toBe(16)

    // ── Clear: back to unrestricted, and it must reach the row ──
    await authenticatedProductsPage.clickEditButton(kornId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getMinAgeValue()).toBe('16')
    await authenticatedProductsPage.setMinAge(null)
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(korn)
    expect(
      await minAgeOf(page, kornId!),
      'clearing the field must clear the column, not leave the old age behind',
    ).toBeNull()

    // ── And the form agrees on reopening ────────────────────────
    await authenticatedProductsPage.clickEditButton(kornId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getMinAgeValue()).toBe('')
    await authenticatedProductsPage.cancelForm()
  })

  test('product special features: icons, dispenser toggle, i18n translations', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const ts = Date.now()
    const prefix = `Spc${ts}`

    // ── Setup: create category via API, reload so frontend has it ─
    const cat = await createCategoryViaApi(page, `${prefix}Kat`, `${prefix}Cat`)
    await authenticatedProductsPage.reloadPage()

    // ── Icons: verify selector, select, clear ──────────────────
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.expectIconSelectTriggerVisible()

    // Nothing selected yet — asserted on the trigger's value, not on the
    // placeholder's wording, which differs per language.
    expect(await authenticatedProductsPage.getSelectedIconName()).toBeNull()

    // Select an icon (opens dropdown internally, selects, closes)
    await authenticatedProductsPage.selectIcon('beer-pils')
    const selectedIcon = await authenticatedProductsPage.getSelectedIconName()
    expect(selectedIcon).not.toBeNull()

    // Clear icon (opens dropdown internally, clicks clear)
    await authenticatedProductsPage.clearIcon()
    const clearedIcon = await authenticatedProductsPage.getSelectedIconName()
    expect(clearedIcon).toBeNull()

    await authenticatedProductsPage.cancelForm()

    // ── Dispenser: create with dispenser → verify badge ─────────
    const dispenserName = `${prefix}Disp`
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.fillProductForm(dispenserName, '2.50')
    await authenticatedProductsPage.selectCategory(cat.id)
    await authenticatedProductsPage.checkRequiresDispenser()
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(dispenserName)
    const dispenserId = await authenticatedProductsPage.getProductIdByName(dispenserName)
    expect(dispenserId).not.toBeNull()
    expect(await authenticatedProductsPage.hasDispenserBadge(dispenserId!)).toBe(true)

    // ── Dispenser: edit to uncheck → verify no badge ────────────
    await authenticatedProductsPage.clickEditButton(dispenserId!)
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.uncheckRequiresDispenser()
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(dispenserName)
    expect(await authenticatedProductsPage.hasDispenserBadge(dispenserId!)).toBe(false)

    // ── Dispenser: edit to re-check → verify badge returns ──────
    await authenticatedProductsPage.clickEditButton(dispenserId!)
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.checkRequiresDispenser()
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(dispenserName)
    expect(await authenticatedProductsPage.hasDispenserBadge(dispenserId!)).toBe(true)

    // ── i18n: create with DE+EN names → verify DE displayed ─────
    await page.request.patch(`${API_BASE}/auth/profile`, {
      data: { locale: 'de' },
      headers: await csrfHeaders(page),
    })
    await page.evaluate(() => localStorage.setItem('locale', 'de'))
    await authenticatedProductsPage.reloadPage()

    const deName = `${prefix}Weizen`
    const enName = `${prefix}Wheat`
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.fillProductFormMultilingual({ de: deName, en: enName }, '3.50')
    await authenticatedProductsPage.selectCategory(cat.id)
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(deName)
    const i18nId = await authenticatedProductsPage.getProductIdByName(deName)
    expect(i18nId).not.toBeNull()

    const deRow = await authenticatedProductsPage.getRowDataByProductId(i18nId)
    expect(deRow!.name).toBe(deName)

    // ── i18n: verify both names preserved in edit modal ─────────
    await authenticatedProductsPage.clickEditButton(i18nId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getFormNameDe()).toBe(deName)
    expect(await authenticatedProductsPage.getFormNameEn()).toBe(enName)
    await authenticatedProductsPage.cancelForm()

    // ── i18n: create DE-only product ────────────────────────────
    const deOnlyName = `${prefix}NurDe`
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()
    await authenticatedProductsPage.fillProductFormMultilingual({ de: deOnlyName }, '4.00')
    await authenticatedProductsPage.selectCategory(cat.id)
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(deOnlyName)
    const deOnlyId = await authenticatedProductsPage.getProductIdByName(deOnlyName)
    expect(deOnlyId).not.toBeNull()
  })
})
