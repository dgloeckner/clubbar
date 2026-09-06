/**
 * The member's own ceiling, typed into the member form (#563, ADR-0047).
 *
 * `tests/api/member-credit-limit.spec.ts` proves the API keeps NULL, 0 and a
 * value apart. This file proves the *form* does — which is a different claim,
 * because the form is where the three states are typed, and two of them look
 * identical on screen until somebody decides what an empty input means.
 *
 * Every assertion about what was stored is made against the API, never against
 * the input the test just typed into: a field can hold "" and still have sent
 * `0`, and that difference is the whole feature. The UI is checked too — the
 * value must come *back* — but it is never the proof.
 *
 * Patterns: 001 (a member created per test, unique by timestamp), 003 (found by
 * its own id), 005 (test IDs), 006 (page object), 008 (expect, never
 * try-catch), 009 (one flow: create → edit → clear → zero).
 */

import { expect, test } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'

const API_BASE = 'http://localhost:8080/api'

/** What the API holds for this member, which is the only thing that counts. */
async function storedLimit(
  request: { get: (url: string) => Promise<{ json: () => Promise<any> }> },
  memberId: string,
): Promise<number | null | undefined> {
  const member = await (await request.get(`${API_BASE}/admin/members/${memberId}`)).json()
  return (member.data ?? member).credit_limit_cents
}

/** The id of the member this form just created, found by the name it was given. */
async function idByLastName(
  request: { get: (url: string) => Promise<{ json: () => Promise<any> }> },
  lastName: string,
): Promise<string> {
  const body = await (
    await request.get(`${API_BASE}/admin/members?search=${encodeURIComponent(lastName)}&per_page=5`)
  ).json()
  const rows = body.data ?? body.members ?? []
  const match = rows.find((m: { last_name: string }) => m.last_name === lastName)
  expect(match, `member ${lastName} should exist after the form saved it`).toBeDefined()
  return match.id
}

test.describe('The credit limit field on the member form', () => {
  test('round-trips a value, and clearing it stores NULL rather than 0', async ({
    authenticatedRequest,
    page,
  }) => {
    const ts = Date.now()
    const lastName = `Limitform${ts}`
    const members = new MembersPage(page)
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await members.expectPageVisible()

    // ── 1. A new member's field is empty, and offers the club's figure ─────
    await members.openCreateModal()
    await members.expectFormModalVisible()
    expect(await members.getCreditLimit()).toBe('')

    // The placeholder is what tells an admin which number an empty field means:
    // the club's, formatted in euros. *Which* number is not pinned here — the
    // acceptance flow owns that setting and may be moving it while this runs —
    // so this asserts the field offers a figure at all, and the flow spec,
    // where the value is deterministic, asserts it is the right one.
    expect(await members.getCreditLimitPlaceholder()).toMatch(/\d/)

    // ── 2. Typed, saved, and stored in cents ──────────────────────────────
    await members.fillMemberForm(
      `Ada${ts}`,
      lastName,
      'DE89370400440532013000',
      '2025-01-01',
      `limitform-${ts}@test.com`,
      'de',
    )
    await members.setCreditLimit('250.00')
    await members.submitForm()
    await members.expectFormModalHidden()

    const memberId = await idByLastName(authenticatedRequest, lastName)
    expect(await storedLimit(authenticatedRequest, memberId)).toBe(25_000)

    // ── 3. It comes back into the form as it was typed ────────────────────
    await members.reopenMemberForm(`Ada${ts}`)
    expect(await members.getCreditLimit()).toBe('250.00')

    // ── 4. Cleared means "follow the club", never "unlimited" ─────────────
    await members.setCreditLimit('')
    await members.submitForm()
    await members.expectFormModalHidden()
    // The distinction this whole test exists for: NULL, not 0.
    expect(await storedLimit(authenticatedRequest, memberId)).toBeNull()

    // …and the emptied field is what the form shows next time.
    await members.reopenMemberForm(`Ada${ts}`)
    expect(await members.getCreditLimit()).toBe('')

    // ── 5. A typed zero is a decision, and survives as one ────────────────
    // The two look-alike states must never read the same on screen: an empty
    // field says the club's number applies, a zero says no number does. Since
    // #830 the empty state says it through the placeholder — the permanent
    // helper paragraph moved behind the label's info icon — and the zero
    // state, which looks like an ordinary number and means the opposite of
    // one, keeps a line of its own.
    await members.expectCreditLimitHelperHidden()
    expect(await members.getCreditLimitPlaceholder()).toMatch(/\d/)

    await members.setCreditLimit('0')
    const zeroHelper = await members.getCreditLimitHelperText()
    expect(zeroHelper).toContain('0')

    await members.submitForm()
    await members.expectFormModalHidden()
    expect(await storedLimit(authenticatedRequest, memberId)).toBe(0)

    // A stored 0 must not come back as an empty field — that would read as
    // "follow the club" and the next save would silently mean it.
    await members.reopenMemberForm(`Ada${ts}`)
    expect(await members.getCreditLimit()).toBe('0.00')

    // ── 6. A negative ceiling is refused by the form ──────────────────────
    // Refused *here*, beside the field, rather than by the 422 the API would
    // also return — which is the point of validating it client-side at all.
    await members.setCreditLimit('-5')
    await members.submitForm()
    await members.expectCreditLimitRejected()
    await members.expectFormModalVisible()
    await members.cancelForm()

    // Refused means nothing was written.
    expect(await storedLimit(authenticatedRequest, memberId)).toBe(0)

    // Housekeeping: this member carries no tab, but leaving it active adds a
    // row to every roster count for the rest of the run.
    await authenticatedRequest.patch(`${API_BASE}/admin/members/${memberId}`, {
      data: { is_active: false },
    })
  })
})
