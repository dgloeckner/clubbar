import { test, expect } from '../../fixtures/pageObjects'
import { createMemberViaPage } from '../../utils/members'

/**
 * The member dialog's status strip (#830).
 *
 * Before it, the dialog answered "can this member use the Clubbar?" with four
 * separate indicators that could each be green for a different reason, and put
 * *Speichern* about 800px below the fold on a 900px screen. What has to hold
 * end to end is that the strip is not decoration:
 *
 *   1. a tile turns from a gap to green because a *field* was filled, and the
 *      roster then agrees the gap is closed — the dialog and the Datenqualität
 *      panel must not be able to disagree about what "incomplete" means;
 *   2. the link inside a tile actually moves the caret to the field it names;
 *   3. the tile previews the save (#392) rather than reporting the load; and
 *   4. Speichern is on screen when the dialog opens, and stays there.
 *
 * Patterns: 001 (unique data per test), 004 (parallel safety), 005 (test IDs),
 *           006 (page object), 008 (expect assertions), 009 (flow-based).
 */

test.describe('Member dialog: the status strip (#830)', () => {

  test('a Terminal gap names the field, jumps to it, and turns green once it is filled', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const cardUid = `0A${ts.toString().slice(-8)}`
    // No card: the member can be billed and mailed but cannot buy anything.
    const member = await createMemberViaPage(page, { firstName: `Strip${ts}` })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Strip${ts}`)
    // The roster's own verdict, before the dialog is opened at all.
    await authenticatedMembersPage.expectMemberGapVisible(member.id, 'card_uid')

    await authenticatedMembersPage.openEditModalForMember(member.id)

    // ── The strip states the outcome, not the field list ────────────────
    expect(await authenticatedMembersPage.getStatusTileTone('terminal')).toBe('gap')
    expect(await authenticatedMembersPage.getStatusTileTone('sepa')).toBe('ok')
    expect(await authenticatedMembersPage.getStatusTileTone('reachable')).toBe('ok')

    // Every required field is present, so the summary is green even while a
    // tile is not: they answer different questions.
    expect(await authenticatedMembersPage.getRequiredSummaryTone()).toBe('success')

    // ── The gap names its fix, and the link is a real jump ──────────────
    await authenticatedMembersPage.expectStatusGapListed('card_uid')
    await authenticatedMembersPage.jumpToStatusGap('card_uid')
    expect(await authenticatedMembersPage.getFocusedFieldTestId()).toBe('member-form-card-uid')

    // ── Filling the field it named turns the tile green ─────────────────
    await authenticatedMembersPage.fillCardUid(cardUid)
    expect(await authenticatedMembersPage.getStatusTileTone('terminal')).toBe('pending')
    await authenticatedMembersPage.expectStatusGapNotListed('card_uid')

    // ── And the roster agrees, after a real round trip ──────────────────
    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Strip${ts}`)
    await authenticatedMembersPage.expectMemberGapHidden(member.id, 'card_uid')

    await authenticatedMembersPage.openEditModalForMember(member.id)
    expect(await authenticatedMembersPage.getStatusTileTone('terminal')).toBe('ok')
  })

  test('removing the bank details turns the SEPA tile red before the save, and offers the way back', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const member = await createMemberViaPage(page, { firstName: `Sepa${ts}` })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Sepa${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)

    expect(await authenticatedMembersPage.getStatusTileTone('sepa')).toBe('ok')

    // ── The tile previews the submit, it does not report the load (#392) ─
    await authenticatedMembersPage.removeStoredIban()
    await authenticatedMembersPage.expectIbanRemovalPendingVisible()
    expect(await authenticatedMembersPage.getStatusTileTone('sepa')).toBe('losing')
    // Nothing is saved yet, so the strip has to offer the undo rather than
    // simply announcing the loss.
    await authenticatedMembersPage.expectStatusGapListed('iban')

    await authenticatedMembersPage.undoRemoveStoredIban()
    await authenticatedMembersPage.expectIbanRemovalPendingHidden()
    expect(await authenticatedMembersPage.getStatusTileTone('sepa')).toBe('ok')
  })

  test('the dialog opens with Speichern on screen and keeps it there while the form scrolls', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const member = await createMemberViaPage(page, { firstName: `Fold${ts}` })

    // 1440x900 is the screen the acceptance criterion names: the old dialog was
    // ~1750px tall inside one scrolling box, so Speichern was below the fold
    // the moment it opened.
    await page.setViewportSize({ width: 1440, height: 900 })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Fold${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)

    await authenticatedMembersPage.expectFooterVisible()
    await authenticatedMembersPage.expectSubmitButtonInViewport()

    // The body scrolls; the footer does not go with it.
    await authenticatedMembersPage.scrollFormToBottom()
    await authenticatedMembersPage.expectSubmitButtonInViewport()

    // An untouched form says so — the fact that decides between Speichern and
    // Abbrechen, and the only place that knows it.
    expect(await authenticatedMembersPage.getChangeCountText()).toMatch(/Keine|No changes/i)
    await authenticatedMembersPage.fillCardUid(`0B${ts.toString().slice(-8)}`)
    expect(await authenticatedMembersPage.getChangeCountText()).toMatch(/1/)
  })

  test('the helper text that used to sit under a field is behind its info icon', async ({
    authenticatedMembersPage,
    page,
  }) => {
    const ts = Date.now()
    const member = await createMemberViaPage(page, { firstName: `Info${ts}` })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.search(`Info${ts}`)
    await authenticatedMembersPage.openEditModalForMember(member.id)

    // The short form is the placeholder; the long form is one click away, and
    // is not on screen until it is asked for.
    const text = await authenticatedMembersPage.openFieldInfo('account-holder')
    expect(text.length).toBeGreaterThan(20)
  })
})
