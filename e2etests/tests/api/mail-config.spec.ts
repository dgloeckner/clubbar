import { test } from '../../fixtures/auth.fixture'
import { expect } from '@playwright/test'

/**
 * E2E Test: Mail Settings API (ADR-0038)
 *
 * `mail_config` is a singleton, so these tests mutate one shared row rather
 * than creating their own data — Pattern 001 cannot apply. Two things keep
 * that safe: the file is `serial`, so its own tests cannot race each other,
 * and it restores the row afterwards, so it cannot leak into anything that
 * reads mail settings later.
 *
 * The endpoint's defining property is a negative one: the SMTP DSN is a secret
 * in `config.php` and must never appear here, in either direction. Most of what
 * follows is checking that boundary holds.
 */
test.describe.serial('Mail Settings API', () => {
  const API_BASE = 'http://localhost:8080/api'
  const URL = `${API_BASE}/admin/mail-config`

  let original: Record<string, unknown> | null = null

  test.beforeAll(async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(URL)
    expect(response.ok()).toBeTruthy()
    original = await response.json()
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    if (!original) return
    await authenticatedRequest.patch(URL, {
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
        statement_cadence: original.statement_cadence,
      },
    })
  })

  test('GET returns the club-editable fields and the transport state', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(URL)
    expect(response.ok()).toBeTruthy()

    const data = await response.json()
    expect(data).toHaveProperty('sender_address')
    expect(data).toHaveProperty('header_style')
    expect(data).toHaveProperty('is_complete')
    expect(data).toHaveProperty('can_send')

    // The transport half is reported, never edited: the DSN lives in
    // config.php next to the database password.
    expect(data.transport).toBeDefined()
    expect(typeof data.transport.configured).toBe('boolean')
    expect(typeof data.transport.valid).toBe('boolean')
  })

  test('GET never exposes the DSN', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(URL)
    const body = await response.text()

    // Naming the setting is fine and useful — "mail.dsn unset" is how the
    // status tells an admin what to go and configure. What must never appear
    // is a DSN *value*, credentials and all.
    expect(body).not.toMatch(/(smtps?|sendmail|native):\/\//)
    const data = JSON.parse(body)
    expect(data).not.toHaveProperty('dsn')
    expect(data.transport).not.toHaveProperty('dsn')
  })

  test('PATCH persists the club fields', async ({ authenticatedRequest }) => {
    const update = {
      sender_name: 'Testverein Bar',
      sender_address: 'bar@test.example.org',
      reply_to_address: 'kassenwart@test.example.org',
      header_style: 'paper',
      footer_org_name: 'Testverein e. V.',
      footer_address_line: 'Musterweg 35 · 60599 Frankfurt am Main',
    }

    const patch = await authenticatedRequest.patch(URL, { data: update })
    expect(patch.ok()).toBeTruthy()

    const patched = await patch.json()
    expect(patched.sender_address).toBe(update.sender_address)
    expect(patched.is_complete).toBe(true)

    // Re-read rather than trust the write's own response: this is the
    // assertion that proves the row was written, not just echoed.
    const reread = await authenticatedRequest.get(URL)
    const data = await reread.json()
    expect(data.sender_name).toBe(update.sender_name)
    expect(data.reply_to_address).toBe(update.reply_to_address)
    expect(data.footer_org_name).toBe(update.footer_org_name)
  })

  test('PATCH rejects an invalid sender address', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(URL, {
      data: { sender_address: 'not-an-address' },
    })

    expect(response.status()).toBe(422)
    const body = await response.json()
    expect(body.error).toBe('validation_failed')
    expect(body.messages).toHaveProperty('sender_address')
  })

  test('PATCH rejects an unknown header variant', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(URL, {
      data: { header_style: 'neon' },
    })

    expect(response.status()).toBe(422)
    const body = await response.json()
    expect(body.messages).toHaveProperty('header_style')
  })

  test('PATCH stores the declared scheduler interval and batch size', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.patch(URL, {
      data: { cron_interval: 'daily', drain_batch_size: 40 },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()
    expect(body.cron_interval).toBe('daily')
    expect(body.drain_batch_size).toBe(40)

    // Read back through a fresh request: the response could be echoing the
    // payload rather than the row.
    const reread = await (await authenticatedRequest.get(URL)).json()
    expect(reread.cron_interval).toBe('daily')
    expect(reread.drain_batch_size).toBe(40)
  })

  test('PATCH refuses a weekly scheduler and says why', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(URL, { data: { cron_interval: 'weekly' } })

    expect(response.status()).toBe(422)

    // The one rejected value somebody will deliberately try, because it is what
    // their tariff offers. A generic "must be one of" would read as an arbitrary
    // limitation rather than as the club's own announcement promise failing
    // (ADR-0039 decision 5).
    const body = await response.json()
    expect(body.messages.cron_interval).toContain('§ 7 Abs. 3')
  })

  test('PATCH stores the Deckelauszug cadence', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(URL, {
      data: { statement_cadence: 'quarterly' },
    })

    expect(response.status()).toBe(200)
    expect((await response.json()).statement_cadence).toBe('quarterly')

    // Read back through a fresh request: the response could be echoing the
    // payload rather than the row.
    const reread = await (await authenticatedRequest.get(URL)).json()
    expect(reread.statement_cadence).toBe('quarterly')

    // And `off` is a real value rather than a way of clearing the field —
    // it is the club's only control over this feature (ADR-0039 decision 3).
    const off = await authenticatedRequest.patch(URL, { data: { statement_cadence: 'off' } })
    expect((await off.json()).statement_cadence).toBe('off')
  })

  test('PATCH refuses a weekly statement cadence', async ({ authenticatedRequest }) => {
    // Refused for a gentler reason than a weekly *scheduler* is: nothing breaks,
    // it simply stops being predictable and starts being relentless. So a plain
    // enum rejection is the right answer here, unlike for `cron_interval`.
    const response = await authenticatedRequest.patch(URL, {
      data: { statement_cadence: 'weekly' },
    })

    expect(response.status()).toBe(422)
    expect(await response.json()).toHaveProperty('messages.statement_cadence')
  })

  test('requires an authenticated session', async ({ request }) => {
    const response = await request.get(URL)
    expect(response.status()).toBe(401)
  })
})
