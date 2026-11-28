import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { readFileSync } from 'node:fs'
import path from 'node:path'

import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { activePrivateKey, WRONG_PRIVATE_KEY } from '../../fixtures/encryption'
import { test } from '../../fixtures/auth.fixture'
import { MembersPage, RegistrationsPage } from '../../pages'
import { lockClubConfig, unlockClubConfig } from '../../utils/clubConfigLock'
import { uniqueIban } from '../../utils/iban'
import { groupedIban, pdfPageCount, pdfText } from '../../utils/pdf'
import {
  clearRegistrationAttempts,
  configureSelfRegistration,
  restoreClubDocumentUrl,
  serveClubDocument,
  stopServingClubDocument,
} from '../../utils/sql'
import { generateTotp } from '../../utils/totp'

/**
 * Accept first, print in bulk, sign later — in the panel (UC-A17, UC-A18;
 * ADR-0052, ADR-0036 and ADR-0051 as amended 2026-11-28).
 *
 * `tests/api/accept-then-print.spec.ts` proves the three steps through the
 * API. This file proves an admin can *perform* them: each test starts from a
 * stranger's submission on the public endpoint, drives the screens a
 * Kassenwart would, and then checks what the server holds afterwards — a
 * dialog that closes politely and changed nothing is the failure CLAUDE.md's
 * end-to-end checklist exists to catch.
 *
 * ### Isolation (Patterns 001, 003, 004)
 *
 * "Alle aufnehmen" accepts whatever is on screen, and the inbox is shared with
 * every other spec that submits a registration. So the batch test first
 * narrows the queue with the search box to a marker only its own rows carry —
 * which is also the behaviour under test: *all* means all that are shown.
 *
 * The print from the Members page has no such filter (it prints every open
 * mandate in the database), so its assertions are about this test's own
 * member — their name and their IBAN on a page — and about the page count
 * being a whole number of club documents, never an exact total.
 */

const API = 'http://localhost:8080/api'

const TEMPLATE = path.resolve(__dirname, '../../../backend/tests/Fixtures/documents/club-anmeldung.pdf')
const TEMPLATE_PAGES = pdfPageCount(readFileSync(TEMPLATE))

test.describe('Accept first, print in bulk, sign later', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(() => {
    serveClubDocument()
  })

  test.afterAll(() => {
    stopServingClubDocument()
  })

  test.beforeEach(() => {
    lockClubConfig()
    clearRegistrationAttempts()
    restoreClubDocumentUrl()
  })

  test.afterEach(() => {
    restoreClubDocumentUrl()
    unlockClubConfig()
  })

  /** Submit through the public endpoint, the way an applicant does. */
  const submit = async (
    request: any,
    overrides: Record<string, unknown> = {},
  ): Promise<{ id: string; iban: string; email: string; firstName: string; lastName: string }> => {
    const secret = `secret-${randomUUID()}`
    configureSelfRegistration(secret)

    const unique = randomUUID().slice(0, 8)
    const data = {
      first_name: `Lena${unique}`,
      last_name: `Offen-${unique}`,
      email: `offen-${unique}@example.org`,
      date_of_birth: '1998-04-02',
      preferred_language: 'de',
      iban: uniqueIban(),
      ...overrides,
    }

    const response = await request.post(`${API}/public/registrations`, { data: { secret, ...data } })
    expect(response.status(), await response.text()).toBe(201)

    return {
      id: (await response.json()).id as string,
      iban: data.iban as string,
      email: data.email as string,
      firstName: data.first_name as string,
      lastName: data.last_name as string,
    }
  }

  /** The member behind an address, as the API reports them. */
  const memberByEmail = async (authenticatedRequest: any, email: string) => {
    const response = await authenticatedRequest.get(
      `${API}/admin/members?search=${encodeURIComponent(email)}&per_page=5`,
    )
    expect(response.status()).toBe(200)
    const matches = ((await response.json()).data ?? []).filter((m: any) => m.email === email)
    expect(matches, `exactly one member at ${email}`).toHaveLength(1)

    return matches[0]
  }

  test('a registration accepted without a signature becomes SEPA-valid once the date is recorded', async ({
    page,
    request,
    authenticatedRequest,
  }) => {
    const registration = await submit(request)
    const inbox = new RegistrationsPage(page)
    const members = new MembersPage(page)

    // 1. Accept ahead of the paper.
    await inbox.open()
    await inbox.expectRowVisible(registration.id)
    await inbox.openApproval(registration.id)

    // The attested approval beside it is still locked, and the consequence of
    // the other way in is spelled out before the click.
    await inbox.expectAttestedApprovalLocked()
    await inbox.expectUnsignedAcceptanceExplained()

    const approved = await inbox.acceptWithoutSignature()
    expect(approved.status()).toBe(201)

    // 2. It lands on the member, who is on the roster with a SEPA gap.
    await expect(page).toHaveURL(/\/members\?search=/)
    const created = await memberByEmail(authenticatedRequest, registration.email)
    expect(created.is_sepa_valid).toBe(false)
    expect(created.mandate_signed_at).toBeNull()
    await members.expectMemberGapVisible(created.id, 'sepa')

    // …and is gone from the inbox.
    expect((await authenticatedRequest.get(`${API}/admin/registrations/${registration.id}`)).status()).toBe(404)

    // 3. The paper comes back signed: record the date on the member.
    await members.openEditModalForMember(created.id)
    await members.expectFormModalVisible()
    expect(await members.setMandateSignatureDate('28.11.2026')).toBe('2026-11-28')
    await members.submitForm()
    await members.expectFormModalHidden()
    expect(await members.getErrorMessage()).toBeNull()

    // 4. That is what unlocks them — on the screen and in the database.
    await members.expectMemberGapHidden(created.id, 'sepa')
    const signed = await memberByEmail(authenticatedRequest, registration.email)
    expect(signed.mandate_signed_at).toBe('2026-11-28')
    expect(signed.is_sepa_valid).toBe(true)
    // The mandate in hand was completed; the reference on the paper stands.
    expect(signed.mandate_reference).toBe(created.mandate_reference)
  })

  test('"Alle aufnehmen" accepts what is shown, leaves a possible duplicate, and says both', async ({
    page,
    request,
    authenticatedRequest,
  }) => {
    // A marker only this test's rows carry, so "all that are shown" is ours.
    const marker = `Sammel${randomUUID().slice(0, 8)}`

    // Somebody the club already has…
    const existing = await submit(request)
    const accepted = await authenticatedRequest.post(
      `${API}/admin/registrations/${existing.id}/approve`,
      { data: {} },
    )
    expect(accepted.status()).toBe(201)

    // …who submits again, beside two strangers.
    const duplicate = await submit(request, { last_name: `${marker}-C`, email: existing.email })
    const first = await submit(request, { last_name: `${marker}-A` })
    const second = await submit(request, { last_name: `${marker}-B` })

    const inbox = new RegistrationsPage(page)
    await inbox.open()
    await inbox.search(marker)
    await inbox.expectRowCount(3)

    // The confirmation states the count — of what will actually be accepted —
    // what the members will be unable to do, and what happens to duplicates.
    await inbox.openAcceptAll()
    const confirmation = await inbox.acceptAllConfirmationText()
    expect(confirmation.count).toContain('2')
    expect(confirmation.unsigned).not.toBe('')
    expect(confirmation.duplicates).toContain('1')

    const response = await inbox.confirmAcceptAll()
    expect(response.status()).toBe(200)
    const result = await response.json()

    // Explicit ids, and exactly the rows that were on screen.
    expect(response.request().postDataJSON().ids.sort()).toEqual([duplicate.id, first.id, second.id].sort())
    expect(result.accepted.map((entry: any) => entry.registration_id).sort()).toEqual(
      [first.id, second.id].sort(),
    )
    expect(result.skipped).toEqual([
      { registration_id: duplicate.id, reason: 'registration_possible_duplicate' },
    ])

    // The page says how many, and why one was left — in the admin's language.
    await inbox.expectBatchResult({
      accepted: 2,
      skipped: { count: 1, reason: 'registration_possible_duplicate' },
    })

    // The queue now holds only the duplicate.
    await inbox.expectRowCount(1)
    await inbox.expectRowVisible(duplicate.id)
    await inbox.expectRowGone(first.id)
    await inbox.expectRowGone(second.id)

    // And the two strangers are members who cannot buy yet.
    for (const stranger of [first, second]) {
      const created = await memberByEmail(authenticatedRequest, stranger.email)
      expect(created.is_sepa_valid).toBe(false)
      expect(created.mandate_signed_at).toBeNull()
    }
  })

  test('the open mandates print blank by default, and in full with the club key', async ({
    page,
    request,
    authenticatedRequest,
  }) => {
    const registration = await submit(request)
    const accepted = await authenticatedRequest.post(
      `${API}/admin/registrations/${registration.id}/approve`,
      { data: {} },
    )
    expect(accepted.status()).toBe(201)

    const members = new MembersPage(page)
    await page.goto('/members')
    await members.expectPageVisible()

    // ── blank ──────────────────────────────────────────────────────────────
    await members.openPrintMandatesDialog()
    await members.expectPrintDialogAsksForNoCredential()

    const blank = await members.submitPrintMandates()

    expect(blank.subarray(0, 5).toString('latin1')).toBe('%PDF-')
    // A whole number of club documents: every member gets every page.
    expect(pdfPageCount(blank)).toBeGreaterThanOrEqual(TEMPLATE_PAGES)
    expect(pdfPageCount(blank) % TEMPLATE_PAGES).toBe(0)

    const blankText = pdfText(blank)
    expect(blankText).toContain(registration.lastName)
    expect(blankText).toContain(`endet auf ****${registration.iban.slice(-4)}`)
    expect(blankText).not.toContain(groupedIban(registration.iban))
    await members.expectNoPrintWarning()

    // ── with the full IBAN ─────────────────────────────────────────────────
    await members.openPrintMandatesDialog()
    await members.choosePrintWithFullIban()
    // No key, no button.
    await members.expectPrintConfirmDisabled()

    // The wrong sheet out of the safe: refused, and the dialog stays open with
    // the key still in it so the admin can correct rather than start over.
    await members.fillPrintKeyAndPassword(
      WRONG_PRIVATE_KEY,
      TEST_CREDENTIALS.admin.password,
      generateTotp(TEST_CREDENTIALS.totp.adminSecret),
    )
    const refused = await members.submitPrintMandatesExpectingRefusal()
    expect(refused.status()).toBe(422)
    await members.expectPrintDialogKeptOpenWithError()
    expect(await members.getPrintKeyFieldValue()).toBe(WRONG_PRIVATE_KEY)

    // The right one — typed into the dialog that is still open, with the
    // password it still holds.
    const keyed = await members.retryPrintMandatesWithKey(
      await activePrivateKey(authenticatedRequest),
      generateTotp(TEST_CREDENTIALS.totp.adminSecret),
    )

    const keyedText = pdfText(keyed)
    expect(keyedText).toContain(registration.lastName)
    expect(keyedText).toContain(groupedIban(registration.iban))
    expect(pdfPageCount(keyed) % TEMPLATE_PAGES).toBe(0)

    // Nothing about printing changed the member: still waiting for a signature.
    const response = await authenticatedRequest.get(`${API}/admin/members/${(await accepted.json()).id}`)
    expect((await response.json()).is_sepa_valid).toBe(false)
  })
})
