/**
 * The mail queue in the admin panel (#407).
 *
 * What these pin is the promise ADR-0038 rule 6 makes: delivery is best effort,
 * and best effort has to be *visible*. After a finalize, every collected member
 * has a row somebody can point at.
 *
 * And the promise rule 4 makes, which is the easier one to break by accident:
 * the page shows state and never orchestrates time. There is a test below that
 * fails if this page ever starts polling.
 *
 * Patterns: 001 (data seeded per test), 003 (rows found by their own ids),
 * 004 (parallel-safe), 005 (test IDs), 006/007 (page object via fixture),
 * 008 (expect, never try/catch).
 *
 * **Not here, and deliberately**: the retry happy path. A `failed` row cannot
 * be produced through the API on a stack with no mail transport — the drain
 * refuses to claim anything, so nothing can fail. That belongs with the Mailpit
 * chain in #409. The transition itself is covered end to end against a real
 * database and the real routes in `NotificationsHttpTest`.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { NewSettlementPage } from '../../pages/NewSettlementPage'
import { NotificationsPage } from '../../pages/NotificationsPage'
import { seedMember } from '../../utils/exclusions'
import type { APIRequestContext } from '@playwright/test'

/** Finalize a direct-debit run for one member and return its id. */
async function settle(request: APIRequestContext, memberId: string): Promise<string> {
  const info = await request.get('/api/admin/settlements/execution-date-info')
  expect(info.status()).toBe(200)
  const executionDate = (await info.json()).minimum_date

  const response = await request.post('/api/admin/settlements', {
    data: { method: 'direct_debit', member_ids: [memberId], execution_date: executionDate },
  })
  expect(response.status()).toBe(201)

  return (await response.json()).id
}

test.describe('Notifications — the mail queue', () => {
  test('a finalized settlement puts one announcement per member on the queue', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Queued',
      amounts: [450, 550],
    })
    const settlementId = await settle(authenticatedRequest, member.memberId)

    const notifications = new NotificationsPage(page)
    await notifications.navigateTo()
    await notifications.expectPageVisible()

    // Found by this member's own row rather than by position: the queue is
    // global and every other test in this file is adding to it (Pattern 003).
    const queued = await authenticatedRequest.get(
      `/api/admin/notifications?subject_id=${settlementId}`
    )
    expect(queued.status()).toBe(200)
    const rows = (await queued.json()).data
    expect(rows).toHaveLength(1)

    await notifications.expectRowVisible(rows[0].id)
    expect(await notifications.statusOf(rows[0].id)).toBe('pending')

    // The snapshot address, which is the proof of who was announced to — and
    // the thing a name alone cannot tell you when it went somewhere stale.
    // The snapshot address must match the member's own — that is the whole
    // claim the column makes.
    const stored = await (await authenticatedRequest.get(`/api/admin/members/${member.memberId}`)).json()
    expect(await notifications.recipientOf(rows[0].id)).toContain(stored.email)
    expect(await notifications.memberNameOf(rows[0].id)).toContain(member.lastName)
  })

  test('a queued message offers no retry — only a failed one can go back', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'NoRetry',
      amounts: [600],
    })
    const settlementId = await settle(authenticatedRequest, member.memberId)

    const rows = (await (
      await authenticatedRequest.get(`/api/admin/notifications?subject_id=${settlementId}`)
    ).json()).data
    expect(rows[0].is_retryable).toBe(false)

    const notifications = new NotificationsPage(page)
    await notifications.navigateTo()
    await notifications.expectNoRetryOffered(rows[0].id)
  })

  test('filtering by kind narrows the list to that kind', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Filter',
      amounts: [700],
    })
    const settlementId = await settle(authenticatedRequest, member.memberId)

    const rows = (await (
      await authenticatedRequest.get(`/api/admin/notifications?subject_id=${settlementId}`)
    ).json()).data
    const announcementId = rows[0].id

    const notifications = new NotificationsPage(page)
    await notifications.navigateTo()
    await notifications.expectRowVisible(announcementId)

    // A kind this row is not: it must disappear, which is the whole of "the
    // filter is applied server-side and the page renders what came back".
    await notifications.filterByKind('cancellation_notice')
    await notifications.expectRowAbsent(announcementId)

    await notifications.filterByKind('sepa_prenotification')
    await notifications.expectRowVisible(announcementId)
  })

  test('filtering by status narrows the list to that status', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Status',
      amounts: [800],
    })
    const settlementId = await settle(authenticatedRequest, member.memberId)

    const rows = (await (
      await authenticatedRequest.get(`/api/admin/notifications?subject_id=${settlementId}`)
    ).json()).data

    const notifications = new NotificationsPage(page)
    await notifications.navigateTo()
    await notifications.filterByStatus('sent')
    await notifications.expectRowAbsent(rows[0].id)

    await notifications.filterByStatus('pending')
    await notifications.expectRowVisible(rows[0].id)
  })

  /**
   * ADR-0038 rule 4, asserted rather than trusted: **the UI may change state,
   * it may never orchestrate time.**
   *
   * A page that quietly re-read the queue would be the first step towards a
   * browser tab acting as a sending path, and it is the kind of thing that gets
   * added later for a good-sounding reason. Counting requests over a window is
   * the only way to notice.
   */
  test('the page does not poll', async ({ page }) => {
    const notifications = new NotificationsPage(page)
    let listRequests = 0
    await page.route('**/api/admin/notifications**', async (route) => {
      if (route.request().method() === 'GET') listRequests++
      await route.continue()
    })

    await notifications.navigateTo()
    await notifications.expectPageVisible()

    const afterLoad = listRequests
    await page.waitForTimeout(3000)

    expect(
      listRequests,
      'the queue must not re-read itself on a timer — that is scheduling, and it belongs to the cron'
    ).toBe(afterLoad)
  })
})

test.describe('Notifications — the finalize confirmation', () => {
  /**
   * *"N announcements queued, sending"* — never *"N sent"*.
   *
   * At the moment this banner is rendered nothing has been sent and nothing
   * could have been: the queue is written inside the settlement's transaction
   * and the scheduler is the only sender. The wording is the only claim the
   * screen can actually back.
   */
  test('finalizing says the announcements are queued, not sent', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Confirm',
      amounts: [900],
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()
    await newSettlement.search(member.lastName)
    await newSettlement.expectMemberVisible(member.memberId)
    await newSettlement.selectMember(member.memberId)
    const settlementId = await newSettlement.create()
    await page.waitForURL('**/settlements')

    // How many the run actually queued, from the server. Hard-coding a number
    // here would be asserting how many members the picker happened to hold —
    // which varies with what else the suite has seeded — rather than the claim
    // under test: the banner states what was queued.
    const queued = (await (
      await authenticatedRequest.get(`/api/admin/notifications?subject_id=${settlementId}`)
    ).json()).data.length
    expect(queued).toBeGreaterThan(0)

    const banner = page.getByTestId('settlements-announcements-queued')
    await expect(banner).toBeVisible()
    await expect(banner).toContainText(String(queued))
    // The German for "sent" must not appear: this is the exact mistake the
    // wording exists to prevent.
    await expect(banner).not.toContainText('versendet')
  })
})
