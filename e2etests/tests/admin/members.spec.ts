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
    // Overwrite-only (#392): the field is blank because the stored IBAN is
    // sealed and never returned (ADR-0036). What persisted is shown beside it.
    expect(await authenticatedMembersPage.getFormIbanValue()).toBe('')
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
    // a new mandate on the new account, still shown only as its last four.
    expect(await authenticatedMembersPage.getFormIbanValue()).toBe('')
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

    // ── Sorting: created date and card UID columns (#125) ───────────────
    // Both headers used to render a sort arrow over rows the API had ordered
    // newest-first regardless, so the parameter sent and the resulting order
    // are both asserted.
    await authenticatedMembersPage.expectCreatedColumnHeaderVisible()
    const dateBefore = await authenticatedMembersPage.getMemberCreatedDateAtRowIndex(0)
    expect(dateBefore).toMatch(/^\d{2}\.\d{2}\.\d{4}$/)

    await authenticatedMembersPage.clickCreatedColumnHeader('created_at_asc')
    const dates = await authenticatedMembersPage.getMemberCreatedDates()
    expect(dates.length).toBeGreaterThan(1)
    const dateKeys = dates.map((d) => d.split('.').reverse().join(''))
    expect(dateKeys).toEqual([...dateKeys].sort())

    await authenticatedMembersPage.clickCardUidColumnHeader('card_uid_asc')
    const cardUids = await authenticatedMembersPage.getMemberCardUids()
    const withCard = cardUids.filter((uid) => uid !== '—')
    expect(withCard.length).toBeGreaterThan(0)
    expect(withCard).toEqual([...withCard].sort())
    // Cardless members are listed after every member that has one.
    expect(cardUids.slice(0, withCard.length)).toEqual(withCard)

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
})
