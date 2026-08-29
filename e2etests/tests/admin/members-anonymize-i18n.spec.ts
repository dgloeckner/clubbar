import { test, expect } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'

/**
 * The refusal an admin actually reads (#757).
 *
 * The 409 that stops a GDPR erasure is the whole point of the interaction: it
 * is the only thing telling the admin what to settle before they can try
 * again. It used to arrive as the backend's own English sentence —
 * "Cannot anonymize: outstanding balance of €7.50" — rendered verbatim on a
 * panel that is German by default, with the amount formatted the English way
 * too.
 *
 * Full stack on purpose: the terminal books the tab, the API refuses, and the
 * assertions read both what came back on the wire and what the browser
 * painted. Scoped to a member created under its own token surname
 * (Patterns 001 and 004).
 */
test.describe('Anonymize refusals speak the admin’s language', () => {
  test('an outstanding tab is refused in German, with the amount formatted for the reader', async ({
    page,
    testTransactions,
  }) => {
    const token = `AnonI18n${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
      .replace(/[^A-Za-z0-9]/g, '')
    const member = await testTransactions.createMember('AnonI18n', token)
    await testTransactions.createSyncTransaction(member.id, 750, 'Outstanding tab')

    const membersPage = new MembersPage(page)
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await membersPage.search(token)
    await membersPage.expectMemberVisibleInTable(token)

    const refusal = page.waitForResponse(
      (resp) => resp.url().includes(`/admin/members/${member.id}/anonymize`) && resp.status() === 409
    )
    await membersPage.clickAnonymizeButtonForMember(member.id)
    await membersPage.expectAnonymizeConfirmVisible()
    await membersPage.confirmAnonymize()

    // The reason and the amount travel separately: the sentence belongs to the
    // panel, the value to the backend.
    const body = await (await refusal).json()
    expect(body.reason).toBe('member_balance_outstanding')
    expect(body.params).toEqual({ balance_cents: 750 })

    await membersPage.expectErrorMessageVisible()
    const shown = await membersPage.getErrorMessage()
    expect(shown).toContain('Anonymisierung nicht möglich')
    expect(shown).toContain('7,50')
    // Neither the English sentence nor the English formatting survives.
    expect(shown).not.toContain('Cannot anonymize')
    expect(shown).not.toContain('€7.50')

    // Refused means refused: the member is still on the list, PII intact.
    await membersPage.expectMemberVisibleInTable(token)
  })
})
