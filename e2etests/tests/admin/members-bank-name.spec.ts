import { test, expect } from '../../fixtures/pageObjects'

/**
 * Admin Frontend - Bank Name Display E2E Tests
 *
 * Verifies that the bank name (resolved from IBAN via Bundesbank BLZ lookup)
 * is displayed below the IBAN field in the member form. The bank name must
 * update live when the admin changes the IBAN.
 *
 * Covers: member creation, member editing, and IBAN change scenarios.
 *
 * Patterns: 001 (test data isolation), 005 (test IDs), 006 (page object),
 *           008 (expect assertions)
 */

test.describe('Bank Name Display', () => {

  test('shows bank name for valid German IBAN during member creation, updates on IBAN change', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // ── No bank name shown for empty IBAN ───────────────────────────
    await authenticatedMembersPage.expectBankNameHidden()

    // ── Enter valid German IBAN → bank name appears ─────────────────
    const ibanInput = page.getByTestId('members-form-iban-input')
    await ibanInput.fill('DE89370400440532013000')

    // Bank name resolved via debounced API call — wait for it
    await authenticatedMembersPage.expectBankNameVisible()
    await authenticatedMembersPage.expectBankNameContains('Commerzbank')

    // ── Change IBAN to a different bank → bank name updates ─────────
    await ibanInput.fill('DE27100777770209299700')
    await authenticatedMembersPage.expectBankNameContains('norisbank')

    // ── Change to invalid IBAN → bank name disappears ───────────────
    await ibanInput.fill('DE00000000000000000000')
    await authenticatedMembersPage.expectBankNameHidden()

    // ── Change back to valid IBAN → bank name reappears ─────────────
    await ibanInput.fill('DE02100100100006820101')
    await authenticatedMembersPage.expectBankNameContains('Postbank')

    // ── Non-German IBAN → no bank name shown ────────────────────────
    await ibanInput.fill('AT611904300234573201')
    await authenticatedMembersPage.expectBankNameHidden()

    await authenticatedMembersPage.cancelForm()
  })

  test('shows bank name when editing existing member with IBAN', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()

    // ── Create member with known IBAN via form ──────────────────────
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.fillMemberForm(
      `BankEdit${ts}`, `Last${ts}`, 'DE89370400440532013000', '2025-01-01',
      `bank-edit-${ts}@test.com`, 'de',
    )
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // ── Open edit modal → bank name already shown ───────────────────
    await authenticatedMembersPage.search(`BankEdit${ts}`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`BankEdit${ts}`)
    await authenticatedMembersPage.clickEditButtonForMember(`BankEdit${ts}`)
    await authenticatedMembersPage.expectFormModalVisible()

    // The IBAN field starts empty and stays that way unless it is retyped: the
    // stored value is sealed and never returned (ADR-0036), so leaving the
    // field alone keeps the account (#392). What the form can show is the last
    // four characters and the bank resolved when the IBAN was written.
    expect(await authenticatedMembersPage.getFormIbanValue()).toBe('')
    await authenticatedMembersPage.expectStoredIbanContains('****3000')
    await authenticatedMembersPage.expectStoredIbanContains('Commerzbank')

    // ── Change IBAN in edit mode → bank name updates ────────────────
    const ibanInput = page.getByTestId('members-form-iban-input')
    await ibanInput.fill('DE02370502990000684712')
    await authenticatedMembersPage.expectBankNameContains('Kreissparkasse Köln')

    await authenticatedMembersPage.cancelForm()
  })
})
