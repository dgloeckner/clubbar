import { test, expect } from '../../fixtures/pageObjects'
import { csrfHeaders } from '../../utils/csrf'

/**
 * Admin Panel — a product's size, typed in litres and stored in millilitres
 * (#878 M4, ADR-0056).
 *
 * The size used to live inside the name. Moving it into `volume_ml` means an
 * admin types a number rather than punctuation, and each reader is shown that
 * number in their own notation.
 *
 * What has to hold end to end, and why each is asserted rather than assumed:
 *
 * - **A German admin can type `0,5`.** This is the whole reason the field is
 *   not `<input type="number">`: that control reports a comma to script as the
 *   *empty string*, so a price typed the way German writes one silently handed
 *   the form nothing (#863). German is the panel's default language, so the
 *   comma is the default case, not the edge one.
 * - **What travels is millilitres.** The hidden `-value` input carries the
 *   canonical number, so the assertion is about what the API receives rather
 *   than about the locale's rendering of it.
 * - **The list shows the reader's notation.** That is the payoff of storing one
 *   language-neutral number.
 * - **Clearing works.** Saying "this product has no size after all" must reach
 *   the column, not return a 200 over an unchanged row.
 *
 * Patterns: 001 (test data isolation), 004 (parallel safety), 005 (test IDs),
 *           006 (page object), 007 (fixtures), 008 (expect assertions),
 *           009 (user flows)
 */

const API_BASE = 'http://localhost:8080/api'

/**
 * The rendered size, with its NO-BREAK SPACE turned into an ordinary one.
 *
 * The formatter puts U+00A0 between the number and the unit so a size never
 * wraps across a line. That is asserted once, on its own, below; every other
 * assertion is about the *size*, and reads better without an invisible
 * character in the expected value.
 */
function plain(text: string | null): string | null {
  return text === null ? null : text.replace(/\u00a0/g, ' ')
}

function unique(): string {
  return Math.random().toString(36).substring(2, 10)
}

async function createCategoryViaApi(page: import('@playwright/test').Page) {
  const id = unique()
  const resp = await page.request.post(`${API_BASE}/admin/categories`, {
    data: { names: { de: `Kat ${id}`, en: `Cat ${id}` } },
    headers: await csrfHeaders(page),
  })
  expect(resp.status(), await resp.text()).toBe(201)
  return resp.json()
}

/**
 * The product's `volume_ml` as the API reports it.
 *
 * Read through the list rather than a single-item GET, because that is the only
 * shape the panel has: there is no `GET /admin/products/{id}`.
 */
async function volumeOf(
  page: import('@playwright/test').Page,
  productId: string,
): Promise<number | null> {
  const resp = await page.request.get(`${API_BASE}/admin/products`, { params: { per_page: 100 } })
  expect(resp.status()).toBe(200)
  const row = (await resp.json()).data.find((p: { id: string }) => p.id === productId)
  expect(row, 'the product must be in the list').toBeTruthy()

  return row.volume_ml ?? null
}

test.describe('Product volume', () => {
  /**
   * The flow this milestone exists for: type a size the German way, see it on
   * the list the German way, confirm the API got a number, then clear it.
   */
  test('a size typed as 0,5 is stored as 500 ml, listed as 0,5 l, and can be cleared', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Weizenbier ${unique()}`

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // A product with no size is the default: nothing typed, nothing sent.
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('')

    await authenticatedProductsPage.fillProductForm(productName, '4.20')
    await authenticatedProductsPage.selectCategory(category.id)
    // The German notation, which is what the panel defaults to and what the
    // native number input could not read.
    await authenticatedProductsPage.setVolume('0,5')

    // Millilitres on the wire, litres on screen.
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('500')
    expect(await authenticatedProductsPage.getFormVolumeText()).toBe('0,5')

    // The preview shows the badge the terminal will draw.
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe('0,5 l')

    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId, 'the created product must be findable by name').toBeTruthy()

    // The list prints the size after the name, in the reader's notation.
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId!))).toBe('0,5 l')
    // …and the unit is held to the number by a NO-BREAK SPACE, so a narrow
    // column cannot leave `0,5` on one line and `l` on the next.
    expect(await authenticatedProductsPage.getVolumeInList(productId!)).toBe('0,5\u00a0l')

    // And the number that actually travelled is millilitres.
    expect(await volumeOf(page, productId!)).toBe(500)

    // --- Reopen: the stored value comes back reading the way it was typed.
    await authenticatedProductsPage.clickEditButton(productId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('500')
    expect(await authenticatedProductsPage.getFormVolumeText()).toBe('0,5')

    // --- Clear it: "this product has no size after all".
    await authenticatedProductsPage.setVolume(null)
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('')
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    // Null must reach the column, not be dropped as an absent key.
    expect(await volumeOf(page, productId!)).toBeNull()
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId!))).toBeNull()
  })

  /**
   * A numeric keypad emits a dot in a German panel, so the dot has to be
   * accepted and rewritten as the locale's separator while it is typed.
   */
  test('a dot typed into the German panel is read as the same size', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Apfelschorle ${unique()}`

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.fillProductForm(productName, '2.50')
    await authenticatedProductsPage.selectCategory(category.id)
    await authenticatedProductsPage.setVolume('0.33')

    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('330')
    expect(await authenticatedProductsPage.getFormVolumeText()).toBe('0,33')

    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(await volumeOf(page, productId!)).toBe(330)
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId!))).toBe('0,33 l')
  })

  /**
   * A product with no size is the ordinary state of a snacks list. It must be
   * saveable with the field untouched, and the preview must still reserve the
   * badge's row — on the terminal that row is what holds every price on a grid
   * row level, and a preview that collapsed it would show a tile the terminal
   * will never draw.
   */
  test('a product with no size saves untouched, and the preview still reserves the badge row', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Sauna-Token ${unique()}`

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.fillProductForm(productName, '3.00')
    await authenticatedProductsPage.selectCategory(category.id)

    expect(await authenticatedProductsPage.isPreviewVolumeRowPresent()).toBe(true)
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBeNull()

    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(await volumeOf(page, productId!)).toBeNull()
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId!))).toBeNull()
  })

  /**
   * The mask is not the validator: a size above ten litres reaches the page's
   * own refusal, in the admin's language, rather than being silently clamped or
   * handed to the backend for a 422 nobody can read.
   */
  test('a size above ten litres is refused by the form, in a sentence', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Fass ${unique()}`

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.fillProductForm(productName, '50.00')
    await authenticatedProductsPage.selectCategory(category.id)
    await authenticatedProductsPage.setVolume('50')

    // Not clamped to 10 000 — the number the admin typed is still there.
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('50000')

    await authenticatedProductsPage.submitFormExpectingRefusal()

    // The form stays open with its own message, rather than closing on a 422.
    await authenticatedProductsPage.expectFormModalVisible()
    const error = await authenticatedProductsPage.getFormError()
    expect(error, 'the form must say why it refused').toBeTruthy()
  })
})
