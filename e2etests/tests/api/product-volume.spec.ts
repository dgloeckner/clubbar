import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * A product carries a volume (#878 M2, ADR-0056).
 *
 * The size used to live inside the name — `Weizenbier (0,5l)`, `Pils 0,5L`,
 * `Bier 0,5 l`, five spellings in the test data alone. `volume_ml` gives it a
 * column: whole millilitres, language-neutral, formatted for each reader at the
 * edge.
 *
 * The properties asserted here, and why each is asserted rather than assumed:
 *
 * - **NULL means no size, not zero.** A Sauna-Token and a Kaffee have no
 *   volume, and that is the ordinary state of a snacks list — making it a
 *   number the Getränkewart has to type would be friction with nothing behind
 *   it.
 * - **The bound is 1–10 000.** A typo guard, not a business rule: 0 would print
 *   as a size while meaning none, and anything above ten litres is litres
 *   entered where millilitres were asked for.
 * - **It can be cleared.** An explicit null must reach the row — a rule set
 *   that read the null as "field absent" would skip its own bounds check on
 *   exactly that path, and `updateById`'s allowlist silently drops any column
 *   missing from it.
 * - **It reaches the terminal.** The whole point is the card redesign, and the
 *   card cannot draw a badge for a field the delta sync does not carry.
 *
 * E2E Pattern 001: every test mints its own category and product.
 */

const API_BASE = 'http://localhost:8080/api'

test.describe('Product volume', () => {
  /** A fresh category, so a product created here collides with nothing. */
  const createCategory = async (request: any) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const response = await request.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${id}`, en: `Cat${id}` } },
    })
    expect(response.status(), await response.text()).toBe(201)

    return (await response.json()).id
  }

  /**
   * Re-read one product from the server. There is no `GET
   * /admin/products/{id}` — the panel only ever lists — so persistence is
   * confirmed through the list, narrowed to this test's own category so no
   * other worker's rows are in the page (Pattern 003).
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
      data: productBody(categoryId, { volume_ml: 500 }),
    })

    expect(created.status(), await created.text()).toBe(201)
    const product = await created.json()
    expect(product.volume_ml).toBe(500)

    expect((await reload(authenticatedRequest, categoryId, product.id)).volume_ml).toBe(500)
  })

  test('is optional — a product with no size needs no volume at all', async ({
    authenticatedRequest,
  }) => {
    const categoryId = await createCategory(authenticatedRequest)

    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId),
    })

    expect(created.status(), await created.text()).toBe(201)
    const product = await created.json()
    // Null rather than absent: a client has to be able to tell "this product
    // has no size" from "this backend does not know about sizes".
    expect(product).toHaveProperty('volume_ml')
    expect(product.volume_ml).toBeNull()
  })

  for (const [label, value] of [
    ['zero', 0],
    ['negative', -1],
    ['past the top of the range', 10001],
    ['a fraction', 0.5],
    ['a German decimal as prose', '0,5'],
    ['prose', 'half a litre'],
  ] as const) {
    test(`rejects ${label} on create`, async ({ authenticatedRequest }) => {
      const categoryId = await createCategory(authenticatedRequest)

      const response = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
        data: productBody(categoryId, { volume_ml: value }),
      })

      expect(response.status()).toBe(422)
      const error = await response.json()
      expect(error.error).toBe('validation_failed')
      expect(error.messages).toHaveProperty('volume_ml')
    })
  }

  test('accepts both ends of the range and the sizes a bar actually pours', async ({
    authenticatedRequest,
  }) => {
    const categoryId = await createCategory(authenticatedRequest)

    for (const ml of [1, 20, 200, 330, 500, 1000, 10000]) {
      const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
        data: productBody(categoryId, { volume_ml: ml }),
      })

      expect(created.status(), await created.text()).toBe(201)
      expect((await created.json()).volume_ml).toBe(ml)
    }
  })

  test('can be set on a product that had none', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId),
    })
    const product = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { volume_ml: 330 },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).volume_ml).toBe(330)

    expect((await reload(authenticatedRequest, categoryId, product.id)).volume_ml).toBe(330)
  })

  /**
   * Clearing is how an admin says the product has no size after all. It must be
   * an explicit null, it must survive the update allowlist, and it must reach
   * the row rather than returning a 200 over an unchanged product.
   */
  test('can be cleared with an explicit null', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { volume_ml: 500 }),
    })
    const product = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { volume_ml: null },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).volume_ml).toBeNull()

    expect((await reload(authenticatedRequest, categoryId, product.id)).volume_ml).toBeNull()
  })

  test('rejects an out-of-range volume on update, leaving the stored one alone', async ({
    authenticatedRequest,
  }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { volume_ml: 500 }),
    })
    const product = await created.json()

    const rejected = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { volume_ml: 10001 },
    })
    expect(rejected.status()).toBe(422)
    expect((await rejected.json()).messages).toHaveProperty('volume_ml')

    expect((await reload(authenticatedRequest, categoryId, product.id)).volume_ml).toBe(500)
  })

  test('a PATCH of the price says nothing about the volume', async ({ authenticatedRequest }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { volume_ml: 500 }),
    })
    const product = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { price_cents: 250 },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).volume_ml).toBe(500)
  })

  /**
   * The terminal is what this field is for. Its delta sync has to carry the
   * volume, and has to carry it on the edit that changed it — otherwise the
   * card draws yesterday's badge until some unrelated write touches the row.
   */
  test('reaches the terminal on the delta sync that changed it', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const categoryId = await createCategory(authenticatedRequest)
    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: productBody(categoryId, { volume_ml: 500 }),
    })
    const product = await created.json()

    // A cursor from before the edit, so the delta is guaranteed to include it.
    const before = Date.now() - 2000

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { volume_ml: 330 },
    })
    expect(patched.status(), await patched.text()).toBe(200)

    const sync = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products`, {
      params: { since: before },
    })
    expect(sync.status(), await sync.text()).toBe(200)

    const synced = (await sync.json()).products.find((p: { id: string }) => p.id === product.id)
    expect(synced, 'the edited product must be in the delta').toBeTruthy()
    expect(synced.volume_ml).toBe(330)
  })
})
