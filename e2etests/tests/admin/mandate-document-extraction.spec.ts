import { test, expect } from '../../fixtures/pageObjects'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { csrfHeaders } from '../../utils/csrf'
import type { Page } from '@playwright/test'

const FIXTURE_DIR = resolve(__dirname, '../../fixtures/files')
const LLM_CONFIGURED = !!process.env.LLM_API_KEY

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
    test.skip(!LLM_CONFIGURED, 'LLM_API_KEY not set — endpoint returns 409 before reaching file validation')
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

test.describe('POST /api/admin/mandate-document/extract — LLM not configured', () => {
  test('returns 409 when LLM not configured', async ({ page }) => {
    test.skip(LLM_CONFIGURED, 'LLM_API_KEY is set — endpoint returns 200, not 409')
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
    expect(resp.status()).toBe(409)
    const body = await resp.json()
    expect(body.error).toBe('llm_not_configured')
  })
})

// ── POST /api/admin/mandate-document/extract — LLM configured ─────────────────
//
// These tests verify the positive extraction path.
// Skipped when LLM_API_KEY is not set (endpoint returns 409).

test.describe('POST /api/admin/mandate-document/extract — LLM configured', () => {
  test('returns 200 with per-field extraction result', async ({ page }) => {
    test.skip(!LLM_CONFIGURED, 'LLM_API_KEY not set — skipping positive extraction test')
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
    expect(resp.status()).toBe(200)
    const body = await resp.json()

    // Response shape: { fields: { first_name: { value, confidence }, ... } }
    expect(body).toHaveProperty('fields')
    const expectedFields = ['first_name', 'last_name', 'email', 'iban', 'account_holder_name', 'mandate_signed_at']
    for (const field of expectedFields) {
      expect(body.fields).toHaveProperty(field)
      const f = body.fields[field]
      expect(f).toHaveProperty('value')
      expect(f).toHaveProperty('confidence')
      if (f.value !== null) expect(typeof f.value).toBe('string')
      if (f.confidence !== null) expect(['high', 'medium', 'low']).toContain(f.confidence)
    }
  })
})

// ── Mandate upload — extraction field in response ─────────────────────────────

test.describe('Mandate upload — extraction field in response', () => {
  test('response includes extraction key (null when LLM not configured)', async ({ page }) => {
    test.skip(LLM_CONFIGURED, 'LLM_API_KEY is set — extraction will be non-null; use the configured test below')
    await page.goto('http://localhost:5173/members')
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
    test.skip(!LLM_CONFIGURED, 'LLM_API_KEY not set — skipping positive extraction test')
    await page.goto('http://localhost:5173/members')
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

    expect(body.extraction_status).toBe('completed')
    expect(body.extraction).not.toBeNull()
    expect(body.extraction).toHaveProperty('fields')

    const expectedFields = ['first_name', 'last_name', 'email', 'iban', 'account_holder_name', 'mandate_signed_at']
    for (const field of expectedFields) {
      expect(body.extraction.fields).toHaveProperty(field)
      const f = body.extraction.fields[field]
      expect(f).toHaveProperty('value')
      expect(f).toHaveProperty('confidence')
    }
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
