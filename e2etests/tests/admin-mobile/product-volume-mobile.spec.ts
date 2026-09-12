/**
 * Admin Panel — the product size picker on a phone (ADR-0056).
 *
 * The size is picked from the sizes a club pours and stored in millilitres. On
 * a phone that choice is the whole reason the control is a native `<select>`:
 * iOS and Android render one as a full-width wheel or sheet, so a size is set
 * with a thumb rather than typed on a keypad — which is where the size field's
 * predecessor was at its worst, a decimal separator on a numeric keyboard that
 * `<input type="number">` would have reported as the empty string (#863).
 *
 * The last option opens a millilitre field for the sizes the list cannot carry.
 * That is typing, on a phone, which is exactly what the picker exists to avoid
 * — so it has to be as hittable as the picker, and it must bring up a keypad
 * with no separator on it rather than a full keyboard.
 *
 * What has to hold on a 390 px screen:
 *
 * - both controls fit the modal rather than overflowing it, and are tall enough
 *   to hit;
 * - the sizes the picker offers are the predefined ones, escape hatch last;
 * - a picked size reaches the API as millilitres and comes back on the card in
 *   the reader's notation — litres.
 *
 * Project config uses `devices['iPhone 14']` (390x844).
 */

import { test, expect } from '@playwright/test'

const API_BASE = 'http://localhost:8080/api'

function unique(): string {
  return Math.random().toString(36).substring(2, 10)
}

/** The panel's CSRF token, read the way the app reads it on boot. */
async function csrfHeaders(page: import('@playwright/test').Page) {
  const profile = await page.request.get(`${API_BASE}/auth/profile`)
  expect(profile.status()).toBe(200)
  return { 'X-CSRF-Token': (await profile.json()).csrf_token }
}

async function openProductForm(page: import('@playwright/test').Page) {
  await page.goto('/products')
  await page.getByTestId('products-create-button').click()
  await expect(page.getByTestId('products-form-volume-select')).toBeVisible()
}

test.describe('Product volume — mobile', () => {
  test('the size picker fits the 390 px modal and offers the predefined sizes', async ({
    page,
  }) => {
    await openProductForm(page)

    const field = page.getByTestId('products-form-volume-select')
    const value = page.getByTestId('products-form-volume-select-value')

    // The control must fit the modal rather than overflow it — 390 px minus the
    // modal's own padding — and be tall enough to hit with a thumb.
    const box = await field.boundingBox()
    expect(box, 'the size picker must be laid out').toBeTruthy()
    expect(box!.width).toBeGreaterThan(200)
    expect(box!.width).toBeLessThanOrEqual(390)
    expect(box!.height).toBeGreaterThanOrEqual(44)

    // A product with no size is the default: nothing picked, nothing sent.
    await expect(value).toHaveValue('')

    // The sizes on offer, in millilitres, largest first — and the escape hatch
    // after them rather than competing with them.
    const offered = await field.locator('option').evaluateAll((options) =>
      options.map((option) => (option as HTMLOptionElement).value).filter((v) => v !== ''),
    )
    expect(offered).toEqual([
      '1000',
      '750',
      '500',
      '400',
      '330',
      '300',
      '250',
      '200',
      '100',
      '40',
      '20',
      'custom',
    ])

    // Picking one sets the millilitres the API will receive…
    await field.selectOption('500')
    await expect(value).toHaveValue('500')

    // …and the empty option is how "this product has no size" is said.
    await field.selectOption('')
    await expect(value).toHaveValue('')
  })

  test('the millilitre field is thumb-sized and opens a keypad, not a keyboard', async ({
    page,
  }) => {
    await openProductForm(page)

    const custom = page.getByTestId('products-form-volume-select-custom')
    const value = page.getByTestId('products-form-volume-select-value')

    // It is not on screen until it is asked for: picking is the ordinary path.
    await expect(custom).toBeHidden()

    await page.getByTestId('products-form-volume-select').selectOption('custom')
    await expect(custom).toBeVisible()

    const box = await custom.boundingBox()
    expect(box, 'the millilitre field must be laid out').toBeTruthy()
    expect(box!.width).toBeGreaterThan(200)
    expect(box!.width).toBeLessThanOrEqual(390)
    expect(box!.height).toBeGreaterThanOrEqual(44)

    // `numeric`, not `decimal`: there is no separator in a millilitre, so the
    // key that broke the litres field on a German keypad (#863) is not on this
    // one at all. And `text`, not `number`, so a rejected character cannot be
    // reported back as an empty field.
    await expect(custom).toHaveAttribute('inputmode', 'numeric')
    await expect(custom).toHaveAttribute('type', 'text')

    await custom.fill('1500')
    await expect(value).toHaveValue('1500')
  })

  test('a size picked on a phone reaches the API as millilitres and shows on the card', async ({
    page,
  }) => {
    const id = unique()
    const productName = `Cola ${id}`

    const headers = await csrfHeaders(page)
    const category = await page.request.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat ${id}`, en: `Cat ${id}` } },
      headers,
    })
    expect(category.status(), await category.text()).toBe(201)
    const categoryId = (await category.json()).id

    await openProductForm(page)

    await page.getByTestId('products-form-name-input-de').fill(productName)
    await page.getByTestId('products-form-price-input').fill('2,50')
    // CategorySelect is a custom dropdown, not a native <select>.
    await page.getByTestId('products-form-category-select-trigger').click()
    await expect(page.getByTestId('products-form-category-select-dropdown')).toBeVisible()
    await page.getByTestId(`products-form-category-select-option-${categoryId}`).click()
    await page.getByTestId('products-form-volume-select').selectOption('330')

    await page.getByTestId('products-form-submit-button').click()
    await expect(page.getByTestId('products-form-volume-select')).toBeHidden()

    // What the API stored is the millilitres the option carried.
    const list = await page.request.get(`${API_BASE}/admin/products`, {
      params: { category_id: categoryId, per_page: 50 },
    })
    expect(list.status()).toBe(200)
    const row = (await list.json()).data.find(
      (p: { names: Record<string, string> }) => p.names.de === productName,
    )
    expect(row, 'the created product must be in its own category').toBeTruthy()
    expect(row.volume_ml).toBe(330)

    // And the mobile card prints it beside the name in litres — picked as
    // `330 ml`, read as `0,33 l` — inside the name slot, so it ellipsizes with
    // the name instead of pushing the price off a narrow card.
    const cell = page.getByTestId(`products-table-cell-volume-${row.id}`)
    await expect(cell).toBeVisible()
    // The unit is held to the number by a NO-BREAK SPACE, so the expectation
    // spells it as an escape rather than as an invisible character.
    expect((await cell.innerText()).trim().replace(/\u00a0/g, ' ')).toBe('0,33 l')
  })
})
