/**
 * Admin Panel — the product size field on a phone (#878 M4, ADR-0056).
 *
 * The size is typed in litres and stored in millilitres, and the control is a
 * text input with `inputMode="decimal"` rather than `<input type="number">` —
 * which is exactly the difference that matters on a phone. A numeric keypad in
 * a German panel emits a **dot**, and the native control would have reported a
 * comma as the empty string (#863). Both have to be read as the same size, and
 * the field has to be reachable and legible inside a 390 px modal.
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
  await expect(page.getByTestId('products-form-volume-input')).toBeVisible()
}

test.describe('Product volume — mobile', () => {
  test('the size field is reachable and typeable inside the 390 px modal', async ({ page }) => {
    await openProductForm(page)

    const field = page.getByTestId('products-form-volume-input')
    const value = page.getByTestId('products-form-volume-input-value')

    // `decimal` is what puts a separator on the phone's keypad without bringing
    // the browser's own number parsing with it.
    await expect(field).toHaveAttribute('inputmode', 'decimal')
    await expect(field).toHaveAttribute('type', 'text')

    // The field must fit the modal rather than overflow it — 390 px minus the
    // modal's own padding.
    const box = await field.boundingBox()
    expect(box, 'the size field must be laid out').toBeTruthy()
    expect(box!.width).toBeGreaterThan(200)
    expect(box!.width).toBeLessThanOrEqual(390)

    // The comma the panel's default language writes.
    await field.fill('0,5')
    await field.blur()
    await expect(value).toHaveValue('500')
    await expect(field).toHaveValue('0,5')

    // …and the dot a numeric keypad emits, read as the same size.
    await field.fill('0.5')
    await field.blur()
    await expect(value).toHaveValue('500')
    await expect(field).toHaveValue('0,5')

    // Clearing it means "this product has no size".
    await field.fill('')
    await field.blur()
    await expect(value).toHaveValue('')
  })

  test('a size typed on a phone reaches the API as millilitres and shows on the card', async ({
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
    await page.getByTestId('products-form-volume-input').fill('0,33')
    await page.getByTestId('products-form-volume-input').blur()

    await page.getByTestId('products-form-submit-button').click()
    await expect(page.getByTestId('products-form-volume-input')).toBeHidden()

    // What the API stored is millilitres, whatever the phone's keypad emitted.
    const list = await page.request.get(`${API_BASE}/admin/products`, {
      params: { category_id: categoryId, per_page: 50 },
    })
    expect(list.status()).toBe(200)
    const row = (await list.json()).data.find(
      (p: { names: Record<string, string> }) => p.names.de === productName,
    )
    expect(row, 'the created product must be in its own category').toBeTruthy()
    expect(row.volume_ml).toBe(330)

    // And the mobile card prints it beside the name, in the reader's notation —
    // inside the name slot, so it ellipsizes with the name instead of pushing
    // the price off a narrow card.
    const cell = page.getByTestId(`products-table-cell-volume-${row.id}`)
    await expect(cell).toBeVisible()
    expect((await cell.innerText()).trim().replace(/ /g, ' ')).toBe('0,33 l')
  })
})
