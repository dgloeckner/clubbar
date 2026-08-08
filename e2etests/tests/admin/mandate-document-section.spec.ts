/**
 * MandateDocumentSection — upload and replace from the member edit view (UC-A15)
 *
 * mandate-document.spec.ts exercises POST/GET/DELETE /admin/members/{id}/mandate-document
 * directly against the API, and mandate-document-extraction.spec.ts drives the
 * create-member-from-scan flow — but nothing exercises MandateDocumentSection.tsx itself
 * (the upload/replace UI shown when editing an existing member). This is also the
 * component whose hand-written uploadMandateDocument/deleteMandateDocument API wrappers
 * were deleted in favor of the generated client as part of the OpenAPI spec drift fix.
 *
 * E2E Patterns applied:
 *  - Pattern 001: Unique test data per test (fresh member, timestamped last name)
 *  - Pattern 003: Database-agnostic assertions — the member is located by its own
 *    unique last name via search, not by table position
 *  - Pattern 005/006: data-testid selectors, Page Object Model (MembersPage)
 */

import { readFileSync } from 'fs'
import { resolve } from 'path'
import { Page, Response as PlaywrightResponse } from '@playwright/test'
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

/**
 * Wait for the mandate-document upload response regardless of status, then assert 200
 * with the response body AND the actual request diagnostics in the failure message — a
 * filtered-to-200 waitForResponse predicate would otherwise time out opaquely on any
 * non-200 response (e.g. a rejected upload), masking the real reason.
 */
async function waitForUploadResponse(page: Page, memberId: string): Promise<PlaywrightResponse> {
  const resp = await page.waitForResponse(
    (r) =>
      r.url().includes(`/api/admin/members/${memberId}/mandate-document`) &&
      r.request().method() === 'POST',
    { timeout: 20000 }
  )
  if (resp.status() !== 200) {
    const req = resp.request()
    const postData = req.postDataBuffer()
    const diagnostics = {
      status: resp.status(),
      body: await resp.text().catch(() => '<no body>'),
      requestContentType: req.headers()['content-type'] ?? '<none>',
      requestBodyBytes: postData?.length ?? '<null>',
    }
    expect(resp.status(), JSON.stringify(diagnostics)).toBe(200)
  }
  return resp
}

test.describe('MandateDocumentSection — upload and replace', () => {
  test('uploads a new mandate document, then replaces it with a different file', async ({
    page,
    authenticatedMembersPage,
  }) => {
    const consoleErrors: string[] = []
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text())
    })
    page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`))

    const { id: memberId, lastName } = await createTestMember(page)

    await authenticatedMembersPage.search(lastName)
    await authenticatedMembersPage.openEditModalForMember(memberId)
    await authenticatedMembersPage.expectFormModalVisible()

    // Fresh member — the section starts idle (no document uploaded yet)
    await expect(page.getByTestId('mandate-document-dropzone')).toBeVisible()

    // ── Initial upload (JPEG — exercises the client-side compression path) ──
    // Uses the realistic 2400x1800 fixture, not the ~281-byte stub: browser-image-compression
    // resizes/re-encodes via a web worker, and a degenerate near-empty image is an edge case
    // the library isn't necessarily built to handle — a real photo is what production sees.
    await page.getByTestId('mandate-document-input').setInputFiles({
      name: 'test-mandate-large.jpg',
      mimeType: 'image/jpeg',
      buffer: readFileSync(resolve(FIXTURE_DIR, 'test-mandate-large.jpg')),
    })
    // Compression runs in a web worker and can take a while under CI's parallel load —
    // matches the timeout convention in mandate-document-extraction.spec.ts.
    await expect(page.getByTestId('mandate-document-preview')).toBeVisible({ timeout: 25000 })

    // Only start listening once the upload button is about to be clicked — creating this
    // earlier races the wait's own timeout against however long file selection takes.
    const firstUploadResponse = waitForUploadResponse(page, memberId)
    await page.getByTestId('mandate-document-upload-btn').click()

    let firstResp: PlaywrightResponse
    try {
      firstResp = await firstUploadResponse
    } catch (err) {
      throw new Error(
        `Upload failed. Browser console errors captured: ${JSON.stringify(consoleErrors)}`,
        { cause: err }
      )
    }
    const firstDoc = await firstResp.json()
    expect(firstDoc.original_filename).toBe('test-mandate-large.jpg')

    await expect(page.getByTestId('mandate-document-stored')).toBeVisible()
    await expect(page.getByTestId('mandate-document-dropzone')).not.toBeVisible()

    // ── Replace with a different file (PDF — exercises the no-compression path) ──
    await page.getByTestId('mandate-document-replace-btn').click()
    await expect(page.getByTestId('mandate-document-dropzone')).toBeVisible()

    await page.getByTestId('mandate-document-input').setInputFiles({
      name: 'test-mandate.pdf',
      mimeType: 'application/pdf',
      buffer: readFileSync(resolve(FIXTURE_DIR, 'test-mandate.pdf')),
    })
    // PDFs skip client-side compression — this should be near-instant, but keep some
    // headroom for CI's parallel load, matching the convention used above.
    await expect(page.getByTestId('mandate-document-preview')).toBeVisible({ timeout: 25000 })

    const secondUploadResponse = waitForUploadResponse(page, memberId)
    await page.getByTestId('mandate-document-upload-btn').click()

    const secondResp = await secondUploadResponse
    const secondDoc = await secondResp.json()
    expect(secondDoc.original_filename).toBe('test-mandate.pdf')
    // A genuinely new document, not the first row read back: the PDF is a few hundred
    // bytes where the JPEG is megabytes. This used to compare `uploaded_at`, but that
    // is `mandate_documents.updated_at` — a TIMESTAMP, so it has second resolution, and
    // the replace happens well inside one second of the first upload whenever the runner
    // is quick. Same second, same string, failed assertion, nothing actually wrong.
    expect(secondDoc.file_size_bytes).not.toBe(firstDoc.file_size_bytes)

    await expect(page.getByTestId('mandate-document-stored')).toBeVisible()

    // The replaced document is really persisted server-side, not just local UI state
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.ok()).toBe(true)
    const contentDisposition = getResp.headers()['content-disposition'] ?? ''
    expect(contentDisposition).toContain('.pdf')
  })
})
