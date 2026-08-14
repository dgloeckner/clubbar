import { test, expect } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'

/**
 * The Deckel column on the member list (#371).
 *
 * The roster used to name a member, their card and their SEPA state, but not
 * the one figure the treasurer opened the page for: what that member still
 * owes. It was only ever readable club-wide, in the "Offene Posten" card above
 * the table, or one member at a time in the journal.
 *
 * This is a full-stack check, not a rendering one: the transactions are booked
 * through the terminal sync endpoint, and the assertions read the amounts the
 * browser actually painted after the API answered.
 *
 * Each test scopes itself to members created under its own token surname
 * (Patterns 001 and 004).
 */
test.describe('Members list Deckel column', () => {
  function deckelToken(): string {
    return `Dcu${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`.replace(/[^A-Za-z0-9]/g, '')
  }

  test('shows each member what they still owe, and sorts the list by it', async ({
    page,
    testTransactions,
  }) => {
    const membersPage = new MembersPage(page)
    const token = deckelToken()

    const big = await testTransactions.createMember('DeckelUiBig', token)
    const small = await testTransactions.createMember('DeckelUiSmall', token)
    const none = await testTransactions.createMember('DeckelUiNone', token)

    await testTransactions.createSyncTransaction(big.id, 4250, 'Deckel column big')
    await testTransactions.createSyncTransaction(small.id, 1250, 'Deckel column small')

    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await membersPage.expectBalanceColumnHeaderVisible()

    await membersPage.search(token)
    expect(await membersPage.getMemberRowCount()).toBe(3)

    // The amounts came back from the API and were formatted for the admin's
    // locale, so the assertion is on the money rather than on one spelling of it.
    expect(parseCurrencyToCents(await membersPage.getMemberBalance(big.id))).toBe(4250)
    expect(parseCurrencyToCents(await membersPage.getMemberBalance(small.id))).toBe(1250)
    // A member who owes nothing reads "0,00 €", never a blank cell: blank
    // would say "not known", which is a different and much worse claim.
    expect(parseCurrencyToCents(await membersPage.getMemberBalance(none.id))).toBe(0)

    // Clicking the header has to reach the query — the header arrow and the
    // row order disagreeing is the #125 regression this column must not repeat.
    await membersPage.clickBalanceColumnHeader('balance_asc')
    expect((await membersPage.getMemberBalances()).map(parseCurrencyToCents)).toEqual([0, 1250, 4250])

    await membersPage.clickBalanceColumnHeader('balance_desc')
    expect((await membersPage.getMemberBalances()).map(parseCurrencyToCents)).toEqual([4250, 1250, 0])
  })

  test('a settlement empties the Deckel it collected', async ({ page, testTransactions }) => {
    const membersPage = new MembersPage(page)
    const token = deckelToken()

    const member = await testTransactions.createMember('DeckelUiSettled', token)
    const transactionId = await testTransactions.createSyncTransaction(member.id, 3300, 'Deckel column settled')

    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await membersPage.search(token)
    expect(parseCurrencyToCents(await membersPage.getMemberBalance(member.id))).toBe(3300)

    await testTransactions.createSettlement([transactionId])

    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await membersPage.search(token)
    // Collected is not owed. Without this the column would grow forever and
    // become the lifetime total #83 deleted for exactly that reason.
    expect(parseCurrencyToCents(await membersPage.getMemberBalance(member.id))).toBe(0)
  })
})

/** Parse a rendered currency string ("1.234,56 €" / "€1,234.56") to cents. */
function parseCurrencyToCents(text: string): number {
  const match = text.match(/[\d.,]+/)
  if (!match) return 0
  // Whichever separator comes last is the decimal one; the other groups digits.
  const raw = match[0]
  const lastComma = raw.lastIndexOf(',')
  const lastDot = raw.lastIndexOf('.')
  const decimalSeparator = lastComma > lastDot ? ',' : '.'
  const groupSeparator = decimalSeparator === ',' ? '.' : ','
  const normalized = raw.split(groupSeparator).join('').replace(decimalSeparator, '.')
  return Math.round(parseFloat(normalized) * 100)
}
