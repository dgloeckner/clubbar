import { test, expect } from '../../fixtures/pageObjects'
import { resolve } from 'path'
import { readFileSync, existsSync } from 'fs'
import { csrfHeaders } from '../../utils/csrf'

const FIXTURES = resolve(__dirname, '../../fixtures/files')

// Helper: create an isolated member and return its id
async function createTestMember(page: import('@playwright/test').Page): Promise<string> {
  const ts = Date.now()
  const resp = await page.request.post('http://localhost:8080/api/admin/members', {
    data: {
      first_name: `MandateTest`,
      last_name: `User${ts}`,
      email: `mandate-doc-${ts}@example.com`,
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

test.describe('Mandate Document API', () => {
  // ── Upload: JPEG ──────────────────────────────────────────────────────────
  test('POST uploads JPEG and returns document info', async ({ page }) => {
    await page.goto('http://localhost:5173/members') // load auth state (localStorage csrf_token)
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.jpg', mimeType: 'image/jpeg', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.jpg')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body).toHaveProperty('uploaded_at')
    expect(body).toHaveProperty('file_size_bytes')
    expect(body.original_filename).toBe('test-mandate.jpg')
    // extraction_status is null when extraction is not configured, or a status string when it is
    expect([null, 'pending', 'completed', 'failed']).toContain(body.extraction_status)
  })

  // ── Upload: PNG ───────────────────────────────────────────────────────────
  test('POST uploads PNG and returns document info', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.png', mimeType: 'image/png', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.png')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body.original_filename).toBe('test-mandate.png')
  })

  // ── Upload: PDF ───────────────────────────────────────────────────────────
  test('POST uploads PDF stored as-is', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body.original_filename).toBe('test-mandate.pdf')
  })

  // ── GET streams PDF ───────────────────────────────────────────────────────
  test('GET returns PDF after upload', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )

    expect(getResp.status()).toBe(200)
    expect(getResp.headers()['content-type']).toContain('application/pdf')
  })

  // ── GET: no document → 404 ────────────────────────────────────────────────
  test('GET returns 404 when no document exists', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )

    expect(resp.status()).toBe(404)
  })

  // ── Replace existing document ─────────────────────────────────────────────
  test('POST replaces existing document', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.jpg', mimeType: 'image/jpeg', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.jpg')) } },
        headers: await csrfHeaders(page),
      }
    )

    const replaceResp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(replaceResp.status()).toBe(200)
    const body = await replaceResp.json()
    expect(body.original_filename).toBe('test-mandate.pdf')

    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(200)
  })

  // ── DELETE ────────────────────────────────────────────────────────────────
  test('DELETE removes document and GET returns 404', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    const delResp = await page.request.delete(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      { headers: await csrfHeaders(page) }
    )
    expect(delResp.status()).toBe(204)

    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(404)
  })

  // ── GDPR anonymize deletes document ───────────────────────────────────────
  test('anonymize deletes mandate document', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    const anonResp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/anonymize`,
      { headers: await csrfHeaders(page) }
    )
    expect(anonResp.ok()).toBe(true)

    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(404)
  })

  // ── Content decides the type, not the declared one (#107) ─────────────────
  test('POST rejects HTML bytes declared as application/pdf', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    // `application/pdf` is the one declared type that skips the dompdf
    // re-render, so a client that lies about it used to get arbitrary bytes
    // written to the mandate store and served back as a PDF.
    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: {
          file: {
            name: 'mandate.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('<html><body><script>alert(1)</script></body></html>'),
          },
        },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(422)

    // Nothing reached the store, so there is still no document to download.
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.status()).toBe(404)
  })

  test('POST accepts a PDF whose declared type is wrong', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: {
          file: {
            name: 'scan.png',
            mimeType: 'image/png',
            buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')),
          },
        },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
  })

  // ── Download headers ──────────────────────────────────────────────────────
  test('GET serves the document as a nosniff attachment', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )

    expect(getResp.status()).toBe(200)
    // The bytes came from an upload, so the browser must neither re-type them
    // nor render them in the admin panel's origin (#107).
    // Contains, not equals: Apache's .htaccess sets the same header, so a
    // response served through it carries the value twice.
    expect(getResp.headers()['x-content-type-options']).toContain('nosniff')
    expect(getResp.headers()['content-disposition']).toContain('attachment')
  })

  // ── Invalid file type → 422 ───────────────────────────────────────────────
  test('POST rejects unsupported file type', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'doc.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: Buffer.from('fake docx') } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(422)
  })

  // ── Large image: dompdf converts it ───────────────────────────────────────
  test('POST with large JPEG stores file successfully', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const largeFile = readFileSync(resolve(FIXTURES, 'test-mandate-large.jpg'))

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate-large.jpg', mimeType: 'image/jpeg', buffer: largeFile } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const body = await resp.json()
    expect(body.file_size_bytes).toBeGreaterThan(0)
  })

  // ── HEIC upload (skipped in CI if fixture absent) ─────────────────────────
  test('POST uploads HEIC converted to PDF', async ({ page }) => {
    const heicPath = resolve(FIXTURES, 'test-mandate.heic')
    if (!existsSync(heicPath)) {
      test.skip(true, 'HEIC fixture not available — add e2etests/fixtures/files/test-mandate.heic from an iPhone photo')
      return
    }

    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const resp = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.heic', mimeType: 'image/heic', buffer: readFileSync(heicPath) } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(200)
    const getResp = await page.request.get(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`
    )
    expect(getResp.headers()['content-type']).toContain('application/pdf')
  })

  // ── Non-existent member → 404 ─────────────────────────────────────────────
  test('POST returns 404 for non-existent member', async ({ page }) => {
    await page.goto('http://localhost:5173/members')

    const resp = await page.request.post(
      'http://localhost:8080/api/admin/members/00000000-0000-0000-0000-000000000000/mandate-document',
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') } },
        headers: await csrfHeaders(page),
      }
    )

    expect(resp.status()).toBe(404)
  })

  // ── GET /admin/members/{id} includes mandate_document field ───────────────
  test('GET member response includes mandate_document field', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    const memberId = await createTestMember(page)

    const before = await page.request.get(`http://localhost:8080/api/admin/members/${memberId}`)
    expect((await before.json()).mandate_document).toBeNull()

    await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/mandate-document`,
      {
        multipart: { file: { name: 'test-mandate.pdf', mimeType: 'application/pdf', buffer: readFileSync(resolve(FIXTURES, 'test-mandate.pdf')) } },
        headers: await csrfHeaders(page),
      }
    )

    const after = await page.request.get(`http://localhost:8080/api/admin/members/${memberId}`)
    const doc = (await after.json()).mandate_document
    expect(doc).not.toBeNull()
    expect(doc).toHaveProperty('uploaded_at')
    expect(doc).toHaveProperty('file_size_bytes')
    expect(doc.original_filename).toBe('test-mandate.pdf')
    // extraction_status is null when extraction is not configured, or a status string when it is
    expect([null, 'pending', 'completed', 'failed']).toContain(doc.extraction_status)
  })
})
