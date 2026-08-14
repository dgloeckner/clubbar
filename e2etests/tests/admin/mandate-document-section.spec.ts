/**
 * MandateDocumentSection — extraction-only (ADR-0037, UC-A15)
 *
 * The system no longer stores mandate scans: `POST/GET/DELETE
 * /admin/members/{id}/mandate-document` are gone, and this component now does
 * exactly one thing — pick a file, call the stateless extract endpoint, hand
 * the result to the parent form. There is no "stored document" state to
 * upload, replace or download; mandate-document-extraction.spec.ts covers the
 * extract endpoint itself (auth, validation, PDF parity, golden values), so
 * this file's job is the component wiring: does picking a file in the member
 * edit view actually reach the form.
 *
 * The extract route is mocked in every test here so the assertions do not
 * depend on an LLM being configured — mandate-document-extraction.spec.ts
 * already covers the real provider path end to end.
 *
 * E2E Patterns applied:
 *  - Pattern 001: Unique test data per test (fresh member, timestamped last name)
 *  - Pattern 003: Database-agnostic assertions — the member is located by its own
 *    unique last name via search, not by table position
 *  - Pattern 005/006: data-testid selectors, Page Object Model (MembersPage)
 */

import { readFileSync } from 'fs'
import { resolve } from 'path'
import { Page } from '@playwright/test'
import { test, expect } from '../../fixtures/pageObjects'
import { csrfHeaders } from '../../utils/csrf'

const FIXTURE_DIR = resolve(__dirname, '../../fixtures/files')

async function createTestMember(page: Page): Promise<{ id: string; lastName: string }> {
  const ts = Date.now()
  const lastName = `MandateSection${ts}`
  const resp = await page.request.post('http://localhost:8080/api/admin/members', {
    data: {
      first_name: 'Test',
      last_name: lastName,
      email: `mandate-section-${ts}@example.com`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2025-01-01',
      preferred_language: 'de',
    },
    headers: await csrfHeaders(page),
  })
  expect(resp.ok()).toBe(true)
  const body = await resp.json()
  return { id: body.id, lastName }
}

test.describe('MandateDocumentSection — extraction fills the edit form', () => {
  test('picks a file, extracts fields into the form, and never shows a stored document', async ({
    page,
    authenticatedMembersPage,
  }) => {
    const { id: memberId, lastName } = await createTestMember(page)
    const mockIban = 'DE02100100100006820101'

    await page.route('**/api/admin/mandate-document/extract', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          fields: {
            iban: { value: mockIban, confidence: 'high' },
            account_holder_name: { value: 'Scanned Holder', confidence: 'medium' },
            mandate_signed_at: { value: '2024-06-15', confidence: 'high' },
          },
          needsReview: false,
        }),
      })
    })

    await authenticatedMembersPage.search(lastName)
    await authenticatedMembersPage.openEditModalForMember(memberId)
    await authenticatedMembersPage.expectFormModalVisible()

    // Nothing has been picked yet — just the picker, never a stored-document view.
    await expect(page.getByTestId('mandate-document-dropzone')).toBeVisible()
    await expect(page.getByTestId('mandate-document-stored')).toHaveCount(0)

    // Uses the realistic 2400x1800 fixture, not the ~281-byte stub: browser-image-compression
    // resizes/re-encodes via a web worker, and a degenerate near-empty image is an edge case
    // the library isn't necessarily built to handle.
    await page.getByTestId('mandate-document-input').setInputFiles({
      name: 'test-mandate-large.jpg',
      mimeType: 'image/jpeg',
      buffer: readFileSync(resolve(FIXTURE_DIR, 'test-mandate-large.jpg')),
    })
    // Compression runs in a web worker and can take a while under CI's parallel load.
    await expect(page.getByTestId('mandate-document-preview')).toBeVisible({ timeout: 25000 })

    const extractResponse = page.waitForResponse(
      (r) => r.url().includes('/api/admin/mandate-document/extract') && r.request().method() === 'POST'
    )
    await page.getByTestId('mandate-document-upload-btn').click()
    await extractResponse

    // The extraction result reached the member edit form itself — this
    // endpoint stores nothing, so the form is the only place it can land.
    await expect(page.getByTestId('members-form-iban-input')).toHaveValue(mockIban)
    await expect(page.getByTestId('members-form-account-holder-name-input')).toHaveValue('Scanned Holder')
    await expect(page.getByTestId('members-form-mandate-date-input')).toHaveValue('2024-06-15')

    // The section itself goes back to idle — there is no "stored" state to move to.
    await expect(page.getByTestId('mandate-document-dropzone')).toBeVisible()
    await expect(page.getByTestId('mandate-document-preview')).not.toBeVisible()
    await expect(page.getByTestId('mandate-document-stored')).toHaveCount(0)

    // No mandate-document endpoint exists to have persisted anything to.
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(404)
  })

  test('cancelling a picked file discards it without calling the extract endpoint', async ({
    page,
    authenticatedMembersPage,
  }) => {
    const { id: memberId, lastName } = await createTestMember(page)

    let extractCalls = 0
    await page.route('**/api/admin/mandate-document/extract', async (route) => {
      extractCalls++
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ fields: {} }) })
    })

    await authenticatedMembersPage.search(lastName)
    await authenticatedMembersPage.openEditModalForMember(memberId)
    await authenticatedMembersPage.expectFormModalVisible()

    await page.getByTestId('mandate-document-input').setInputFiles({
      name: 'test-mandate-large.jpg',
      mimeType: 'image/jpeg',
      buffer: readFileSync(resolve(FIXTURE_DIR, 'test-mandate-large.jpg')),
    })
    await expect(page.getByTestId('mandate-document-preview')).toBeVisible({ timeout: 25000 })

    await page.getByTestId('mandate-document-cancel-btn').click()

    await expect(page.getByTestId('mandate-document-dropzone')).toBeVisible()
    await expect(page.getByTestId('mandate-document-preview')).not.toBeVisible()
    expect(extractCalls).toBe(0)
  })

  test('an extraction error is shown inline and keeps the file selected for retry', async ({
    page,
    authenticatedMembersPage,
  }) => {
    const { id: memberId, lastName } = await createTestMember(page)

    await page.route('**/api/admin/mandate-document/extract', async (route) => {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'validation_error', messages: { file: ['Unsupported file type'] } }),
      })
    })

    await authenticatedMembersPage.search(lastName)
    await authenticatedMembersPage.openEditModalForMember(memberId)
    await authenticatedMembersPage.expectFormModalVisible()

    await page.getByTestId('mandate-document-input').setInputFiles({
      name: 'test-mandate-large.jpg',
      mimeType: 'image/jpeg',
      buffer: readFileSync(resolve(FIXTURE_DIR, 'test-mandate-large.jpg')),
    })
    await expect(page.getByTestId('mandate-document-preview')).toBeVisible({ timeout: 25000 })
    await page.getByTestId('mandate-document-upload-btn').click()

    await expect(page.getByTestId('mandate-document-error')).toHaveText('Unsupported file type')
    // Nothing was stored (there is nowhere left for it to go), and the picked
    // file is still there so the admin can retry without re-selecting it.
    await expect(page.getByTestId('mandate-document-preview')).toBeVisible()
  })
})
