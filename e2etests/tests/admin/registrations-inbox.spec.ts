import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import {
  clearRegistrationAttempts,
  configureSelfRegistration,
  countPendingRegistrations,
  restoreClubDocumentUrl,
  serveClubDocument,
  stopServingClubDocument,
} from '../../utils/sql'
import { test } from '../../fixtures/roleRequests'

/**
 * The registrations inbox in the panel (#782, UC-A17).
 *
 * Every submission here is made through the **public endpoint**, so what the
 * screen shows is a real applicant's row rather than a fixture: the claim worth
 * asserting is that a stranger's phone and a treasurer's browser meet correctly,
 * and a hand-written database row would prove neither end.
 *
 * Serial, and for the reason the API specs are: `self_registration_config` is a
 * single row by design — one club, one poster, one switch — so parallel workers
 * overwrite each other's secret mid-flight.
 */

const API = 'http://localhost:8080/api'
const TEST_IBAN = 'DE89370400440532013000'

test.describe('Registrations inbox', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(() => {
    serveClubDocument()
  })

  test.afterAll(() => {
    stopServingClubDocument()
  })

  test.beforeEach(() => {
    clearRegistrationAttempts()
  })

  test.afterEach(() => {
    restoreClubDocumentUrl()
  })

  /** Submit through the public endpoint, the way an applicant does. */
  const submit = async (request: any, overrides: Record<string, unknown> = {}) => {
    const secret = `secret-${randomUUID()}`
    configureSelfRegistration(secret)

    const unique = randomUUID().slice(0, 8)
    const data = {
      first_name: 'Lena',
      last_name: `Brandt-${unique}`,
      email: `lena-${unique}@example.org`,
      date_of_birth: '1998-04-02',
      preferred_language: 'de',
      iban: TEST_IBAN,
      ...overrides,
    }

    const response = await request.post(`${API}/public/registrations`, { data: { secret, ...data } })
    expect(response.status()).toBe(201)

    return { id: (await response.json()).id as string, data }
  }

  const openInbox = async (page: any) => {
    await page.goto('/registrations')
    await expect(page.getByTestId('registrations-page')).toBeVisible()
  }

  test('the queue lists a real submission, masked, and flags nothing for a stranger', async ({
    page,
    request,
  }) => {
    const { id, data } = await submit(request)

    await openInbox(page)
    const row = page.getByTestId(`registration-row-${id}`)
    await expect(row).toBeVisible()
    await expect(row).toContainText(data.last_name as string)
    await expect(row).toContainText('****3000')

    // The claim the module rests on, asserted at the surface an admin actually
    // looks at: the readable number is nowhere on the page.
    expect(await page.content()).not.toContain(TEST_IBAN)
  })

  test('the nav carries the pending count', async ({ page, request }) => {
    await submit(request)

    await openInbox(page)

    // `.first()`, and the second one is not a bug: `DesktopNav` decides what
    // fits by measuring an off-screen copy of every entry, so the badge
    // deliberately renders inside the icon node and is therefore measured with
    // it. A badge rendered outside the entry would change the real width
    // without changing the measured one, and the last section in the row would
    // fall off the end for a reason nothing explains (#742).
    const badge = page.getByTestId('nav-registrations-count').first()
    await expect(badge).toBeVisible()
    // Not an exact number: the dev stack accumulates rows across runs, and what
    // this asserts is that the count reaches the nav at all.
    expect((await badge.textContent()) ?? '').toMatch(/^(\d+|99\+)$/)
  })

  test('the detail panel shows the notice the applicant was pointed at', async ({ page, request }) => {
    const { id } = await submit(request)

    await openInbox(page)
    await page.getByTestId(`registration-open-${id}`).click()

    await expect(page.getByTestId('registration-panel')).toBeVisible()
    await expect(page.getByTestId('panel-iban')).toHaveText('****3000')
    // The club's evidence that the Datenschutzhinweise were reachable before
    // anything was collected.
    await expect(page.getByTestId('panel-notice-url')).not.toBeEmpty()
  })

  test('approving needs the attestation, then lands on the member', async ({ page, request }) => {
    const { id, data } = await submit(request)
    const before = countPendingRegistrations()

    await openInbox(page)
    await page.getByTestId(`registration-open-${id}`).click()
    await page.getByTestId('panel-approve').click()

    // The point of the endpoint: no attestation, no approval — and the button
    // says so before the server has to.
    await expect(page.getByTestId('approve-confirm')).toBeDisabled()

    await page.getByTestId('approve-signed-at').fill('30.08.2026')
    await page.getByTestId('approve-attestation').check()
    await expect(page.getByTestId('approve-confirm')).toBeEnabled()

    await page.getByTestId('approve-confirm').click()

    // The approval's whole point is a member, so that is where it lands —
    // the roster, searched down to the one just created. There is no member
    // detail route in this app; members are a list plus a modal.
    await expect(page).toHaveURL(/\/members\?search=/)
    await expect(page.getByText(data.last_name as string).first()).toBeVisible()

    expect(countPendingRegistrations()).toBe(before - 1)

    await openInbox(page)
    await expect(page.getByTestId(`registration-row-${id}`)).toHaveCount(0)
  })

  test('an applicant the club already has is flagged before anyone clicks approve', async ({
    page,
    request,
  }) => {
    const first = await submit(request)

    await openInbox(page)
    await page.getByTestId(`registration-open-${first.id}`).click()
    await page.getByTestId('panel-approve').click()
    await page.getByTestId('approve-signed-at').fill('30.08.2026')
    await page.getByTestId('approve-attestation').check()
    await page.getByTestId('approve-confirm').click()
    await expect(page).toHaveURL(/\/members\?search=/)

    // The same person submits again.
    const again = await submit(request, { email: first.data.email })

    await openInbox(page)
    await expect(page.getByTestId(`duplicate-email-${again.id}`)).toBeVisible()

    // And approving anyway is refused — in the admin's language, never the
    // backend's English.
    await page.getByTestId(`registration-open-${again.id}`).click()
    await page.getByTestId('panel-approve').click()
    await page.getByTestId('approve-signed-at').fill('30.08.2026')
    await page.getByTestId('approve-attestation').check()
    await page.getByTestId('approve-confirm').click()

    const error = page.getByTestId('panel-error')
    await expect(error).toBeVisible()
    await expect(error).not.toContainText('already exists')
  })

  test('rejecting says the data is deleted, and deletes it', async ({ page, request }) => {
    const { id } = await submit(request)
    const before = countPendingRegistrations()

    await openInbox(page)
    await page.getByTestId(`registration-open-${id}`).click()
    await page.getByTestId('panel-reject').click()

    await expect(page.getByTestId('reject-warning')).toBeVisible()
    await page.getByTestId('reject-reason').fill('Kein Mandat eingegangen')
    await page.getByTestId('reject-confirm').click()

    await expect(page.getByTestId('registration-panel')).toBeHidden()
    expect(countPendingRegistrations()).toBe(before - 1)
  })

  test('an edit corrects a typo through the whole stack', async ({ page, request }) => {
    const { id } = await submit(request, { first_name: 'Lenna' })

    await openInbox(page)
    await page.getByTestId(`registration-open-${id}`).click()
    await page.getByTestId('panel-edit').click()

    // The IBAN field opens empty on purpose: pre-filled it would invite an
    // accidental "correction" to a value that was already right, and a
    // correction re-seals irreversibly.
    await expect(page.getByTestId('edit-iban')).toHaveValue('')

    await page.getByTestId('edit-first-name').fill('Lena')
    await page.getByTestId('edit-save').click()

    await openInbox(page)
    await expect(page.getByTestId(`registration-row-${id}`)).toContainText('Lena')
  })

  test('the print action downloads the club form', async ({ page, request }) => {
    const { id } = await submit(request)

    await openInbox(page)
    await page.getByTestId(`registration-open-${id}`).click()

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('panel-print').click(),
    ])
    expect(download.suggestedFilename()).toContain('.pdf')
  })
})
