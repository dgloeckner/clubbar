import { test, expect } from '@playwright/test'

test('debug: Check if product names are displaying', async ({ page }) => {
  // Use stored auth state
  await page.context().addInitScript((storageState) => {
    const state = storageState as any
    if (state.cookies) {
      document.cookie = state.cookies.map((c: any) => `${c.name}=${c.value}`).join('; ')
    }
  }, {})

  await page.goto('http://localhost:5173/products')
  await page.waitForLoadState('networkidle')

  // Give time for console logs
  await page.waitForTimeout(1500)

  // Get all product name cells
  const nameCells = page.locator('[data-testid*="products-table-cell-name-"]')
  const count = await nameCells.count()
  console.log(`Found ${count} product names in table`)

  // Get text content of each name cell
  for (let i = 0; i < Math.min(count, 5); i++) {
    const text = await nameCells.nth(i).textContent()
    console.log(`Name ${i}: "${text?.trim()}"`)
  }

  // Also check the first product row
  const firstRow = page.locator('tr[data-testid*="products-table-row-"]').first()
  const rowText = await firstRow.textContent()
  console.log(`First row text: "${rowText?.substring(0, 150)}"`)
})
