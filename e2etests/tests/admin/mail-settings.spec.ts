/**
 * Mail settings in the admin panel (#407, ADR-0038).
 *
 * Two things worth pinning here, and neither is "the form fills in":
 *
 * 1. **A saved value survives a reload**, read back from the API rather than
 *    from the form's own state. `mail_config` is a singleton, so this suite is
 *    serial and restores what it found (the singleton form of Pattern 001).
 * 2. **`weekly` is refused with its reasoning**, next to the field. It is the
 *    one rejected value somebody will deliberately try — it is what their
 *    hosting tariff offers — and a generic "invalid value" would read as an
 *    arbitrary limitation rather than as the club's own § 7 Abs. 3 promise
 *    failing (ADR-0039 decision 5).
 *
 * The SMTP DSN is deliberately absent from the form, and asserted absent: it
 * carries a password and lives in `config.php` (ADR-0031 decision 2).
 */

import { test, expect } from '../../fixtures/auth.fixture'

const API = '/api/admin/mail-config'

test.describe.serial('Mail settings', () => {
  let original: Record<string, unknown> | null = null

  test.beforeAll(async ({ authenticatedRequest }) => {
    original = await (await authenticatedRequest.get(API)).json()
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    if (!original) return
    await authenticatedRequest.patch(API, {
      data: {
        sender_name: original.sender_name,
        sender_address: original.sender_address,
        reply_to_address: original.reply_to_address,
        header_style: original.header_style,
        footer_org_name: original.footer_org_name,
        footer_address_line: original.footer_address_line,
        website_url: original.website_url,
        logo_url: original.logo_url,
        cron_interval: original.cron_interval,
        drain_batch_size: original.drain_batch_size,
      },
    })
  })

  async function openMailTab(page: import('@playwright/test').Page) {
    const loaded = page.waitForResponse((r) => r.url().includes(API) && r.request().method() === 'GET')
    await page.goto('/settings')
    await page.getByTestId('settings-tab-mail').click()
    await loaded
    await expect(page.getByTestId('settings-mail-tab')).toBeVisible()
  }

  test('the form saves and the value survives a reload', async ({ page, authenticatedRequest }) => {
    await openMailTab(page)

    const replyTo = `kassenwart-${Date.now()}@test.example`
    await page.getByTestId('settings-mail-reply_to_address').fill(replyTo)
    await page.getByTestId('settings-mail-cron_interval').selectOption('daily')

    const saved = page.waitForResponse(
      (r) => r.url().includes(API) && r.request().method() === 'PATCH' && r.status() === 200
    )
    await page.getByTestId('settings-mail-save').click()
    await saved
    await expect(page.getByTestId('settings-mail-success')).toBeVisible()

    // The row, not the form: a page that echoed its own state would pass an
    // assertion made against itself.
    const stored = await (await authenticatedRequest.get(API)).json()
    expect(stored.reply_to_address).toBe(replyTo)
    expect(stored.cron_interval).toBe('daily')

    await openMailTab(page)
    await expect(page.getByTestId('settings-mail-reply_to_address')).toHaveValue(replyTo)
    await expect(page.getByTestId('settings-mail-cron_interval')).toHaveValue('daily')
  })

  test('the interval dropdown offers no weekly option at all', async ({ page }) => {
    await openMailTab(page)

    // Refused at the API with a paragraph of reasoning, and not offered here in
    // the first place — an admin should not be able to pick a granularity at
    // which the announcement promise stops holding.
    const options = await page.getByTestId('settings-mail-cron_interval').locator('option').allTextContents()
    expect(options).toHaveLength(2)
    expect(options.join(' ').toLowerCase()).not.toContain('woch')
    expect(options.join(' ').toLowerCase()).not.toContain('week')
  })

  test('the API refuses weekly by naming the promise it would break', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.patch(API, { data: { cron_interval: 'weekly' } })

    expect(response.status()).toBe(422)
    expect((await response.json()).messages.cron_interval).toContain('§ 7 Abs. 3')
  })

  test('the transport is shown as measured state, never as an editable field', async ({ page }) => {
    await openMailTab(page)

    await expect(page.getByTestId('settings-mail-transport-state')).toBeVisible()

    // No input for the DSN, and no DSN value anywhere on the page: it carries a
    // password and lives in config.php.
    await expect(page.locator('[data-testid="settings-mail-dsn"]')).toHaveCount(0)
    await expect(page.getByTestId('settings-mail-tab')).not.toContainText('smtp://')
  })

  /**
   * The button exists and answers. On this stack there is no transport, so the
   * answer is a refusal — which is exactly the case it is for: the admin finds
   * out from the panel rather than from a settlement that announced nothing.
   */
  test('the test-mail button reports what the transport answered', async ({ page }) => {
    await openMailTab(page)

    const sent = page.waitForResponse(
      (r) => r.url().includes('/api/admin/mail-config/test-mail') && r.request().method() === 'POST'
    )
    await page.getByTestId('settings-mail-test-button').click()
    const response = await sent
    expect(response.status()).toBe(200)

    const result = page.getByTestId('settings-mail-test-result')
    await expect(result).toBeVisible()

    // Whichever way it went, the page must say which — a silent button is the
    // one outcome that helps nobody.
    const outcome = await result.getAttribute('data-sent')
    expect(['true', 'false']).toContain(outcome)
    if (outcome === 'false') {
      await expect(result).not.toBeEmpty()
    }
  })
})
