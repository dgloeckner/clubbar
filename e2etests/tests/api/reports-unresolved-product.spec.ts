import { randomUUID } from 'crypto'
import type { APIRequestContext } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * A sale whose product no longer resolves, in the per-product report.
 *
 * ADR-0033 §1 says `product_id` is **not** checked for existence: the terminal
 * sold against a cached catalogue, and by sync time the drink is in the
 * member's hand, so refusing the row would lose the record of a sale that
 * happened rather than undo it. The report therefore has to render sales it
 * cannot name — and it used to render them badly, in two ways at once:
 *
 * - every such sale, whatever product it named, was folded into one group,
 *   because the query grouped on the *join's* `p.id` rather than on the
 *   transaction's own `t.product_id`. The result was a fabricated row summing
 *   unrelated products, which — being the sum of many — sorted to the top of
 *   the chart;
 * - the group was labelled with the English literal `Unknown`, which a German
 *   admin panel printed verbatim between its own product names.
 *
 * This is the end-to-end proof of both: a terminal uploads the sales, the admin
 * API reports them. Isolation comes from `product_ids` (E2E Pattern 001 and
 * 004): the filter matches on `t.product_id`, so it selects exactly the rows
 * this test created and nothing a parallel worker is doing.
 */

const API_BASE = 'http://localhost:8080/api'

interface ReportRow {
  dimension: string | null
  dimension_id: string
  revenue_cents: number
  count: number
}

async function createMember(authenticatedRequest: APIRequestContext) {
  const uniqueId = randomUUID().substring(0, 8)

  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `Report${uniqueId}`,
      last_name: `Reader${uniqueId}`,
      email: `report-${uniqueId}@example.com`,
      date_of_birth: '1985-06-15',
      iban: 'DE89370400440532013000',
      mandate_signed_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      preferred_language: 'de',
    },
  })

  expect(response.ok(), await response.text()).toBeTruthy()
  return await response.json()
}

async function createProduct(authenticatedRequest: APIRequestContext, germanName: string) {
  const category = await authenticatedRequest.post('/api/admin/categories', {
    data: { names: { de: `Kategorie ${randomUUID().substring(0, 8)}`, en: 'Category' } },
  })
  expect(category.ok(), await category.text()).toBeTruthy()

  const response = await authenticatedRequest.post('/api/admin/products', {
    data: {
      names: { de: germanName, en: germanName },
      price_cents: 350,
      category_id: (await category.json()).id,
    },
  })
  expect(response.ok(), await response.text()).toBeTruthy()
  return await response.json()
}

test.describe('Reports: sales whose product no longer resolves', () => {
  test('names what it can, keeps apart what it cannot, and invents no label', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest)
    const germanName = `Helles ${randomUUID().substring(0, 8)}`
    const known = await createProduct(authenticatedRequest, germanName)

    // Two ids the catalogue has never carried — the shape of a terminal that
    // synced after the product was purged, or from a rebuilt database.
    const gone = randomUUID()
    const alsoGone = randomUUID()

    const sale = (productId: string, amountCents: number) => ({
      id: randomUUID(),
      member_id: member.id,
      product_id: productId,
      amount_cents: amountCents,
      created_at: new Date().toISOString(),
    })

    const upload = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [sale(known.id, 350), sale(gone, 500), sale(alsoGone, 300)],
      },
    })

    // Stored, not refused: the sales happened (ADR-0033 §1, ruling #143).
    expect(upload.status(), await upload.text()).toBe(201)
    expect((await upload.json()).rejected.count).toBe(0)

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=product&product_ids=${known.id},${gone},${alsoGone}`,
    )
    expect(response.status()).toBe(200)
    const rows: ReportRow[] = (await response.json()).data

    const rowFor = (productId: string) =>
      rows.find((row) => row.dimension_id === productId)

    // Three sales naming three products are three rows. Before the fix the two
    // unresolved ones merged into a single `Unknown` row.
    expect(rows).toHaveLength(3)

    expect(rowFor(known.id)).toMatchObject({
      dimension: germanName,
      revenue_cents: 350,
      count: 1,
    })

    expect(rowFor(gone)).toMatchObject({
      dimension: null,
      revenue_cents: 500,
      count: 1,
    })
    expect(rowFor(alsoGone)).toMatchObject({
      dimension: null,
      revenue_cents: 300,
      count: 1,
    })

    // The API names nothing it cannot name — no 'Unknown', no placeholder of
    // any other language either. Labelling is the panel's job.
    expect(rows.map((row) => row.dimension)).not.toContain('Unknown')
  })

  test('repeat sales of the same missing product are one row, not several', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest)
    const gone = randomUUID()

    const upload = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [200, 200, 150].map((amountCents) => ({
          id: randomUUID(),
          member_id: member.id,
          product_id: gone,
          amount_cents: amountCents,
          created_at: new Date().toISOString(),
        })),
      },
    })
    expect(upload.status(), await upload.text()).toBe(201)

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/consumption?group_by=product&product_ids=${gone}`,
    )
    expect(response.status()).toBe(200)
    const rows = (await response.json()).data

    expect(rows).toHaveLength(1)
    expect(rows[0]).toMatchObject({
      dimension: null,
      dimension_id: gone,
      revenue_cents: 550,
      count: 3,
    })
  })
})
