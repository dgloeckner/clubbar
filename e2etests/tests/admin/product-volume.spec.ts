import { test, expect } from '../../fixtures/pageObjects'
import { csrfHeaders } from '../../utils/csrf'

/**
 * Admin Panel — a product's size, picked from a list and stored in millilitres
 * (ADR-0056).
 *
 * The size used to live inside the name, then briefly in a typed litres field.
 * It is now picked from the sizes a club pours, which is what removes the last
 * way to get it wrong: no decimal separator to read, no `50` where `0,5` was
 * meant, nothing to refuse.
 *
 * What has to hold end to end, and why each is asserted rather than assumed:
 *
 * - **The list is the predefined one** — 1000, 500, 330, 300, 250 and 200 ml,
 *   in that order. It is the requirement, so it is asserted on the control itself
 *   rather than inferred from one lucky pick.
 * - **Picked in millilitres, read in litres.** The option says `500 ml`,
 *   because that is what the crate says; the preview and the list say `0,5 l`,
 *   because that is what the member reads on the terminal. Both halves are
 *   asserted, for every size on offer.
 * - **What travels is millilitres.** The hidden `-value` input carries the
 *   canonical number, so the assertion is about what the API receives rather
 *   than about anybody's rendering of it.
 * - **Clearing works.** Saying "this product has no size after all" must reach
 *   the column, not return a 200 over an unchanged row.
 * - **A size from before the list survives.** A product saved with 750 ml must
 *   still offer and hold 750 ml, or an unrelated edit would silently clear it.
 *
 * Patterns: 001 (test data isolation), 004 (parallel safety), 005 (test IDs),
 *           006 (page object), 007 (fixtures), 008 (expect assertions),
 *           009 (user flows)
 */

const API_BASE = 'http://localhost:8080/api'

/**
 * The sizes the picker offers, and what each one is read as.
 *
 * The German column, because the panel's default language is German and these
 * tests run in it. The full locale matrix is the formatter's own business and
 * is checked against `api/fixtures/volume-format.json` in the unit suites.
 */
const SIZES = [
  { ml: 1000, label: '1000 ml', read: '1 l' },
  { ml: 500, label: '500 ml', read: '0,5 l' },
  { ml: 330, label: '330 ml', read: '0,33 l' },
  { ml: 300, label: '300 ml', read: '0,3 l' },
  { ml: 250, label: '250 ml', read: '0,25 l' },
  { ml: 200, label: '200 ml', read: '0,2 l' },
]

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
   * The flow this feature exists for: pick a size off the list, see the litres
   * a member will read, confirm the API got millilitres, then clear it.
   */
  test('a size picked as 500 ml is stored as 500, read as 0,5 l, and can be cleared', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Weizenbier ${unique()}`

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // A product with no size is the default: nothing picked, nothing sent.
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('')

    await authenticatedProductsPage.fillProductForm(productName, '4.20')
    await authenticatedProductsPage.selectCategory(category.id)
    await authenticatedProductsPage.setVolume(500)

    // Millilitres on the wire, millilitres on the option, litres in the preview.
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('500')
    expect(plain(await authenticatedProductsPage.getFormVolumeText())).toBe('500 ml')
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe('0,5 l')

    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId, 'the created product must be findable by name').toBeTruthy()

    // The list prints the size after the name, in litres.
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId!))).toBe('0,5 l')
    // …and the unit is held to the number by a NO-BREAK SPACE, so a narrow
    // column cannot leave `0,5` on one line and `l` on the next.
    expect(await authenticatedProductsPage.getVolumeInList(productId!)).toBe('0,5\u00a0l')

    // And the number that actually travelled is millilitres.
    expect(await volumeOf(page, productId!)).toBe(500)

    // --- Reopen: the stored size comes back selected.
    await authenticatedProductsPage.clickEditButton(productId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('500')
    expect(plain(await authenticatedProductsPage.getFormVolumeText())).toBe('500 ml')

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
   * The predefined list itself, and the pairing that makes it readable: a size
   * is *chosen* in the unit a crate is labelled in and *read* in the unit a
   * member drinks in.
   */
  test('the picker offers the predefined sizes in millilitres, and previews each in litres', async ({
    authenticatedProductsPage,
  }) => {
    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    expect(await authenticatedProductsPage.getVolumeOptionValues()).toEqual(
      SIZES.map((size) => String(size.ml)),
    )
    expect(await authenticatedProductsPage.getVolumeOptionLabels()).toEqual(
      SIZES.map((size) => size.label.replace(' ', '\u00a0')),
    )

    for (const size of SIZES) {
      await authenticatedProductsPage.setVolume(size.ml)
      expect(await authenticatedProductsPage.getFormVolumeValue()).toBe(String(size.ml))
      // The preview is what the admin checks the choice against, and it speaks
      // the terminal's language: litres.
      expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe(size.read)
    }
  })

  /**
   * A product with no size is the ordinary state of a snacks list. It must be
   * saveable with the picker untouched, and the preview's pill must then carry
   * the price alone — not a dash, not an empty segment.
   */
  test('a product with no size saves untouched, and the preview pill shows just the price', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Sauna-Token ${unique()}`

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.fillProductForm(productName, '3.00')
    await authenticatedProductsPage.selectCategory(category.id)

    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBeNull()
    expect(plain(await authenticatedProductsPage.getPreviewPricePill())).toMatch(/^3[,.]00\s€$/)

    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(await volumeOf(page, productId!)).toBeNull()
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId!))).toBeNull()
  })

  /**
   * A size from before the list existed.
   *
   * Sizes were typed once, so a club can hold 750 ml on a wine bottle. A picker
   * that offered only the presets would show that product as having *no* size,
   * and the next save of an unrelated field would clear a column nobody
   * touched. The size is offered back instead — kept, selected, and saved
   * unchanged.
   */
  test('a size the list does not contain is offered back and survives an unrelated edit', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Weinflasche ${unique()}`

    const created = await page.request.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: productName, en: productName },
        category_id: category.id,
        price_cents: 1450,
        volume_ml: 750,
      },
      headers: await csrfHeaders(page),
    })
    expect(created.status(), await created.text()).toBe(201)
    const productId = (await created.json()).id

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.search(productName)
    await authenticatedProductsPage.clickEditButton(productId)
    await authenticatedProductsPage.expectFormModalVisible()

    // Selected, and on the list — between the sizes it sits between.
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('750')
    expect(plain(await authenticatedProductsPage.getFormVolumeText())).toBe('750 ml')
    expect(await authenticatedProductsPage.getVolumeOptionValues()).toEqual([
      '1000',
      '750',
      '500',
      '330',
      '300',
      '250',
      '200',
    ])
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe('0,75 l')

    // An edit that says nothing about the size must leave it alone.
    await authenticatedProductsPage.fillPrice('15.00')
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    expect(await volumeOf(page, productId)).toBe(750)
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId))).toBe('0,75 l')
  })
})
