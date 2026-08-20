import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * A product carries a minimum age (epic #582 M3, ADR-0045).
 *
 * This is the product half of the Jugendschutz check — the number a
 * Getränkewart sets on a drink, which the terminal compares against the
 * member's age at checkout, offline, from its own cache.
 *
 * The properties that matter, and why each is asserted rather than assumed:
 *
 * - **NULL means unrestricted, not unknown.** Most of a drinks list carries no
 *   legal age; making that an assertion the Getränkewart has to type would be
 *   friction with no safety gain, and a forgotten field would block the sale of
 *   an Apfelschorle.
 * - **The bound is 1–99.** Not a `{16, 18}` enum: JuSchG's thresholds are
 *   German law and this software is self-hosted. What the range rules out is
 *   nonsense — a 0 that reads as "everyone" in the field that restricts.
 * - **It can be cleared.** Removing a restriction is the one write here that
 *   makes a product *more* available, so it has to be explicit and it has to
 *   actually reach the row.
 *
 * E2E Pattern 001: every test mints its own category and product.
 */

const API_BASE = 'http://localhost:8080/api'

test.describe('Product minimum age', () => {
  /** A fresh category, so a product created here collides with nothing. */
  const createCategory = async (request: { post: Function }) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const response = await (request as any).post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${id}`, en: `Cat${id}` } },
    })
    expect(response.status(), await response.text()).toBe(201)

    return (await response.json()).id
  }

  /**
   * Re-read one product from the server.
   *
   * There is no `GET /admin/products/{id}` — the panel only ever lists — so
   * persistence is confirmed through the list, narrowed to the category this
   * test minted so no other worker's rows are in the page (Pattern 003).
   */
  const reload = async (request: any, categoryId: string, productId: string) => {
    const list = await request.get(`${API_BASE}/admin/products`, {
      params: { category_id: categoryId, per_page: 50 },
    })
    expect(list.status(), await list.text()).toBe(200)

    const row = (await list.json()).data.find((r: { id: string }) => r.id === productId)
    expect(row, 'the product must be in its own category page').toBeTruthy()

    return row
  }

  const productBody = (categoryId: string, overrides: Record<string, unknown> = {}) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)

    return {
      names: { de: `Getränk ${id}`, en: `Drink ${id}` },
      category_id: categoryId,
      price_cents: 220,
      ...overrides,
    }
  }

  test('is stored on create and read back on the product', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)

    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { min_age: 18 }),
    })

    expect(created.status(), await created.text()).toBe(201)
    const product = await created.json()
    expect(product.min_age).toBe(18)

    expect((await reload(authenticatedRequest, categoryId, product.id)).min_age).toBe(18)
  })

  test('is optional — an unrestricted product needs no age at all', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)

    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId),
    })

    expect(created.status(), await created.text()).toBe(201)
    expect((await created.json()).min_age).toBeNull()
  })

  for (const [label, value] of [
    ['zero', 0],
    ['negative', -1],
    ['past the top of the range', 100],
    ['a fraction', 17.5],
    ['prose', 'eighteen'],
  ] as const) {
    test(`rejects ${label} on create`, async ({ authenticatedRequest }) => {
      const categoryId = await createCategory(authenticatedRequest)

      const response = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
        data: productBody(categoryId, { min_age: value }),
      })

      expect(response.status()).toBe(422)
      const error = await response.json()
      expect(error.error).toBe('validation_failed')
      expect(error.messages).toHaveProperty('min_age')
    })
  }

  test('accepts both JuSchG thresholds and both ends of the range', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)

    for (const age of [1, 16, 18, 99]) {
      const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
        data: productBody(categoryId, { min_age: age }),
      })

      expect(created.status(), await created.text()).toBe(201)
      expect((await created.json()).min_age).toBe(age)
    }
  })

  test('can be set on a product that had none', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId),
    })
    const product = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { min_age: 16 },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).min_age).toBe(16)
  })

  /**
   * Un-restricting a drink is the write that makes it available to more people,
   * so it must be an explicit null and it must persist. A rule set that read
   * the null as "field absent" would skip the bounds check on exactly this
   * path.
   */
  test('can be cleared with an explicit null', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { min_age: 18 }),
    })
    const product = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { min_age: null },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).min_age).toBeNull()

    expect((await reload(authenticatedRequest, categoryId, product.id)).min_age).toBeNull()
  })

  test('rejects an out-of-range age on update, leaving the stored one alone', async ({
    authenticatedRequest,
  }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { min_age: 18 }),
    })
    const product = await created.json()

    const rejected = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { min_age: 0 },
    })
    expect(rejected.status()).toBe(422)
    expect((await rejected.json()).messages).toHaveProperty('min_age')

    expect((await reload(authenticatedRequest, categoryId, product.id)).min_age).toBe(18)
  })

  test('a PATCH of the price says nothing about the age', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { min_age: 16 }),
    })
    const product = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { price_cents: 250 },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).min_age).toBe(16)
  })

  /**
   * Unlike a member's birth date, `min_age` belongs on the list: it is a
   * property of a drink rather than of a person, the Getränkewart needs to see
   * at a glance which products are restricted, and it says nothing about
   * anybody (ADR-0045 rule 5).
   */
  test('is present on the product list', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { min_age: 18 }),
    })
    const product = await created.json()

    expect((await reload(authenticatedRequest, categoryId, product.id)).min_age).toBe(18)
  })
})
