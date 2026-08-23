/**
 * The epic's acceptance test, end to end (#555, ADR-0046).
 *
 * Every other spec in this epic checks one seam. This one checks that they
 * join up: a club sets its ceiling, a member with no override of their own is
 * measured against it, that member is then given a ceiling, and the dashboard
 * moves with it — while the member beside them does not.
 *
 * The point of doing it in one flow rather than four tests (Pattern 009) is
 * that "no other member changed" is only meaningful about members whose state
 * this test knows. Two members are seeded with the same tab, and only one of
 * them is ever singled out.
 *
 * Patterns: 001 (members seeded per test), 002 (never touches a shared
 * account's roles), 003 (rows found by id, never by position), 005 (test IDs),
 * 006 (page objects), 008 (expect, never try-catch), 009 (one flow).
 */

import { expect, test } from '../../fixtures/auth.fixture'
import { DashboardPage } from '../../pages/DashboardPage'
import { MembersPage } from '../../pages/MembersPage'
import { SettingsPage } from '../../pages/SettingsPage'
import { seedMember } from '../../utils/exclusions'

const API_BASE = 'http://localhost:8080/api'
const CONFIG_URL = `${API_BASE}/admin/credit-limit-config`

test.describe.serial('Credit limits, end to end', () => {
  /** Restored afterwards: the club setting is a singleton every spec reads. */
  let originalConfig: { default_limit_cents: number; warn_threshold_percent: number } | null = null
  let seeded: string[] = []

  test.beforeAll(async ({ authenticatedRequest }) => {
    originalConfig = await (await authenticatedRequest.get(CONFIG_URL)).json()
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    if (originalConfig) {
      await authenticatedRequest.patch(CONFIG_URL, { data: originalConfig })
    }
    // Deactivating the fixtures hands the panel's five rows back to everyone
    // else — the same cleanup `dashboard-credit-limit.spec.ts` performs, and
    // for the same reason: a tab is only cleared by a settlement run.
    for (const memberId of seeded) {
      await authenticatedRequest.patch(`${API_BASE}/admin/members/${memberId}`, {
        data: { is_active: false },
      })
    }
    seeded = []
  })

  test('a club ceiling, a member override, and the dashboard following both', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    // ── 1. The club sets its ceiling, through the page a treasurer uses ────
    const settings = new SettingsPage(page)
    await settings.goto()
    await settings.openLimitsTab()
    await settings.setCreditLimits('500.00', '80')
    await settings.expectLimitsSaved()

    // The derived figure is stated rather than left as arithmetic homework.
    expect(await settings.getWarnAtText()).toMatch(/400[.,]00/)

    const stored = await (await authenticatedRequest.get(CONFIG_URL)).json()
    expect(stored.default_limit_cents).toBe(50_000)
    expect(stored.warn_threshold_percent).toBe(80)

    // The number a treasurer just set is the number the member form offers for
    // a member who has none of their own — asserted here, where this test owns
    // the setting and nothing else can be moving it.
    const members = new MembersPage(page)
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await members.expectPageVisible()
    await members.openCreateModal()
    await members.expectFormModalVisible()
    expect(await members.getCreditLimitPlaceholder()).toMatch(/500/)
    await members.cancelForm()

    // ── 2. Two members with identical tabs, neither singled out ────────────
    // 45,000 is 90 % of the club's new ceiling: inside the band, past nothing.
    const tabCents = 45_000
    const [subject, bystander] = await Promise.all([
      seedMember(authenticatedRequest, authenticatedTerminalRequest, {
        tag: 'FlowSubject',
        prefix: 'Limit',
        amounts: [tabCents],
      }),
      seedMember(authenticatedRequest, authenticatedTerminalRequest, {
        tag: 'FlowBystander',
        prefix: 'Limit',
        amounts: [tabCents],
      }),
    ])
    seeded.push(subject.memberId, bystander.memberId)

    const panelRow = async (memberId: string) => {
      const body = await (await authenticatedRequest.get(`${API_BASE}/admin/dashboard`)).json()
      return (
        body.members_near_limit.members as Array<{
          id: string
          limit_cents: number
          percent_of_limit: number
          status: string
        }>
      ).find((row) => row.id === memberId)
    }

    // Both are measured against the club's ceiling, because neither has one.
    for (const member of [subject, bystander]) {
      const row = await panelRow(member.memberId)
      expect(row, `${member.lastName} should be on the panel`).toBeDefined()
      expect(row!.limit_cents).toBe(50_000)
      expect(row!.percent_of_limit).toBe(90)
      expect(row!.status).toBe('approaching')
    }

    // ── 3. One member is given a ceiling of their own ──────────────────────
    await authenticatedRequest.patch(`${API_BASE}/admin/members/${subject.memberId}`, {
      data: { credit_limit_cents: 40_000 },
    })

    const singledOut = await panelRow(subject.memberId)
    expect(singledOut!.limit_cents).toBe(40_000)
    // 45,000 of 40,000 — past their own ceiling, so the terminal has stopped
    // serving them even though the club's ceiling is 50,000.
    expect(singledOut!.percent_of_limit).toBe(113)
    expect(singledOut!.status).toBe('exceeded')

    // ── 4. Nobody else moved ───────────────────────────────────────────────
    const untouched = await panelRow(bystander.memberId)
    expect(untouched!.limit_cents).toBe(50_000)
    expect(untouched!.percent_of_limit).toBe(90)
    expect(untouched!.status).toBe('approaching')

    // ── 5. And the panel says so on screen ─────────────────────────────────
    const dashboard = new DashboardPage(page)
    await dashboard.goto()
    await dashboard.expectMemberNearLimit(subject.memberId)
    expect(await dashboard.getMemberNearLimitState(subject.memberId)).toBe('exceeded')
    // "450,00 € von 400,00 €" — the member's own ceiling, not the club's.
    expect(await dashboard.getMemberNearLimitBalance(subject.memberId)).toMatch(/400[.,]00/)
    expect(await dashboard.getMemberNearLimitBalance(bystander.memberId)).toMatch(/500[.,]00/)

    // ── 6. Raising a ceiling takes a member off the panel entirely ─────────
    await authenticatedRequest.patch(`${API_BASE}/admin/members/${subject.memberId}`, {
      data: { credit_limit_cents: 500_000 },
    })
    expect(await panelRow(subject.memberId)).toBeUndefined()

    // ── 7. And a member with no ceiling at all never appears ───────────────
    await authenticatedRequest.patch(`${API_BASE}/admin/members/${subject.memberId}`, {
      data: { credit_limit_cents: 0 },
    })
    expect(await panelRow(subject.memberId)).toBeUndefined()

    // ── 8. The terminal is told the same club policy it must enforce ───────
    const { credit_limit } = await (
      await authenticatedTerminalRequest.get(`${API_BASE}/sync/config`)
    ).json()
    expect(credit_limit.default_limit_cents).toBe(50_000)
    expect(credit_limit.warn_at_cents).toBe(40_000)

    // …and the member's override rides their own row, unresolved.
    const sync = await (
      await authenticatedTerminalRequest.get(`${API_BASE}/sync/members`, { params: { since: 0 } })
    ).json()
    const syncedSubject = (sync.members as Array<{ id: string; credit_limit_cents: number | null }>).find(
      (m) => m.id === subject.memberId,
    )
    const syncedBystander = (sync.members as Array<{ id: string; credit_limit_cents: number | null }>).find(
      (m) => m.id === bystander.memberId,
    )
    expect(syncedSubject?.credit_limit_cents).toBe(0)
    expect(syncedBystander?.credit_limit_cents).toBeNull()
  })

  /**
   * The tab's own behaviour, kept in this file rather than one of its own:
   * every test that writes the club setting has to be serial with respect to
   * every other, and inside one file is the only place Playwright can promise
   * that.
   */
  test('the tab reads back what is stored, and refuses a ceiling it cannot send', async ({
    authenticatedRequest,
    page,
  }) => {
    const settings = new SettingsPage(page)
    await settings.goto()
    await settings.openLimitsTab()

    const stored = await (await authenticatedRequest.get(CONFIG_URL)).json()
    expect(await settings.getClubDefaultLimit()).toBe(
      (stored.default_limit_cents / 100).toFixed(2),
    )
    expect(await settings.getWarnThresholdPercent()).toBe(String(stored.warn_threshold_percent))
    // The helper is what says the number is club-wide; an empty one would leave
    // the field reading like a per-member setting.
    expect((await settings.getLimitsHelperText()).trim().length).toBeGreaterThan(0)

    // An empty ceiling is refused, and refused *here*: a club default has no
    // "unset" state — that is what a member's empty field means, not the
    // club's — so the save is stopped with the field named rather than sent as
    // a value the API would have to interpret.
    //
    // A **negative** ceiling cannot be typed into this control at all: the
    // input declares `min=0`, so the browser's own constraint validation stops
    // the submit before any of this runs. The rule that a negative ceiling is
    // refused rather than read as "unlimited" is asserted over HTTP instead, in
    // `tests/api/credit-limit-config.spec.ts`, which is the layer that owns it.
    await settings.setCreditLimitsExpectingRefusal('', String(stored.warn_threshold_percent))
    await settings.expectLimitsRejected()

    const afterwards = await (await authenticatedRequest.get(CONFIG_URL)).json()
    expect(afterwards.default_limit_cents).toBe(stored.default_limit_cents)
  })
})
