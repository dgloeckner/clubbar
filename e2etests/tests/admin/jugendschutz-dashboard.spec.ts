import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * The dashboard says a minor was served, and lets somebody clear it
 * (#622, ADR-0045 §3).
 *
 * The API spec beside this one proves the endpoints. This proves the half only
 * a browser can: that the alert renders, that the button reaches the API, and
 * that the row leaves the screen afterwards — the loop a Kassenwart actually
 * performs.
 *
 * The negative assertion is the one worth keeping. This panel renders on a
 * screen that may be open in the clubroom, so it names the drink's required age
 * and the transaction, never the member (rule 6).
 */

const API_BASE = 'http://localhost:8080/api'

test.describe('Jugendschutz on the dashboard', () => {
  test('an unacknowledged violation is shown and can be cleared', async ({
    page,
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const suffix = randomUUID().replace(/-/g, '').slice(0, 10)
    const dateOfBirth = (() => {
      const d = new Date()
      d.setUTCFullYear(d.getUTCFullYear() - 15)
      return d.toISOString().slice(0, 10)
    })()

    const memberResponse = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `Dash${suffix}`,
        last_name: `Schutz${suffix}`,
        email: `dash-jugend-${suffix}@example.com`,
        date_of_birth: dateOfBirth,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(memberResponse.status(), await memberResponse.text()).toBe(201)
    const member = await memberResponse.json()

    const category = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${suffix}`, en: `Cat${suffix}` } },
    })
    const productResponse = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Korn ${suffix}`, en: `Spirit ${suffix}` },
        category_id: (await category.json()).id,
        price_cents: 350,
        min_age: 18,
      },
    })
    const product = await productResponse.json()

    const transactionId = randomUUID()
    const sale = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, {
      data: {
        transactions: [
          {
            id: transactionId,
            member_id: member.id,
            product_id: product.id,
            amount_cents: 350,
            quantity: 1,
            created_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
          },
        ],
      },
    })
    expect(sale.status(), await sale.text()).toBe(201)

    await page.goto('/dashboard')

    const panel = page.getByTestId('dashboard-jugendschutz-warning')
    await expect(panel).toBeVisible()
    await expect(panel).toHaveAttribute('data-severity', 'error')

    const row = page.locator(`[data-testid="dashboard-jugendschutz-item"][data-transaction-id="${transactionId}"]`)
    await expect(row).toBeVisible()
    // The drink's requirement and the sale, never the person.
    await expect(row).toContainText('18')
    await expect(panel).not.toContainText(member.first_name)
    await expect(panel).not.toContainText(member.last_name)

    await row.getByTestId('dashboard-jugendschutz-acknowledge').click()

    // Gone from the asking...
    await expect(row).toBeHidden()

    // ...and still on the record, which is invariant 4. The panel is a view of
    // what is outstanding, not of what happened.
    const audit = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?action=jugendschutz_violation&entity_id=${transactionId}`,
    )
    expect((await audit.json()).data).toHaveLength(1)
  })
})
