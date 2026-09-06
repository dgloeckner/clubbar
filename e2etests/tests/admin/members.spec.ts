import { test, expect } from '../../fixtures/pageObjects'
import { createMemberViaPage } from '../../utils/members'
import { csrfHeaders } from '../../utils/csrf'

/**
 * Admin Frontend - Members Page E2E Tests (Consolidated)
 *
 * Four flow-based tests covering UC-A10 through UC-A15:
 * 1. CRUD lifecycle: create → verify persistence → edit → verify changes → search
 * 2. Filters: SEPA, card, status, sort, card-edit interaction
 * 3. Card UID validation: format checks, auto-format, duplicate detection
 * 4. IBAN validation: inline indicator, checksum rejection, normalization, valid submit
 *
 * Patterns: 001 (test data isolation), 004 (parallel safety),
 *           005 (test IDs), 006 (page object), 007 (fixtures), 008 (expect assertions)
 */

test.describe('Admin Members Page', () => {

  test('member CRUD lifecycle: create with all fields, verify persistence, edit, verify changes', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()

    // ── CREATE with all fields ──────────────────────────────────────────
    const createData = {
      firstName: `CNew${ts}`,
      lastName: `Last${ts}`,
      email: `cnew-${ts}@test.com`,
      iban: 'DE75512108001245126199',
      mandateDate: '2025-02-01',
      accountHolder: `Holder${ts}`,
      mandateRef: `REF${ts}`,
      cardUid: `000${ts.toString().slice(-8)}`,
      dateOfBirth: '1979-11-23',
      language: 'de' as const,
    }

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    await authenticatedMembersPage.fillMemberForm(
      createData.firstName,
      createData.lastName,
      createData.iban,
      createData.mandateDate,
      createData.email,
      createData.language,
      createData.dateOfBirth,
    )
    await authenticatedMembersPage.fillAccountHolderName(createData.accountHolder)
    await authenticatedMembersPage.fillMandateReference(createData.mandateRef)
    await authenticatedMembersPage.fillCardUid(createData.cardUid)

    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // ── Verify member appears in list ───────────────────────────────────
    await authenticatedMembersPage.search(createData.firstName)
    await authenticatedMembersPage.expectMemberVisibleInTable(createData.firstName)

    // ── Verify ALL fields persisted via edit modal ──────────────────────
    await authenticatedMembersPage.clickEditButtonForMember(createData.firstName)
    await authenticatedMembersPage.expectFormModalVisible()

    expect(await authenticatedMembersPage.getFormFirstNameValue()).toBe(createData.firstName)
    expect(await authenticatedMembersPage.getFormLastNameValue()).toBe(createData.lastName)
    expect(await authenticatedMembersPage.getFormEmailValue()).toBe(createData.email)
    // Jugendschutz (ADR-0045): mandatory on create, and it has to come back on
    // the edit form — an admin who cannot see the stored date cannot notice a
    // wrong one, and a wrong one reads as a lawful refusal at the terminal.
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe(createData.dateOfBirth)
    // Overwrite-only (#392): there is no IBAN input at all, because the stored
    // IBAN is sealed and never returned (ADR-0036). The stored account takes
    // its place, and replacing it is a deliberate action.
    await authenticatedMembersPage.expectIbanInputHidden()
    await authenticatedMembersPage.expectStoredIbanContains(`****${createData.iban.slice(-4)}`)
    expect(await authenticatedMembersPage.getFormAccountHolderNameValue()).toBe(createData.accountHolder)
    expect(await authenticatedMembersPage.getFormMandateReferenceValue()).toBe(createData.mandateRef.toUpperCase())
    expect(await authenticatedMembersPage.getFormMandateDateValue()).toBe(createData.mandateDate)
    expect(await authenticatedMembersPage.getFormCardUidValue()).toBe(createData.cardUid.toUpperCase())
    expect(await authenticatedMembersPage.getFormLanguageValue()).toBe(createData.language)

    await authenticatedMembersPage.cancelForm()

    // ── EDIT several fields ─────────────────────────────────────────────
    const editData = {
      firstName: `CEdit${ts}`,
      lastName: `ELast${ts}`,
      email: `cedit-${ts}@test.com`,
      iban: 'DE27100777770209299700',
      mandateDate: '2025-01-15',
      accountHolder: `EHolder${ts}`,
      mandateRef: `EREF${ts}`,
    }

    await authenticatedMembersPage.clickEditButtonForMember(createData.firstName)
    await authenticatedMembersPage.expectFormModalVisible()

    await authenticatedMembersPage.fillMemberForm(
      editData.firstName,
      editData.lastName,
      editData.iban,
      editData.mandateDate,
      editData.email,
    )
    await authenticatedMembersPage.fillAccountHolderName(editData.accountHolder)
    await authenticatedMembersPage.fillMandateReference(editData.mandateRef)

    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // ── Verify edited member in list, original gone ─────────────────────
    await authenticatedMembersPage.search(editData.firstName)
    await authenticatedMembersPage.expectMemberVisibleInTable(editData.firstName)

    await authenticatedMembersPage.clearSearch()
    await authenticatedMembersPage.search(createData.firstName)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(createData.firstName)

    // ── Verify edits persisted via edit modal ───────────────────────────
    await authenticatedMembersPage.clearSearch()
    await authenticatedMembersPage.search(editData.firstName)
    await authenticatedMembersPage.clickEditButtonForMember(editData.firstName)
    await authenticatedMembersPage.expectFormModalVisible()

    expect(await authenticatedMembersPage.getFormFirstNameValue()).toBe(editData.firstName)
    expect(await authenticatedMembersPage.getFormLastNameValue()).toBe(editData.lastName)
    expect(await authenticatedMembersPage.getFormEmailValue()).toBe(editData.email)
    // The edit did retype the IBAN, so the replacement is what is stored now —
    // a new mandate on the new account, still shown only as its last four, and
    // once again in place of the input rather than beside it.
    await authenticatedMembersPage.expectIbanInputHidden()
    await authenticatedMembersPage.expectStoredIbanContains(`****${editData.iban.slice(-4)}`)
    expect(await authenticatedMembersPage.getFormMandateDateValue()).toBe(editData.mandateDate)

    await authenticatedMembersPage.cancelForm()
  })


  test('filters and sort: SEPA, card, status, created-date column, card-edit interaction', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const prefix = `Flt${ts}`

    // ── Setup: create 3 members via API ─────────────────────────────────
    // A: active, SEPA-valid, with card
    // B: active, SEPA-missing, no card
    // C: inactive, SEPA-valid, no card
    await createMemberViaPage(page, {
      firstName: `${prefix}A`,
      lastName: 'HasAll',
      email: `${prefix}a@t.com`,
      cardUid: `A${ts.toString().slice(-10)}`,
    })
    await createMemberViaPage(page, {
      firstName: `${prefix}B`,
      lastName: 'NoSepa',
      email: `${prefix}b@t.com`,
      withSepa: false,
    })
    const memberC = await createMemberViaPage(page, {
      firstName: `${prefix}C`,
      lastName: 'Inactive',
      email: `${prefix}c@t.com`,
    })
    // Backend ignores is_active on POST (hardcoded true) — must PATCH to deactivate
    const patchResp = await page.request.patch(
      `http://localhost:8080/api/admin/members/${memberC.id}`,
      { data: { is_active: false }, headers: await csrfHeaders(page) }
    )
    expect(patchResp.ok()).toBe(true)

    // ── Navigate and search for test prefix ─────────────────────────────
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()
    await authenticatedMembersPage.search(prefix)

    // ── SEPA filter ─────────────────────────────────────────────────────
    await authenticatedMembersPage.setSepaFilter('valid')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}A`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}B`)

    await authenticatedMembersPage.setSepaFilter('missing')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}B`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)

    await authenticatedMembersPage.setSepaFilter('all')

    // ── Card filter ─────────────────────────────────────────────────────
    await authenticatedMembersPage.setCardFilter('with')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}A`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}B`)

    await authenticatedMembersPage.setCardFilter('without')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}B`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)

    await authenticatedMembersPage.setCardFilter('all')

    // ── Status filter ───────────────────────────────────────────────────
    await authenticatedMembersPage.setStatusFilter('active')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}A`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}B`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}C`)

    await authenticatedMembersPage.setStatusFilter('inactive')
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}B`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}C`)

    await authenticatedMembersPage.setStatusFilter('all')

    // ── Sorting: created date column (#125) ──────────────────────────────
    // The header used to render a sort arrow over rows the API had ordered
    // newest-first regardless, so the parameter sent and the resulting order
    // are both asserted. (Card UID sort coverage lives at the API level in
    // admin-members-list.spec.ts — the Karten-UID column itself was removed
    // from this table.)
    await authenticatedMembersPage.expectCreatedColumnHeaderVisible()
    const dateBefore = await authenticatedMembersPage.getMemberCreatedDateAtRowIndex(0)
    expect(dateBefore).toMatch(/^\d{2}\.\d{2}\.\d{4}$/)

    await authenticatedMembersPage.clickCreatedColumnHeader('created_at_asc')
    const dates = await authenticatedMembersPage.getMemberCreatedDates()
    expect(dates.length).toBeGreaterThan(1)
    const dateKeys = dates.map((d) => d.split('.').reverse().join(''))
    expect(dateKeys).toEqual([...dateKeys].sort())

    // ── Card-edit interaction: clear card → verify filter change ────────
    await authenticatedMembersPage.clickEditButtonForMember(`${prefix}A`)
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.clearCardUid()

    const patchPromise = page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/members') &&
        resp.request().method() === 'PATCH' &&
        resp.status() === 200,
    )
    await authenticatedMembersPage.submitForm()
    await patchPromise
    await authenticatedMembersPage.expectFormModalHidden()

    // After clearing card, member A should appear in "without card"
    await authenticatedMembersPage.setCardFilter('without')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}A`)

    await authenticatedMembersPage.setCardFilter('with')
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)
  })


  test('mutations reload the list with every active filter (#92): save, status toggle, anonymize', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const prefix = `Keep${ts}`

    // ── Setup: three members via API ────────────────────────────────────
    // A: SEPA-valid, with card  → must stay hidden behind both filters below
    // B: SEPA-valid, no card    → target of the save
    // C: SEPA-missing, no card  → target of the status toggle and anonymize
    await createMemberViaPage(page, {
      firstName: `${prefix}A`,
      lastName: 'SepaCard',
      email: `${prefix}a@t.com`,
      cardUid: `B${ts.toString().slice(-10)}`,
    })
    await createMemberViaPage(page, {
      firstName: `${prefix}B`,
      lastName: 'NoCard',
      email: `${prefix}b@t.com`,
    })
    const memberC = await createMemberViaPage(page, {
      firstName: `${prefix}C`,
      lastName: 'NoSepa',
      email: `${prefix}c@t.com`,
      withSepa: false,
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()
    await authenticatedMembersPage.search(prefix)

    // Matches only the list query (GET /api/admin/members?…), never the
    // single-member GET/PATCH which carry the id in the path instead.
    const listReload = () =>
      page.waitForResponse(
        (resp) =>
          resp.url().includes('/api/admin/members?') &&
          resp.request().method() === 'GET' &&
          resp.status() === 200,
        { timeout: 15000 },
      )

    // ── 1. Editing a member keeps the card filter ───────────────────────
    await authenticatedMembersPage.setCardFilter('without')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}B`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)

    await authenticatedMembersPage.clickEditButtonForMember(`${prefix}B`)
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.fillAccountHolderName(`Holder${ts}`)

    const saveReload = listReload()
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    const saveParams = new URL((await saveReload).url()).searchParams
    expect(saveParams.get('has_card_uid')).toBe('without')
    expect(saveParams.get('search')).toBe(prefix)
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}B`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)

    // ── 2. Toggling status keeps the SEPA filter ────────────────────────
    await authenticatedMembersPage.setCardFilter('all')
    await authenticatedMembersPage.setSepaFilter('missing')
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}C`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)

    await authenticatedMembersPage.toggleStatusForMember(memberC.id)
    await authenticatedMembersPage.expectDeactivateConfirmVisible()

    const toggleReload = listReload()
    await authenticatedMembersPage.confirmDeactivate()

    const toggleParams = new URL((await toggleReload).url()).searchParams
    expect(toggleParams.get('sepa_status')).toBe('invalid')
    expect(toggleParams.get('search')).toBe(prefix)
    await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}C`)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)

    // ── 3. Anonymizing keeps the SEPA filter ────────────────────────────
    // The anonymized member drops out of the search itself (its name is
    // erased), so the surviving assertion is that A stays filtered out.
    await authenticatedMembersPage.clickAnonymizeButtonForMember(memberC.id)
    await authenticatedMembersPage.expectAnonymizeConfirmVisible()

    const anonymizeReload = listReload()
    await authenticatedMembersPage.confirmAnonymize()
    await authenticatedMembersPage.expectAnonymizeConfirmHidden()

    const anonymizeParams = new URL((await anonymizeReload).url()).searchParams
    expect(anonymizeParams.get('sepa_status')).toBe('invalid')
    expect(anonymizeParams.get('search')).toBe(prefix)
    await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)
  })


  test('card UID validation: format, auto-uppercase, duplicate inline error', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()

    // ── Client-side validation ──────────────────────────────────────────
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // Too short (< 8 hex chars) → format error
    await authenticatedMembersPage.fillCardUid('123')
    await authenticatedMembersPage.blurCardUid()
    await authenticatedMembersPage.expectCardUidFormatErrorVisible()

    // Valid (8+ hex chars) → no error
    await authenticatedMembersPage.fillCardUid('0003195661')
    await authenticatedMembersPage.blurCardUid()
    await authenticatedMembersPage.expectCardUidFormatErrorHidden()

    // Auto-uppercase + strip non-hex
    await authenticatedMembersPage.fillCardUid('abc123xyz')
    const formatted = await authenticatedMembersPage.getFormCardUidValue()
    expect(formatted).toBe('ABC123') // xyz stripped (not hex)

    await authenticatedMembersPage.cancelForm()

    // ── Server-side duplicate detection ─────────────────────────────────
    const cardUid = `DUP${ts.toString().slice(-12)}`.toUpperCase()

    // Create first member with this card_uid
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.fillMemberForm(
      `Dup1${ts}`, 'Test', 'DE89370400440532013000', '2024-01-15',
      `dup1-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.fillMandateReference(`MDUP1${ts}`)
    await authenticatedMembersPage.fillCardUid(cardUid)
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // Try second member with same card_uid
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.fillMemberForm(
      `Dup2${ts}`, 'Test', 'DE02120300000000202051', '2024-01-15',
      `dup2-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.fillMandateReference(`MDUP2${ts}`)
    await authenticatedMembersPage.fillCardUid(cardUid)
    await authenticatedMembersPage.submitForm()

    // Modal stays open with duplicate error
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.expectCardUidDuplicateErrorVisible()

    const errorText = await authenticatedMembersPage.getCardUidDuplicateErrorText()
    expect(errorText.toLowerCase()).toMatch(/(already|bereits)/)

    // Fix by changing card_uid → error clears
    await authenticatedMembersPage.fillCardUid(`000${Date.now().toString().slice(-8)}`)
    await authenticatedMembersPage.expectCardUidDuplicateErrorHidden()

    await authenticatedMembersPage.cancelForm()
  })


  test('IBAN validation: inline indicator, checksum rejection, valid IBAN accepted', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // ── Empty IBAN → no validation indicator shown ──────────────────
    await authenticatedMembersPage.expectIbanValidationHidden()

    // ── Invalid IBAN (bad checksum) → red X indicator ───────────────
    const ibanInput = authenticatedMembersPage['page'].getByTestId('members-form-iban-input')
    await ibanInput.fill('DE00000000000000000000')
    await authenticatedMembersPage.expectIbanInvalidVisible()

    // ── Try submitting invalid IBAN → form stays open, error shown ──
    await authenticatedMembersPage.fillMemberForm(
      `IVal${ts}`, `Last${ts}`, 'DE00000000000000000000', '2025-01-01',
      `ival-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.expectIbanErrorVisible()

    // ── Valid IBAN → green checkmark ─────────────────────────────────
    await ibanInput.fill('DE89370400440532013000')
    await authenticatedMembersPage.expectIbanValidVisible()
    await authenticatedMembersPage.expectIbanErrorHidden()

    // ── Pasted IBAN with spaces → normalized and valid ──────────────
    await ibanInput.fill('DE89 3704 0044 0532 0130 00')
    const normalizedValue = await authenticatedMembersPage.getFormIbanValue()
    expect(normalizedValue).toBe('DE89370400440532013000')
    await authenticatedMembersPage.expectIbanValidVisible()

    // ── Submit with valid IBAN → succeeds ────────────────────────────
    await authenticatedMembersPage.fillMemberForm(
      `IVal${ts}`, `Last${ts}`, 'DE89370400440532013000', '2025-01-01',
      `ival-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // ── Verify member created ────────────────────────────────────────
    await authenticatedMembersPage.search(`IVal${ts}`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`IVal${ts}`)
  })

  /**
   * Email is required at application level (#362): the pre-notification and
   * settlement statement emails are a contractual promise (Nutzungsordnung
   * § 7), so a member cannot be onboarded without one. The input carries
   * `required` like first/last name, so a blank submission never reaches the
   * network; the 255-char column width is not expressible in HTML5 and is
   * therefore still enforced — and surfaced — server-side.
   */
  test('email validation: required on create, rejects an over-long address', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // ── Blank email blocks submission — the input is `required` ────────
    await authenticatedMembersPage.fillMemberForm(`EReq${ts}`, `Last${ts}`, '', '')
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalVisible()

    // ── An email past the 255-char column width is rejected server-side ─
    const overlongEmail = `${'a'.repeat(250)}@test.com`
    await authenticatedMembersPage.fillMemberForm(`EReq${ts}`, `Last${ts}`, '', '', overlongEmail, 'de')
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.expectEmailErrorVisible()

    // ── A valid email allows the create to proceed ──────────────────────
    await authenticatedMembersPage.fillMemberForm(`EReq${ts}`, `Last${ts}`, '', '', `ereq-${ts}@test.com`, 'de')
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.search(`EReq${ts}`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`EReq${ts}`)
  })

  /**
   * Deactivating a member used to fire on the toggle click — on the mobile card
   * list that toggle sits right beside the name, so a mistap cut a member off
   * from the terminal silently (#130). Reactivating only restores access, so it
   * stays a single tap.
   */
  test('deactivating a member asks first; cancelling leaves the member active (#130)', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const prefix = `Deact${ts}`
    const member = await createMemberViaPage(page, {
      firstName: prefix,
      lastName: 'Toggle',
      email: `${prefix.toLowerCase()}@test.com`,
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()
    await authenticatedMembersPage.search(prefix)
    await authenticatedMembersPage.expectMemberVisibleInTable(prefix)
    expect(await authenticatedMembersPage.getMemberStatus(member.id)).toBe('active')

    // The PATCH must not go out on the click alone.
    let patchCalls = 0
    await page.route(`**/api/admin/members/${member.id}`, (route) => {
      if (route.request().method() === 'PATCH') patchCalls += 1
      return route.continue()
    })

    // ── Cancel keeps the member active ──────────────────────────────────
    await authenticatedMembersPage.toggleStatusForMember(member.id)
    await authenticatedMembersPage.expectDeactivateConfirmVisible()

    // The dialog names the member, so a mistap is recognisable as one.
    expect(await authenticatedMembersPage.getDeactivateConfirmMessage()).toContain(prefix)

    await authenticatedMembersPage.cancelDeactivate()
    expect(patchCalls).toBe(0)
    expect(await authenticatedMembersPage.getMemberStatus(member.id)).toBe('active')

    // ── Confirm deactivates, and the backend agrees ─────────────────────
    const deactivateReload = page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/members?') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
      { timeout: 15000 },
    )
    await authenticatedMembersPage.deactivateMember(member.id)
    await deactivateReload
    expect(patchCalls).toBe(1)
    expect(await authenticatedMembersPage.getMemberStatus(member.id)).toBe('inactive')

    // ── Reactivating needs no confirmation ──────────────────────────────
    const reactivateReload = page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/members?') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
      { timeout: 15000 },
    )
    await authenticatedMembersPage.reactivateMember(member.id)
    await reactivateReload
    expect(patchCalls).toBe(2)
    expect(await authenticatedMembersPage.getMemberStatus(member.id)).toBe('active')
  })

  /**
   * The overwrite-only IBAN field (#392, ADR-0036).
   *
   * A sealed IBAN cannot be read back, so the form shows the stored account in
   * place of the input and only reveals the input on request. These flows
   * assert the property that makes that safe: nothing but a deliberate
   * Change-then-save replaces the account, and nothing but Remove-then-save
   * revokes the mandate. Each verdict is taken by reopening the form, so it is
   * the persisted state that is checked, not the local one.
   */
  test('IBAN change can be abandoned: cancel keeps the stored account through a save', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    const original = 'DE89370400440532013000'
    const replacement = 'DE27100777770209299700'
    const firstName = `IbanCancel${ts}`

    await authenticatedMembersPage.createMember(
      firstName, `Last${ts}`, original, '2025-01-10', `ibancancel-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.reopenMemberForm(firstName)

    // ── The stored account stands in for the input ───────────────────
    await authenticatedMembersPage.expectIbanInputHidden()
    await authenticatedMembersPage.expectStoredIbanContains(`****${original.slice(-4)}`)

    // ── Change reveals an empty input, not a prefilled one ───────────
    await authenticatedMembersPage.beginIbanChange()
    await authenticatedMembersPage.expectIbanInputVisible()
    expect(await authenticatedMembersPage.getFormIbanValue()).toBe('')
    await authenticatedMembersPage.expectStoredIbanHidden()

    // ── Type a replacement, then take it back ────────────────────────
    await authenticatedMembersPage.fillIban(replacement)
    await authenticatedMembersPage.expectIbanValidVisible()
    await authenticatedMembersPage.cancelIbanChange()
    await authenticatedMembersPage.expectStoredIbanContains(`****${original.slice(-4)}`)

    // ── Save anyway: the abandoned IBAN must not have leaked into it ─
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.reopenMemberForm(firstName)
    await authenticatedMembersPage.expectStoredIbanContains(`****${original.slice(-4)}`)
    await authenticatedMembersPage.cancelForm()
  })

  test('IBAN change that is saved replaces the account and keeps SEPA valid', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    const original = 'DE89370400440532013000'
    const replacement = 'DE02370502990000684712'
    const firstName = `IbanSwap${ts}`

    await authenticatedMembersPage.createMember(
      firstName, `Last${ts}`, original, '2025-01-10', `ibanswap-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.reopenMemberForm(firstName)

    await authenticatedMembersPage.beginIbanChange()
    await authenticatedMembersPage.fillIban(replacement)
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.reopenMemberForm(firstName)
    await authenticatedMembersPage.expectIbanInputHidden()
    await authenticatedMembersPage.expectStoredIbanContains(`****${replacement.slice(-4)}`)
    // The mandate survived the swap, and the strip's SEPA tile says so as the
    // saved state rather than as a pending change (#830).
    expect(await authenticatedMembersPage.getStatusTileTone('sepa')).toBe('ok')
    await authenticatedMembersPage.cancelForm()
  })

  test('removing bank details is undoable, and only takes effect once saved', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    const iban = 'DE75512108001245126199'
    const firstName = `IbanDrop${ts}`

    await authenticatedMembersPage.createMember(
      firstName, `Last${ts}`, iban, '2025-01-10', `ibandrop-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.reopenMemberForm(firstName)

    // ── Queue the removal, then take it back ─────────────────────────
    await authenticatedMembersPage.removeStoredIban()
    await authenticatedMembersPage.expectIbanRemovalPendingVisible()
    await authenticatedMembersPage.expectStoredIbanHidden()
    await authenticatedMembersPage.undoRemoveStoredIban()
    await authenticatedMembersPage.expectIbanRemovalPendingHidden()
    await authenticatedMembersPage.expectStoredIbanContains(`****${iban.slice(-4)}`)

    // ── Saving after the undo leaves the account alone ───────────────
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.reopenMemberForm(firstName)
    await authenticatedMembersPage.expectStoredIbanContains(`****${iban.slice(-4)}`)

    // ── Now really remove it ─────────────────────────────────────────
    await authenticatedMembersPage.removeStoredIban()
    await authenticatedMembersPage.expectIbanRemovalPendingVisible()
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.reopenMemberForm(firstName)
    // No account left to stand in for the input, so the input is back.
    await authenticatedMembersPage.expectStoredIbanHidden()
    await authenticatedMembersPage.expectIbanInputVisible()
    await authenticatedMembersPage.cancelForm()
  })

  /**
   * The mandate reference is minted by the server when the mandate is opened
   * (ADR-0006); supplying one is the migration case. The form says so by
   * making "assigned on save" the default state and typing one an opt-in.
   */
  test('mandate reference: assigned by default, shown as a value once minted', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    const firstName = `MRef${ts}`

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // ── The standard case needs no input at all ─────────────────────
    await authenticatedMembersPage.expectMandateReferenceAutoVisible()
    await authenticatedMembersPage.expectMandateReferenceInputHidden()

    // ── Typing one is opt-in, and abandoning it returns to automatic ─
    await authenticatedMembersPage.beginMandateReferenceEntry()
    await authenticatedMembersPage.fillMandateReference(`TEMP${ts}`)
    await authenticatedMembersPage.cancelMandateReferenceEntry()
    await authenticatedMembersPage.expectMandateReferenceAutoVisible()

    await authenticatedMembersPage.fillMemberForm(
      firstName, `Last${ts}`, 'DE27100777770209299700', '2025-01-10',
      `mref-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // ── The server assigned one; it is now shown as the value it is ──
    await authenticatedMembersPage.reopenMemberForm(firstName)
    await authenticatedMembersPage.expectMandateReferenceInputHidden()
    const assigned = await authenticatedMembersPage.getFormMandateReferenceValue()
    // A UUID without hyphens, per ADR-0006 — and emphatically not the
    // abandoned TEMP value.
    expect(assigned).toMatch(/^[0-9a-f]{32}$/i)
    await authenticatedMembersPage.cancelForm()
  })
})
