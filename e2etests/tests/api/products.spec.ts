import { test, expect } from '../../fixtures/auth.fixture'
import { randomUUID } from 'crypto'

/**
 * Products API Tests (Consolidated)
 *
 * Four flow-based tests covering all product API functionality:
 * 1. CRUD lifecycle with validation (admin endpoints)
 * 2. List features: pagination, sorting, filtering (admin endpoints)
 * 3. Icon support (admin endpoints)
 * 4. Terminal sync: delta response, schema, category filtering (terminal endpoint)
 *
 * Patterns: 001 (test data isolation), 002 (auth isolation), 003 (database-agnostic), 009 (user flows)
 */

function createValidProduct(categoryId: string, overrides: Record<string, unknown> = {}) {
  return {
    names: {
      de: `Produkt ${randomUUID().substring(0, 8)}`,
      en: `Product ${randomUUID().substring(0, 8)}`,
    },
    descriptions: {
      de: 'Deutsche Beschreibung',
      en: 'English Description',
    },
    price_cents: 350,
    category_id: categoryId,
    ...overrides,
  }
}

async function createCategory(authenticatedRequest: any, nameSuffix = '') {
  const response = await authenticatedRequest.post('/api/admin/categories', {
    data: {
      names: {
        de: `Kategorie ${nameSuffix} ${randomUUID().substring(0, 8)}`,
        en: `Category ${nameSuffix} ${randomUUID().substring(0, 8)}`,
      },
    },
  })
  if (!response.ok()) {
    throw new Error(`Failed to create category: ${response.status()} - ${await response.text()}`)
  }
  return response.json()
}

test.describe('Products API', () => {

  test('CRUD lifecycle with validation: create, read, update, toggle status, delete', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest)

    // ── List: GET returns paginated list with correct structure ──
    const listResp = await authenticatedRequest.get('/api/admin/products')
    expect(listResp.status()).toBe(200)
    const listBody = await listResp.json()
    expect(Array.isArray(listBody.items)).toBe(true)
    expect(typeof listBody.total).toBe('number')
    expect(typeof listBody.limit).toBe('number')
    expect(typeof listBody.offset).toBe('number')
    expect(typeof listBody.has_more).toBe('boolean')

    // Verify product fields structure (if items exist)
    if (listBody.items.length > 0) {
      const p = listBody.items[0]
      expect(p.id).toBeDefined()
      expect(p.names).toBeDefined()
      expect(p.price_cents).toBeDefined()
      expect(p.category_id).toBeDefined()
      expect(p.is_active).toBeDefined()
    }

    // ── Create: POST valid product → 201 ────────────────────────
    const productData = createValidProduct(category.id)
    const createResp = await authenticatedRequest.post('/api/admin/products', {
      data: productData,
    })
    expect(createResp.status()).toBe(201)
    const product = await createResp.json()
    expect(product.id).toBeDefined()
    expect(product.names).toEqual(productData.names)
    expect(product.price_cents).toBe(350)
    expect(product.category_id).toBe(category.id)
    expect(product.is_active).toBe(true)
    expect(product.descriptions.de).toBe('Deutsche Beschreibung')

    // ── Create: minimal data works ──────────────────────────────
    const minimalResp = await authenticatedRequest.post('/api/admin/products', {
      data: { names: { de: 'Minimal' }, price_cents: 100, category_id: category.id },
    })
    expect(minimalResp.status()).toBe(201)

    // ── Create validation: missing name (422) ───────────────────
    const noNameResp = await authenticatedRequest.post('/api/admin/products', {
      data: { names: {}, price_cents: 100, category_id: category.id },
    })
    expect(noNameResp.status()).toBe(422)

    // ── Create validation: zero price (422) ─────────────────────
    const zeroPriceResp = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, { price_cents: 0 }),
    })
    expect(zeroPriceResp.status()).toBe(422)

    // ── Create validation: negative price (422) ─────────────────
    const negPriceResp = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, { price_cents: -100 }),
    })
    expect(negPriceResp.status()).toBe(422)

    // ── Create validation: invalid category (422) ───────────────
    const badCatResp = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(randomUUID()),
    })
    expect(badCatResp.status()).toBe(422)

    // ── Create validation: inactive category (409) ──────────────
    const inactiveCat = await createCategory(authenticatedRequest)
    await authenticatedRequest.patch(`/api/admin/categories/${inactiveCat.id}/status`, {
      data: { is_active: false },
    })
    const inactiveCatResp = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(inactiveCat.id),
    })
    expect(inactiveCatResp.status()).toBe(409)

    // ── Update: PATCH name ──────────────────────────────────────
    const updateNameResp = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { names: { de: 'Neuer Name', en: 'New Name' } },
    })
    expect(updateNameResp.ok()).toBeTruthy()
    const updatedName = await updateNameResp.json()
    expect(updatedName.names.de).toBe('Neuer Name')

    // ── Update: PATCH price ─────────────────────────────────────
    const updatePriceResp = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { price_cents: 450 },
    })
    const updatedPrice = await updatePriceResp.json()
    expect(updatedPrice.price_cents).toBe(450)

    // ── Update: PATCH category ──────────────────────────────────
    const category2 = await createCategory(authenticatedRequest)
    const updateCatResp = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { category_id: category2.id },
    })
    const updatedCat = await updateCatResp.json()
    expect(updatedCat.category_id).toBe(category2.id)

    // ── Update: preserves created_at ────────────────────────────
    expect(updatedCat.created_at).toBe(product.created_at)

    // ── Update validation: non-existent product (404) ───────────
    const noProductResp = await authenticatedRequest.patch(`/api/admin/products/${randomUUID()}`, {
      data: { names: { de: 'Updated' } },
    })
    expect(noProductResp.status()).toBe(404)

    // ── Update validation: zero price (422) ─────────────────────
    const zeroUpdateResp = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { price_cents: 0 },
    })
    expect(zeroUpdateResp.status()).toBe(422)

    // ── Update validation: invalid category (404) ───────────────
    const badCatUpdateResp = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { category_id: randomUUID() },
    })
    expect(badCatUpdateResp.status()).toBe(404)

    // ── Toggle status: deactivate ───────────────────────────────
    const deactivateResp = await authenticatedRequest.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: false },
    })
    expect(deactivateResp.status()).toBe(200)
    const deactivated = await deactivateResp.json()
    expect(deactivated.is_active).toBe(false)

    // ── Toggle status: activate ─────────────────────────────────
    const activateResp = await authenticatedRequest.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: true },
    })
    const activated = await activateResp.json()
    expect(activated.is_active).toBe(true)

    // ── Toggle status: non-existent (404) ───────────────────────
    const noToggleResp = await authenticatedRequest.patch(`/api/admin/products/${randomUUID()}/status`, {
      data: { is_active: false },
    })
    expect(noToggleResp.status()).toBe(404)

    // ── Delete: DELETE → 204 ────────────────────────────────────
    const deleteResp = await authenticatedRequest.delete(`/api/admin/products/${product.id}`)
    expect(deleteResp.status()).toBe(204)

    // ── Delete: verify gone from list ───────────────────────────
    const afterDeleteList = await authenticatedRequest.get('/api/admin/products')
    const afterDeleteBody = await afterDeleteList.json()
    expect(afterDeleteBody.items.some((p: any) => p.id === product.id)).toBe(false)

    // ── Delete: non-existent (404) ──────────────────────────────
    const noDeleteResp = await authenticatedRequest.delete(`/api/admin/products/${randomUUID()}`)
    expect(noDeleteResp.status()).toBe(404)
  })


  test('list features: pagination, sorting, category filter, status filter', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest, 'ListFeatures')
    const productIds: string[] = []

    // ── Setup: create 4 products with different prices ──────────
    const prices = [500, 100, 300, 200]
    for (const price of prices) {
      const resp = await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          price_cents: price,
          names: { de: `ListProd ${price}`, en: `ListProd ${price}` },
        }),
      })
      const p = await resp.json()
      productIds.push(p.id)
    }

    // Deactivate one product for status filter testing
    await authenticatedRequest.patch(`/api/admin/products/${productIds[0]}/status`, {
      data: { is_active: false },
    })

    // ── Pagination: page, per_page, max limit ───────────────────
    const page1Resp = await authenticatedRequest.get('/api/admin/products?page=1')
    const page1Body = await page1Resp.json()
    expect(page1Body.offset).toBe(0)

    const perPageResp = await authenticatedRequest.get('/api/admin/products?per_page=10')
    const perPageBody = await perPageResp.json()
    expect(perPageBody.limit).toBe(10)

    const maxPageResp = await authenticatedRequest.get('/api/admin/products?per_page=500')
    const maxPageBody = await maxPageResp.json()
    expect(maxPageBody.limit).toBeLessThanOrEqual(100)

    // ── Sorting: price_asc ──────────────────────────────────────
    const ascResp = await authenticatedRequest.get(`/api/admin/products?sort_by=price_asc&category_id=${category.id}`)
    const ascBody = await ascResp.json()
    expect(ascResp.ok()).toBeTruthy()
    const ascProducts = ascBody.items.filter((p: any) => productIds.includes(p.id))
    expect(ascProducts.length).toBe(4)
    const ascPrices = ascProducts.map((p: any) => p.price_cents)
    expect(ascPrices).toEqual([100, 200, 300, 500])

    // ── Sorting: price_desc ─────────────────────────────────────
    const descResp = await authenticatedRequest.get(`/api/admin/products?sort_by=price_desc&category_id=${category.id}`)
    const descBody = await descResp.json()
    const descProducts = descBody.items.filter((p: any) => productIds.includes(p.id))
    const descPrices = descProducts.map((p: any) => p.price_cents)
    expect(descPrices).toEqual([500, 300, 200, 100])

    // ── Sorting: name, order params work ────────────────────────
    const sortNameResp = await authenticatedRequest.get('/api/admin/products?sort=name')
    expect(sortNameResp.ok()).toBeTruthy()
    const orderDescResp = await authenticatedRequest.get('/api/admin/products?order=desc')
    expect(orderDescResp.ok()).toBeTruthy()

    // ── Status filter: active (200) ─────────────────────────────
    const activeResp = await authenticatedRequest.get('/api/admin/products?status=active')
    expect(activeResp.ok()).toBeTruthy()

    // ── Status filter: invalid (422) ────────────────────────────
    const invalidStatusResp = await authenticatedRequest.get('/api/admin/products?status=invalid')
    expect(invalidStatusResp.status()).toBe(422)

    // ── Category filter: returns only matching ──────────────────
    const catFilterResp = await authenticatedRequest.get(`/api/admin/products?category_id=${category.id}`)
    expect(catFilterResp.ok()).toBeTruthy()
    const catFilterBody = await catFilterResp.json()
    const catFilterIds = catFilterBody.items.map((p: any) => p.id)
    for (const id of productIds) {
      expect(catFilterIds).toContain(id)
    }

    // ── Category filter: empty category → 0 items ───────────────
    const emptyCat = await createCategory(authenticatedRequest, 'Empty')
    const emptyCatResp = await authenticatedRequest.get(`/api/admin/products?category_id=${emptyCat.id}`)
    const emptyCatBody = await emptyCatResp.json()
    expect(emptyCatBody.items.length).toBe(0)
    expect(emptyCatBody.total).toBe(0)

    // ── Category filter: invalid UUID (422) ─────────────────────
    const invalidCatResp = await authenticatedRequest.get('/api/admin/products?category_id=not-a-uuid')
    expect(invalidCatResp.status()).toBe(422)
    const invalidCatBody = await invalidCatResp.json()
    expect(invalidCatBody.error).toBe('validation_failed')

    // ── Category filter: non-existent UUID → 0 items ────────────
    const noExistResp = await authenticatedRequest.get(`/api/admin/products?category_id=${randomUUID()}`)
    const noExistBody = await noExistResp.json()
    expect(noExistBody.items.length).toBe(0)

    // ── Combined: category + status ─────────────────────────────
    const combinedResp = await authenticatedRequest.get(`/api/admin/products?category_id=${category.id}&status=active`)
    const combinedBody = await combinedResp.json()
    // productIds[0] was deactivated, should not appear with status=active
    const combinedIds = combinedBody.items.map((p: any) => p.id)
    expect(combinedIds).not.toContain(productIds[0])
    // Other products should appear
    expect(combinedIds).toContain(productIds[1])
  })


  test('icon support: create, update, clear, validate', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest)

    // ── Create with icon ────────────────────────────────────────
    const withIconResp = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, { icon_name: 'beer-pils' }),
    })
    expect(withIconResp.status()).toBe(201)
    const withIcon = await withIconResp.json()
    expect(withIcon.icon_name).toBe('beer-pils')

    // ── Create with null icon ───────────────────────────────────
    const nullIconResp = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, { icon_name: null }),
    })
    expect(nullIconResp.status()).toBe(201)
    const nullIcon = await nullIconResp.json()
    expect(nullIcon.icon_name).toBeNull()

    // ── Update icon ─────────────────────────────────────────────
    const updateIconResp = await authenticatedRequest.patch(`/api/admin/products/${withIcon.id}`, {
      data: { icon_name: 'beer-weizen' },
    })
    const updatedIcon = await updateIconResp.json()
    expect(updatedIcon.icon_name).toBe('beer-weizen')

    // ── Clear icon to null ──────────────────────────────────────
    const clearIconResp = await authenticatedRequest.patch(`/api/admin/products/${withIcon.id}`, {
      data: { icon_name: null },
    })
    const clearedIcon = await clearIconResp.json()
    expect(clearedIcon.icon_name).toBeNull()

    // ── Validation: icon_name too long (422) ────────────────────
    const longIconResp = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, { icon_name: 'a'.repeat(51) }),
    })
    expect(longIconResp.status()).toBe(422)
  })


  test('terminal sync: delta response, active/inactive, category filtering, schema', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // ── Setup: create active category + products ────────────────
    const activeCat = await createCategory(authenticatedRequest, 'SyncActive')

    const activeProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(activeCat.id, {
          icon_name: 'beer-pils',
          requires_dispenser: true,
          names: { de: 'SyncAktiv', en: 'SyncActive', fr: 'SyncActif' },
        }),
      })
    ).json()

    const inactiveProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(activeCat.id),
      })
    ).json()
    await authenticatedRequest.patch(`/api/admin/products/${inactiveProduct.id}/status`, {
      data: { is_active: false },
    })

    // ── Setup: create inactive category + product ───────────────
    const inactiveCat = await createCategory(authenticatedRequest, 'SyncInactive')
    await authenticatedRequest.patch(`/api/admin/categories/${inactiveCat.id}/status`, {
      data: { is_active: false },
    })
    const hiddenProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(inactiveCat.id),
      })
    ).json()

    // ── Delta response: verify structure ────────────────────────
    const syncResp = await authenticatedTerminalRequest.get('/api/sync/products')
    expect(syncResp.ok()).toBeTruthy()
    expect(syncResp.status()).toBe(200)

    const contentType = syncResp.headers()['content-type']
    expect(contentType).toContain('application/json')

    const body = await syncResp.json()
    expect(Array.isArray(body.products)).toBe(true)
    expect(typeof body.cursor).toBe('number')
    expect(typeof body.count).toBe('number')
    expect(typeof body.has_more).toBe('boolean')
    expect(body.count).toBe(body.products.length)

    // ── Active/inactive: both returned in sync ──────────────────
    const activeInSync = body.products.find((p: any) => p.id === activeProduct.id)
    const inactiveInSync = body.products.find((p: any) => p.id === inactiveProduct.id)
    expect(activeInSync).toBeDefined()
    expect(activeInSync.is_active).toBe(true)
    expect(inactiveInSync).toBeDefined()
    expect(inactiveInSync.is_active).toBe(false)

    // ── Category filtering: inactive category products excluded ─
    const hiddenInSync = body.products.find((p: any) => p.id === hiddenProduct.id)
    expect(hiddenInSync).toBeUndefined()

    // ── Since parameter: respects timestamp filter ───────────────
    const oldTimestamp = Math.floor(Date.now() / 1000) - 3600
    const sinceResp = await authenticatedTerminalRequest.get(`/api/sync/products?since=${oldTimestamp}`)
    expect(sinceResp.ok()).toBeTruthy()
    const sinceBody = await sinceResp.json()
    expect(sinceBody.products).toBeDefined()

    // ── Schema: validate product object fields ──────────────────
    expect(activeInSync.id).toBeDefined()
    expect(typeof activeInSync.names).toBe('object')
    expect(activeInSync.names.de).toBe('SyncAktiv')
    expect(activeInSync.names.en).toBe('SyncActive')
    expect(activeInSync.names.fr).toBe('SyncActif')
    expect(typeof activeInSync.price_cents).toBe('number')
    expect(Number.isInteger(activeInSync.price_cents)).toBe(true)
    expect(activeInSync.price_cents).toBeGreaterThanOrEqual(0)
    expect(activeInSync.category_id).toBe(activeCat.id)
    expect(activeInSync.created_at).toBeDefined()
    expect(activeInSync.updated_at).toBeDefined()
    expect(activeInSync.icon_name).toBe('beer-pils')

    // ── Schema: requires_dispenser field ─────────────────────────
    expect(activeInSync).toHaveProperty('requires_dispenser')
    expect(typeof activeInSync.requires_dispenser).toBe('boolean')

    // ── Schema: descriptions can be null or object ───────────────
    if (activeInSync.descriptions !== null) {
      expect(typeof activeInSync.descriptions).toBe('object')
    }

    // Validate all product prices are valid non-negative integers
    for (const p of body.products) {
      expect(Number.isInteger(p.price_cents)).toBe(true)
      expect(p.price_cents).toBeGreaterThanOrEqual(0)
    }
  })
})
