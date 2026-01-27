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
  console.log(`Creating product: ${productName}`)

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

  // Log API responses
  console.log('\n=== API RESPONSES ===')
  apiResponses.forEach((resp, i) => {
    console.log(`\nResponse ${i}:`)
    console.log(`URL: ${resp.url}`)
    console.log(`Status: ${resp.status}`)
    if (resp.data?.items?.length) {
      console.log(`Items in response: ${resp.data.items.length}`)
      const productNames = resp.data.items.map((p: any) => p.names?.de || 'NO NAME')
      console.log(`Product names: ${productNames.slice(0, 5).join(', ')}`)
      const found = resp.data.items.find((p: any) => p.names?.de?.includes(productName))
      console.log(`Found created product: ${found ? 'YES' : 'NO'}`)
    }
  })
})
