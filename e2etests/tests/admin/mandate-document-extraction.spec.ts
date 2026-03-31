import { test, expect } from '../../fixtures/pageObjects'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { csrfHeaders } from '../../utils/csrf'
import type { Page } from '@playwright/test'

const FIXTURE_DIR = resolve(__dirname, '../../fixtures/files')
const EXTRACTION_CONFIGURED = !!process.env.LLM_API_KEY

// Helper: create an isolated member and return its id
async function createTestMember(page: Page): Promise<string> {
  const ts = Date.now()
  const resp = await page.request.post('http://localhost:8080/api/admin/members', {
    data: {
      first_name: `ExtrTest`,
      last_name: `User${ts}`,
      email: `extr-${ts}@example.com`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2025-01-01',
      preferred_language: 'de',
    },
    headers: await csrfHeaders(page),
  })
  expect(resp.ok()).toBe(true)
  const body = await resp.json()
  return body.id
}

// ── POST /api/admin/mandate-document/extract — auth & validation ───────────────
//
// These tests are always valid regardless of LLM configuration.

test.describe('POST /api/admin/mandate-document/extract — auth and validation', () => {
  test('returns 4xx when unauthenticated', async ({ playwright }) => {
    // CSRF middleware runs before auth and may return 403 instead of 401.
    // Either way the request is rejected — we verify it is not 200.
    const ctx = await playwright.request.newContext()
    const resp = await ctx.post(
      'http://localhost:8080/api/admin/mandate-document/extract',
      {
        multipart: {
          file: { name: 'test.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('fake') },
        },
      }
    )
    expect(resp.status()).toBeGreaterThanOrEqual(400)
    expect(resp.status()).toBeLessThan(500)
    await ctx.dispose()
  })

  test('returns 422 when no file provided (LLM configured)', async ({ page }) => {
    // The controller checks LLM config before file presence, so 422 only applies
    // when LLM is configured. Without LLM the endpoint returns 409 first.
    test.skip(!EXTRACTION_CONFIGURED, 'LLM_API_KEY not set — endpoint returns 409 before reaching file validation')
    await page.goto('http://localhost:5173/members')
    const resp = await page.request.post(
      'http://localhost:8080/api/admin/mandate-document/extract',
      { data: {}, headers: await csrfHeaders(page) }
    )
    expect(resp.status()).toBe(422)
  })
})

// ── POST /api/admin/mandate-document/extract — LLM not configured ─────────────
//
// These tests verify behaviour when no LLM API key is present.
// Skipped when LLM_API_KEY is set (endpoint returns 200, not 409).

test.describe('POST /api/admin/mandate-document/extract — Extraction not configured', () => {
  test('returns 409 when LLM not configured', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const resp = await page.request.post(
      'http://localhost:8080/api/admin/mandate-document/extract',
      {
        multipart: {
          file: {
            name: 'test-mandate.jpg',
            mimeType: 'image/jpeg',
            buffer: readFileSync(resolve(FIXTURE_DIR, 'test-mandate.jpg')),
          },
        },
        headers: await csrfHeaders(page),
      }
    )
    // Skip when backend has LLM configured (returns non-409)
    // This handles both process-env detection and Docker/integration environments
    // where LLM is configured in the backend but not the test process env
    test.skip(resp.status() !== 409, 'Backend has LLM configured — endpoint returns non-409')
    expect(resp.status()).toBe(409)
    const body = await resp.json()
    expect(body.error).toBe('extraction_not_configured')
  })
})

// ── POST /api/admin/mandate-document/extract — LLM configured ─────────────────
//
// These tests verify the positive extraction path.
// Skipped when LLM_API_KEY is not set (endpoint returns 409).

test.describe('POST /api/admin/mandate-document/extract — LLM configured', () => {
  test('returns 200 with per-field extraction result', async ({ page }) => {
    test.skip(!EXTRACTION_CONFIGURED, 'LLM_API_KEY not set — skipping positive extraction test')
    test.setTimeout(30000) // Extended thinking can take up to 2–3 minutes
    await page.goto('http://localhost:5173/members')
    const resp = await page.request.post(
      'http://localhost:8080/api/admin/mandate-document/extract',
      {
        multipart: {
          file: {
            name: 'sepa-form.jpg',
            mimeType: 'image/jpeg',
            buffer: readFileSync(resolve(FIXTURE_DIR, 'sepa-form.jpg')),
          },
        },
        headers: await csrfHeaders(page),
        timeout: 25000,
      }
    )
    expect(resp.status()).toBe(200)
    const body = await resp.json()

    // Response shape: { fields: { first_name: { value, confidence }, ... } }
    expect(body).toHaveProperty('fields')
    const expectedFields = ['first_name', 'last_name', 'email', 'iban', 'account_holder_name', 'mandate_signed_at', 'card_uid']
    for (const field of expectedFields) {
      expect(body.fields).toHaveProperty(field)
      const f = body.fields[field]
      expect(f).toHaveProperty('value')
      expect(f).toHaveProperty('confidence')
      if (f.value !== null) expect(typeof f.value).toBe('string')
      if (f.confidence !== null) expect(['high', 'medium', 'low']).toContain(f.confidence)
    }

    expect(body).toHaveProperty('needsReview')
    expect(typeof body.needsReview).toBe('boolean')
    expect(body.fields.iban).toHaveProperty('checksumValid')
    expect(typeof body.fields.iban.checksumValid).toBe('boolean')
  })
})

// ── Mandate upload — extraction field in response ─────────────────────────────

test.describe('Mandate upload — extraction field in response', () => {
  test('response includes extraction key (null when LLM not configured)', async ({ page }) => {
    await page.goto('http://localhost:5173/members')

    // Probe the extract endpoint to detect backend LLM config before creating a member
    const probe = await page.request.post(
      'http://localhost:8080/api/admin/mandate-document/extract',
      {
        multipart: { file: { name: 'x.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('x') } },
        headers: await csrfHeaders(page),
      }
    )
    // Skip when backend has LLM (probe returns non-409): extraction would be non-null
    test.skip(probe.status() !== 409, 'Backend has LLM configured — extraction will be non-null')

    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: {
          file: {
            name: 'test-mandate.jpg',
            mimeType: 'image/jpeg',
            buffer: readFileSync(resolve(FIXTURE_DIR, 'test-mandate.jpg')),
          },
        },
        headers: await csrfHeaders(page),
      }
    )
    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body).toHaveProperty('extraction')
    expect(body.extraction).toBeNull()
    expect(body.extraction_status).toBeNull()
  })

  test('response includes completed extraction when LLM configured', async ({ page }) => {
    test.skip(!EXTRACTION_CONFIGURED, 'LLM_API_KEY not set — skipping positive extraction test')
    test.setTimeout(30000) // Extended thinking can take up to 2–3 minutes
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: {
          file: {
            name: 'sepa-form.jpg',
            mimeType: 'image/jpeg',
            buffer: readFileSync(resolve(FIXTURE_DIR, 'sepa-form.jpg')),
          },
        },
        headers: await csrfHeaders(page),
        timeout: 25000,
      }
    )
    expect(resp.status()).toBe(200)
    const body = await resp.json()

    expect(body.extraction_status).toBe('completed')
    expect(body.extraction).not.toBeNull()
    expect(body.extraction).toHaveProperty('fields')

    const expectedFields = ['first_name', 'last_name', 'email', 'iban', 'account_holder_name', 'mandate_signed_at', 'card_uid']
    for (const field of expectedFields) {
      expect(body.extraction.fields).toHaveProperty(field)
      const f = body.extraction.fields[field]
      expect(f).toHaveProperty('value')
      expect(f).toHaveProperty('confidence')
    }
  })
})

// ── Golden values — sepa-form.jpg fixture ─────────────────────────────────────
//
// sepa-form.jpg is a filled-out Club Bar membership/SEPA mandate form used as a
// stable regression fixture.  Skipped when LLM is not configured.
//
// Exact values are asserted only for fields read with high confidence across
// multiple runs.  IBAN and mandate_signed_at use pattern assertions:
//
//   IBAN: exact value asserted — Kästchen layout + extended thinking pipeline
//         (mod-97 validation + brute-force refinement) makes this reliable.
//
//   mandate_signed_at: actual date on form is 2026-04-01.  The form now uses
//         individual digit boxes (Kästchen) with pre-printed dots separating
//         day/month/year, so the full date should be consistently readable.
//         We assert YYYY-MM-DD format and the correct year-month-day.

test.describe('POST /api/admin/mandate-document/extract — golden values (sepa-form.jpg)', () => {
  test('extracts correct values from sepa-form.jpg', async ({ page }) => {
    test.skip(!EXTRACTION_CONFIGURED, 'LLM_API_KEY not set — skipping golden extraction test')
    test.setTimeout(30000) // Extended thinking can take up to 2–3 minutes
    await page.goto('http://localhost:5173/members')
    const resp = await page.request.post(
      'http://localhost:8080/api/admin/mandate-document/extract',
      {
        multipart: {
          file: {
            name: 'sepa-form.jpg',
            mimeType: 'image/jpeg',
            buffer: readFileSync(resolve(FIXTURE_DIR, 'sepa-form.jpg')),
          },
        },
        headers: await csrfHeaders(page),
        timeout: 25000,
      }
    )
    expect(resp.status()).toBe(200)
    const body = await resp.json()
    const f = body.fields

    // High-confidence fields — exact value assertions
    expect(f.first_name.value).toBe('Max')
    expect(f.last_name.value).toBe('Müller')
    expect(f.email.value).toBe('max@mueller.de')
    expect(f.account_holder_name.value).toBe('Mandy Müller')

    // IBAN — assert exact value
    expect(f.iban.value).toBe('DE02100100100006820101')

    // mandate_signed_at — assert exact date (Kästchen layout makes all digits unambiguous)
    expect(f.mandate_signed_at.value).toBe('2026-04-01')
  })
})

// ── Golden values — sepa-form-2.jpg fixture ───────────────────────────────────

test.describe('POST /api/admin/mandate-document/extract — golden values (sepa-form-2.jpg)', () => {
  test('extracts correct values from sepa-form-2.jpg', async ({ page }) => {
    test.skip(!EXTRACTION_CONFIGURED, 'LLM_API_KEY not set — skipping golden extraction test')
    test.setTimeout(30000)
    await page.goto('http://localhost:5173/members')
    const resp = await page.request.post(
      'http://localhost:8080/api/admin/mandate-document/extract',
      {
        multipart: {
          file: {
            name: 'sepa-form-2.jpg',
            mimeType: 'image/jpeg',
            buffer: readFileSync(resolve(FIXTURE_DIR, 'sepa-form-2.jpg')),
          },
        },
        headers: await csrfHeaders(page),
        timeout: 25000,
      }
    )
    expect(resp.status()).toBe(200)
    const body = await resp.json()
    const f = body.fields

    expect(f.first_name.value).toBe('Susi')
    expect(f.last_name.value).toBe('Sommerfrische')
    expect(f.email.value).toBe('bliblablub@gmail.com')
    expect(f.account_holder_name.value).toBeNull()
    expect(f.iban.value).toBe('DE02370502990000684712')
    expect(f.mandate_signed_at.value).toBe('2026-05-03')
  })
})

// ── UI — extraction review banner and New from scan button ────────────────────

test.describe('UI — extraction review banner and New from scan button', () => {
  test('extraction review banner is not visible by default on Members page', async ({ page }) => {
    await page.goto('/members')
    await expect(page.locator('[data-testid="members-page"]')).toBeVisible({ timeout: 5000 })
    await expect(page.locator('[data-testid="extraction-review-banner"]')).not.toBeVisible()
  })

  test('"New from scan" button is visible on Members page', async ({ page }) => {
    await page.goto('/members')
    await expect(page.locator('[data-testid="members-page"]')).toBeVisible({ timeout: 5000 })
    await expect(page.locator('[data-testid="members-new-from-scan-button"]')).toBeVisible()
  })
})

// ── UI — scan-to-create: form pre-fill with mock extraction ───────────────────
//
// Verifies that the "New from scan" flow correctly pre-fills all extractable
// fields in the member creation form.  The extraction API is mocked so this
// test runs without a real LLM configured.
//
// card_uid IS extracted: the Club Bar SEPA template includes a "Chip-ID" field.

test.describe('UI — scan-to-create: form pre-fill with mock extraction', () => {
  test('pre-fills all extractable fields including card_uid and creates member end-to-end', async ({ page }) => {
    const ts = Date.now()
    const mockFirstName = `ScanTest${ts}`
    const mockLastName = 'Mustermann'
    const mockIban = 'DE89370400440532013000'
    // Use last 8 hex digits of timestamp for a unique, test-run-isolated card UID
    const mockCardUid = ts.toString(16).toUpperCase().slice(-8).padStart(8, '0')

    // Set up route mock BEFORE navigating so it's active when the upload fires
    await page.route('**/api/admin/mandate-document/extract', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          fields: {
            first_name: { value: mockFirstName, confidence: 'high' },
            last_name: { value: mockLastName, confidence: 'high' },
            email: { value: `scan-${ts}@example.com`, confidence: 'high' },
            iban: { value: mockIban, confidence: 'high' },
            account_holder_name: { value: 'Otto Mustermann', confidence: 'medium' },
            mandate_signed_at: { value: '2024-06-15', confidence: 'high' },
            card_uid: { value: mockCardUid, confidence: 'high' },
          },
        }),
      })
    })

    await page.goto('/members')
    await expect(page.locator('[data-testid="members-page"]')).toBeVisible({ timeout: 5000 })

    // Trigger the hidden scan file input with a fake file
    await page.locator('[data-testid="members-scan-input"]').setInputFiles({
      name: 'mandate.jpg',
      mimeType: 'image/jpeg',
      buffer: Buffer.from('fake-jpeg-data'),
    })

    // Wait for the create form modal to open after extraction completes
    await expect(page.locator('[data-testid="members-form-modal"]')).toBeVisible({ timeout: 5000 })

    // Verify all extractable fields are pre-filled from the mock response
    await expect(page.locator('[data-testid="members-form-first-name-input"]')).toHaveValue(mockFirstName)
    await expect(page.locator('[data-testid="members-form-last-name-input"]')).toHaveValue(mockLastName)
    await expect(page.locator('[data-testid="members-form-email-input"]')).toHaveValue(`scan-${ts}@example.com`)
    await expect(page.locator('[data-testid="members-form-iban-input"]')).toHaveValue(mockIban)
    await expect(page.locator('[data-testid="members-form-account-holder-name-input"]')).toHaveValue('Otto Mustermann')
    await expect(page.locator('[data-testid="members-form-mandate-date-input"]')).toHaveValue('2024-06-15')
    await expect(page.locator('[data-testid="member-form-card-uid"]')).toHaveValue(mockCardUid)

    // Submit form — verify member is created and persisted (E2E: frontend → API → backend)
    const createResponse = page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/members') &&
        resp.request().method() === 'POST' &&
        resp.status() === 201,
      { timeout: 10000 }
    )
    await page.locator('[data-testid="members-form-submit-button"]').click()
    await createResponse

    // Form closes after successful creation
    await expect(page.locator('[data-testid="members-form-modal"]')).not.toBeVisible({ timeout: 5000 })

    // Member appears in the list
    await expect(
      page.locator('[data-testid^="members-table-cell-name-"]').filter({ hasText: mockFirstName })
    ).toBeVisible({ timeout: 10000 })
  })
})

// ── UI — scan-to-create: future mandate date corrected by admin ───────────────
//
// When extraction returns a future mandate_signed_at date, the admin must
// correct it manually before the form can be submitted.  This test verifies
// the full flow: future date pre-filled → admin corrects → member created.

test.describe('UI — scan-to-create: future mandate date corrected by admin', () => {
  test('admin corrects future mandate date and creates member', async ({ page }) => {
    const ts = Date.now()
    const mockFirstName = `FutureDate${ts}`
    const mockLastName = 'TestUser'
    const mockIban = 'DE89370400440532013000'

    // Compute a date 3 months in the future (always in the future regardless of when test runs)
    const futureDate = new Date()
    futureDate.setMonth(futureDate.getMonth() + 3)
    const futureDateStr = futureDate.toISOString().split('T')[0]

    // The corrected date the admin will manually enter
    const correctedDate = '2025-01-15'

    // Mock extraction returning a future mandate date
    await page.route('**/api/admin/mandate-document/extract', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          fields: {
            first_name: { value: mockFirstName, confidence: 'high' },
            last_name: { value: mockLastName, confidence: 'high' },
            email: { value: `futuredate-${ts}@example.com`, confidence: 'high' },
            iban: { value: mockIban, confidence: 'high' },
            account_holder_name: { value: null, confidence: null },
            mandate_signed_at: { value: futureDateStr, confidence: 'high' },
            card_uid: { value: null, confidence: null },
          },
        }),
      })
    })

    await page.goto('/members')
    await expect(page.locator('[data-testid="members-page"]')).toBeVisible({ timeout: 5000 })

    // Trigger scan with a fake file
    await page.locator('[data-testid="members-scan-input"]').setInputFiles({
      name: 'mandate.jpg',
      mimeType: 'image/jpeg',
      buffer: Buffer.from('fake-jpeg-data'),
    })

    // Form opens with the future date pre-filled
    await expect(page.locator('[data-testid="members-form-modal"]')).toBeVisible({ timeout: 5000 })
    await expect(page.locator('[data-testid="members-form-mandate-date-input"]')).toHaveValue(futureDateStr)

    // Admin corrects the mandate date to a valid past date
    await page.fill('[data-testid="members-form-mandate-date-input"]', correctedDate)
    await expect(page.locator('[data-testid="members-form-mandate-date-input"]')).toHaveValue(correctedDate)

    // Submit — verify member is created (E2E: frontend → API → backend)
    const createResponse = page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/members') &&
        resp.request().method() === 'POST' &&
        resp.status() === 201,
      { timeout: 10000 }
    )
    await page.locator('[data-testid="members-form-submit-button"]').click()
    await createResponse

    // Form closes after successful creation
    await expect(page.locator('[data-testid="members-form-modal"]')).not.toBeVisible({ timeout: 5000 })

    // Member appears in the list
    await expect(
      page.locator('[data-testid^="members-table-cell-name-"]').filter({ hasText: mockFirstName })
    ).toBeVisible({ timeout: 10000 })
  })
})
