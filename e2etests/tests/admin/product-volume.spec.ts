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
 * - **The list is the predefined one** — the eleven sizes a club bar pours,
 *   largest first, with the "other size" escape hatch last. It is the
 *   requirement, so it is asserted on the control itself rather than inferred
 *   from one lucky pick.
 * - **Picked in millilitres, read in litres.** The option says `500 ml`,
 *   because that is what the crate says; the preview and the list say `0,5 l`,
 *   because that is what the member reads on the terminal. Both halves are
 *   asserted, for every size on offer.
 * - **What travels is millilitres.** The hidden `-value` input carries the
 *   canonical number, so the assertion is about what the API receives rather
 *   than about anybody's rendering of it.
 * - **Clearing works.** Saying "this product has no size after all" must reach
 *   the column, not return a 200 over an unchanged row.
 * - **A size the list has no answer for can still be said.** A club that pours
 *   a 0,7 l Schnapsflasche types 700 into the field behind the last option, and
 *   700 is what the API gets.
 * - **A size from before the list survives.** A product saved with 700 ml must
 *   open showing 700 ml, or an unrelated edit would silently clear it.
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
  { ml: 750, label: '750 ml', read: '0,75 l' },
  { ml: 500, label: '500 ml', read: '0,5 l' },
  { ml: 400, label: '400 ml', read: '0,4 l' },
  { ml: 330, label: '330 ml', read: '0,33 l' },
  { ml: 300, label: '300 ml', read: '0,3 l' },
  { ml: 250, label: '250 ml', read: '0,25 l' },
  { ml: 200, label: '200 ml', read: '0,2 l' },
  { ml: 100, label: '100 ml', read: '0,1 l' },
  // Below 100 ml a size reads in millilitres in both halves: `0,04 l` says
  // less about a double Schnaps than `40 ml` does (ADR-0056, decision 4).
  { ml: 40, label: '40 ml', read: '40 ml' },
  { ml: 20, label: '20 ml', read: '20 ml' },
]

/** The last option: "not on the list — let me type it." */
const CUSTOM = 'custom'

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

    // The sizes first, in order, and the escape hatch after them.
    expect(await authenticatedProductsPage.getVolumeOptionValues()).toEqual([
      ...SIZES.map((size) => String(size.ml)),
      CUSTOM,
    ])
    expect(await authenticatedProductsPage.getVolumeOptionLabels()).toEqual([
      ...SIZES.map((size) => size.label.replace(' ', '\u00a0')),
      'Andere Größe …',
    ])

    // A listed size is picked, not typed: the field stays shut.
    await authenticatedProductsPage.setVolume(500)
    expect(await authenticatedProductsPage.getCustomVolumeText()).toBeNull()

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
   * The size a club pours that the list has no answer for.
   *
   * A picker cannot be complete — a 0,7 l Schnapsflasche, a 1,5 l PET bottle —
   * and a picker with no answer is how the size goes back into the product
   * name, which is the habit ADR-0056 exists to end. The last option opens a
   * millilitre field instead.
   */
  test('a size the list does not offer is typed in millilitres and reaches the API', async ({
    authenticatedProductsPage,
    page,
  }) => {
    const category = await createCategoryViaApi(page)
    const productName = `Obstler ${unique()}`

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // The field is not there until it is asked for: a listed size is picked.
    expect(await authenticatedProductsPage.getCustomVolumeText()).toBeNull()

    await authenticatedProductsPage.fillProductForm(productName, '2.80')
    await authenticatedProductsPage.selectCategory(category.id)
    await authenticatedProductsPage.setCustomVolume(700)

    expect(await authenticatedProductsPage.getCustomVolumeText()).toBe('700')
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('700')
    // The preview is the guard on a typed size: it spells out what the member
    // will read, right beside the field being typed into.
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe('0,7 l')

    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId, 'the created product must be findable by name').toBeTruthy()
    expect(await volumeOf(page, productId!)).toBe(700)
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId!))).toBe('0,7 l')

    // --- Reopen: a size the list does not contain comes back in the field,
    // not as a blank picker that the next save would clear.
    await authenticatedProductsPage.clickEditButton(productId!)
    await authenticatedProductsPage.expectFormModalVisible()
    expect(await authenticatedProductsPage.getCustomVolumeText()).toBe('700')
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('700')

    // --- And it is editable, which a picker could not make it: 0,7 l becomes
    // the 1 l bottle, off the list, and the field shuts behind it.
    await authenticatedProductsPage.setVolume(1000)
    expect(await authenticatedProductsPage.getCustomVolumeText()).toBeNull()
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('1000')

    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()
    await authenticatedProductsPage.search(productName)
    expect(await volumeOf(page, productId!)).toBe(1000)
  })

  /**
   * The mistake the typed field must not swallow: a size entered in litres.
   *
   * The litres field this replaced had the opposite failure — `<input
   * type="number">` reported `0,5` as the empty string, so a German admin's
   * price or size simply vanished (#863). A millilitre is a whole number, so
   * the separator is not a character this field has at all: `0,5` becomes `5`,
   * and the preview beside it says `5 ml` rather than quietly storing half a
   * litre.
   */
  test('the typed field takes whole millilitres only, and shows what it made of them', async ({
    authenticatedProductsPage,
  }) => {
    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    await authenticatedProductsPage.openCustomVolume()
    // Opened, and empty: asking to type is not yet a size.
    expect(await authenticatedProductsPage.getCustomVolumeText()).toBe('')
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('')
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBeNull()

    // A comma is not dropped into the void — it is not typed at all.
    await authenticatedProductsPage.setCustomVolume('0,5')
    expect(await authenticatedProductsPage.getCustomVolumeText()).toBe('5')
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('5')
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe('5 ml')

    // …and the admin, seeing `5 ml` where they meant half a litre, corrects it.
    await authenticatedProductsPage.setCustomVolume('1500')
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('1500')
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe('1,5 l')
  })

  /**
   * A size from before the list existed.
   *
   * Sizes were typed once, so a club can hold 700 ml on a Schnapsflasche — a
   * size the picker still does not offer. A control that showed only the
   * presets would show that product as having *no* size, and the next save of
   * an unrelated field would clear a column nobody touched. It opens the typed
   * field with the size in it instead.
   */
  test('a size the list does not contain survives an unrelated edit', async ({
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
        volume_ml: 700,
      },
      headers: await csrfHeaders(page),
    })
    expect(created.status(), await created.text()).toBe(201)
    const productId = (await created.json()).id

    await authenticatedProductsPage.reloadPage()
    await authenticatedProductsPage.search(productName)
    await authenticatedProductsPage.clickEditButton(productId)
    await authenticatedProductsPage.expectFormModalVisible()

    // In the field, and the picker says so rather than falling back to "no size".
    expect(await authenticatedProductsPage.getFormVolumeValue()).toBe('700')
    expect(await authenticatedProductsPage.getCustomVolumeText()).toBe('700')
    expect(await authenticatedProductsPage.getVolumeOptionValues()).toEqual([
      ...SIZES.map((size) => String(size.ml)),
      CUSTOM,
    ])
    expect(plain(await authenticatedProductsPage.getPreviewVolume())).toBe('0,7 l')

    // An edit that says nothing about the size must leave it alone.
    await authenticatedProductsPage.fillPrice('15.00')
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    await authenticatedProductsPage.search(productName)
    expect(await volumeOf(page, productId)).toBe(700)
    expect(plain(await authenticatedProductsPage.getVolumeInList(productId))).toBe('0,7 l')
  })
})
