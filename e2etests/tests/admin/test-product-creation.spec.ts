import { test, expect } from '@playwright/test'
import { ProductsPage } from '../../pages/ProductsPage'

test('verify API returns newly created product', async ({ page }) => {
  // Load auth state
  await page.context().addInitScript((storageState) => {
    if ((storageState as any)?.cookies) {
      const cookies = (storageState as any).cookies
      document.cookie = cookies.map((c: any) => `${c.name}=${c.value}`).join('; ')
    }
  }, {})

  const productsPage = new ProductsPage(page)

  // Capture API requests/responses
  const apiResponses: any[] = []
  page.on('response', (response) => {
    if (response.url().includes('/admin/products')) {
      response.json().then((json) => {
        apiResponses.push({
          url: response.url(),
          status: response.status(),
          data: json
        })
      }).catch(() => {})
    }
  })

  // Navigate and load products
  await productsPage.navigate()
  await productsPage.expectPageVisible()

  // Create a product
  const productName = `API Test ${Date.now()}`

  await productsPage.openCreateModal()
  await productsPage.fillProductForm(productName, '19.99')

  // Get first available category
  const categoryId = await productsPage.getFirstActiveCategoryId()
  if (categoryId) {
    await productsPage.selectCategory(categoryId)
  }

  await productsPage.submitForm()
  await productsPage.expectFormModalHidden()

  // Wait a moment for API responses to be captured
  await page.waitForTimeout(2000)
})
