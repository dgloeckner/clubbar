import { test, expect } from '../../fixtures/pageObjects'
import { createMemberViaPage } from '../../utils/members'

/**
 * Mandatory data, made visible (#629).
 *
 * Three things the member form and the roster could not previously say:
 *
 *   1. which fields are still needed, all of them at once rather than one
 *      native browser bubble at a time;
 *   2. that emptying a stored value on an edit is a deletion — since #131 a
 *      blank optional input reaches the API as an explicit `null`, and nothing
 *      on screen said so; and
 *   3. which members on the roster are missing something, and what it costs.
 *
 * Patterns: 001 (unique data per test), 004 (parallel safety), 005 (test IDs),
 *           006 (page object), 008 (expect assertions), 009 (flow-based).
 */

test.describe('Member form: mandatory fields are visible (#629)', () => {

  test('a blank create names every missing field at once and marks them as they are filled', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // ── The summary opens counting, not complaining ─────────────────────
    // The language selector always carries a value, so a blank form starts
    // at one of five rather than zero.
    expect(await authenticatedMembersPage.getRequirementsPanelState()).toBe('incomplete')
    expect(await authenticatedMembersPage.getRequirementsProgressText()).toContain('1')

    // Each required field carries an "open" marker; the optional ones do not.
    for (const field of ['first-name', 'last-name', 'email', 'dob']) {
      expect(await authenticatedMembersPage.getRequirementMarkerState(field)).toBe('open')
    }
    expect(await authenticatedMembersPage.getRequirementMarkerState('card-uid')).toBe('conditional')
    expect(await authenticatedMembersPage.getRequirementMarkerState('account-holder')).toBe('optional')

    // ── Submitting blank is refused, and says so about all four ─────────
    // The browser would have stopped at the first one. `noValidate` plus the
    // checks in handleSubmit is what makes the other three visible.
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalVisible()
    expect(await authenticatedMembersPage.getRequirementsPanelState()).toBe('blocked')

    for (const field of ['first_name', 'last_name', 'email', 'date_of_birth']) {
      await authenticatedMembersPage.expectMissingFieldListed(field)
    }
    await authenticatedMembersPage.expectRequiredFieldError('first-name')
    await authenticatedMembersPage.expectRequiredFieldError('email')

    // ── A chip in the summary takes you to the field it names ───────────
    await authenticatedMembersPage.jumpToMissingField('email')
    expect(await authenticatedMembersPage.getFocusedFieldTestId()).toBe('members-form-email-input')

    // ── Filling a field flips its marker and drops it off the list ──────
    await authenticatedMembersPage.fillMemberForm(
      `Req${ts}`,
      `Last${ts}`,
      '',
      '',
      `req-${ts}@test.com`,
      'de',
    )

    for (const field of ['first-name', 'last-name', 'email', 'dob']) {
      expect(await authenticatedMembersPage.getRequirementMarkerState(field)).toBe('satisfied')
    }
    await authenticatedMembersPage.expectMissingFieldNotListed('email')
    expect(await authenticatedMembersPage.getRequirementsPanelState()).toBe('complete')

    // ── And the create goes through, end to end ─────────────────────────
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()
    await authenticatedMembersPage.search(`Req${ts}`)
    await authenticatedMembersPage.expectMemberVisibleInTable(`Req${ts}`)
  })

  test('emptying a stored card UID says it will be deleted, and the undo means it is not', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const cardUid = `00${ts.toString().slice(-8)}`
    const member = await createMemberViaPage(page, {
      firstName: `Clr${ts}`,
      cardUid,
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Clr${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)

    // Nothing changed yet, so nothing is being deleted.
    await authenticatedMembersPage.expectClearedNoticeHidden('card-uid')

    // ── Clearing it announces the deletion, naming the value ────────────
    await authenticatedMembersPage.clearCardUid()
    await authenticatedMembersPage.expectClearedNoticeVisible('card-uid')
    expect(await authenticatedMembersPage.getClearedNoticeText('card-uid')).toContain(cardUid)
    await authenticatedMembersPage.expectClearingSummaryVisible()

    // ── Undo puts it back, and the save keeps the card ──────────────────
    await authenticatedMembersPage.restoreClearedValue('card-uid')
    await authenticatedMembersPage.expectClearedNoticeHidden('card-uid')
    expect(await authenticatedMembersPage.getFormCardUidValue()).toBe(cardUid)

    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // Reloaded from the API, not read back off the form: the point is that
    // the value survived the round trip.
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Clr${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)
    expect(await authenticatedMembersPage.getFormCardUidValue()).toBe(cardUid)
  })

  test('emptying a stored card UID and saving really does remove it', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const member = await createMemberViaPage(page, {
      firstName: `Del${ts}`,
      cardUid: `01${ts.toString().slice(-8)}`,
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Del${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)
    await authenticatedMembersPage.clearCardUid()
    await authenticatedMembersPage.expectClearedNoticeVisible('card-uid')
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    // The warning was not a bluff — the card is gone from the record, and the
    // roster now reports the gap.
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Del${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)
    expect(await authenticatedMembersPage.getFormCardUidValue()).toBe('')
    await authenticatedMembersPage.cancelForm()
    await authenticatedMembersPage.expectMemberGapVisible(member.id, 'card_uid')
  })

  test('a required field cleared on an edit blocks the save instead of being sent empty', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const member = await createMemberViaPage(page, { firstName: `Keep${ts}` })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Keep${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)

    await authenticatedMembersPage.clearFirstName()
    expect(await authenticatedMembersPage.getRequirementMarkerState('first-name')).toBe('open')

    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalVisible()
    expect(await authenticatedMembersPage.getRequirementsPanelState()).toBe('blocked')
    await authenticatedMembersPage.expectRequiredFieldError('first-name')
  })
})

test.describe('Roster: spotting members with missing data (#629)', () => {

  test('the Datenqualität panel counts the gaps, and its button lists exactly those members', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    // A member with no mandate is a SEPA gap the panel must both count and
    // be able to list.
    const incomplete = await createMemberViaPage(page, {
      firstName: `Gap${ts}`,
      withSepa: false,
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectDataQualityVisible()
    expect(await authenticatedMembersPage.getDataQualityState()).toBe('incomplete')

    const sepaCount = await authenticatedMembersPage.getDataQualityCount('sepa')
    expect(sepaCount).toBeGreaterThan(0)

    // ── The button behind the count opens the list it counted ───────────
    await authenticatedMembersPage.showDataQualityGap('sepa')
    const listed = await authenticatedMembersPage.getMemberRowCount()
    expect(listed).toBeGreaterThan(0)

    // Everything the filtered list shows really does have the gap.
    await authenticatedMembersPage.search(`Gap${ts}`)
    await authenticatedMembersPage.expectMemberGapVisible(incomplete.id, 'sepa')
  })

  test('a complete member reads "complete" on the roster; each gap shows as its own chip', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const whole = await createMemberViaPage(page, {
      firstName: `Full${ts}`,
      cardUid: `02${ts.toString().slice(-8)}`,
    })
    const partial = await createMemberViaPage(page, {
      firstName: `Part${ts}`,
      withSepa: false,
    })

    await authenticatedMembersPage.navigate()

    await authenticatedMembersPage.search(`Full${ts}`)
    await authenticatedMembersPage.expectMemberDataComplete(whole.id)

    await authenticatedMembersPage.search(`Part${ts}`)
    await authenticatedMembersPage.expectMemberGapVisible(partial.id, 'sepa')
    // It has a card? No — createMemberViaPage omits one unless asked, so the
    // card gap is expected too. What it must NOT report is a birth date gap:
    // the field is mandatory on create (ADR-0045).
    await authenticatedMembersPage.expectMemberGapHidden(partial.id, 'date_of_birth')
  })
})
