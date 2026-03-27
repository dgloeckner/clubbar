import { test, expect } from '../../fixtures/pageObjects'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { csrfHeaders } from '../../utils/csrf'
import type { Page } from '@playwright/test'

const FIXTURE_DIR = resolve(__dirname, '../../fixtures/files')

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

// ── POST /api/admin/mandate-document/extract — LLM not configured ─────────────

test.describe('POST /api/admin/mandate-document/extract — LLM not configured', () => {
  test('returns 409 when LLM not configured', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/mandate-document/extract`,
      {
        multipart: {
          member_id: memberId,
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
  })

  test('returns 422 when no file provided', async ({ page }) => {
    await page.goto('http://localhost:5173/members')

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/mandate-document/extract`,
      {
        data: {},
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(422)
  })

  test('returns 401 when unauthenticated', async ({ playwright }) => {
    const ctx = await playwright.request.newContext()

    const resp = await ctx.post(
      `http://localhost:8080/api/admin/mandate-document/extract`,
      {
        multipart: {
          file: {
            name: 'test.jpg',
            mimeType: 'image/jpeg',
            buffer: Buffer.from('fake'),
          },
        },
      }
    )

    expect(resp.status()).toBe(401)
    await ctx.dispose()
  })
})

// ── Upload returns extraction field in response ────────────────────────────────

test.describe('Upload returns extraction field in response', () => {
  test('mandate upload response includes extraction key (null when LLM not configured)', async ({ page }) => {
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
