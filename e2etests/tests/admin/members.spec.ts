import { test, expect } from '../../fixtures/pageObjects'
import { createMemberViaPage } from '../../utils/members'

/**
 * Admin Frontend - Members Page E2E Tests (Consolidated)
 *
 * Three flow-based tests covering UC-A10 through UC-A15:
 * 1. CRUD lifecycle: create → verify persistence → edit → verify changes → search
 * 2. Filters: SEPA, card, status, sort, card-edit interaction
 * 3. Card UID validation: format checks, auto-format, duplicate detection
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
      iban: 'DE89370400440532013050',
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
    expect(await authenticatedMembersPage.getFormIbanValue()).toBe(createData.iban.toUpperCase())
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
      iban: 'DE89370400440532013099',
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
    expect(await authenticatedMembersPage.getFormIbanValue()).toBe(editData.iban.toUpperCase())
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
      { data: { is_active: false } }
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

    // ── Sorting: created date column ────────────────────────────────────
    await authenticatedMembersPage.expectCreatedColumnHeaderVisible()
    const dateBefore = await authenticatedMembersPage.getMemberCreatedDateAtRowIndex(0)
    expect(dateBefore).toMatch(/^\d{2}\.\d{2}\.\d{4}$/)

    await authenticatedMembersPage.clickCreatedColumnHeader()
    const dateAfter = await authenticatedMembersPage.getMemberCreatedDateAtRowIndex(0)
    expect(dateAfter).toMatch(/^\d{2}\.\d{2}\.\d{4}$/)

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
      `Dup2${ts}`, 'Test', 'DE89370400440532013001', '2024-01-15',
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
})
