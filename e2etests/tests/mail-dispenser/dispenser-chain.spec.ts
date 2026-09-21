/**
 * The dispenser alarm, end to end (#956, ADR-0057 / ADR-0058).
 *
 *     a terminal reports a jam → bin/cron.php (scan + enqueue + drain) → Mailpit
 *
 * ### What only this file can show
 *
 * The PHPUnit suites pin the conditions, the two dedup anchors, the persistence
 * window, the office and the rendering in both languages. None of them can show
 * that a club **receives an email** when a dispenser jams — because a row that
 * was never written is indistinguishable from a row that was written and never
 * sent, and only one of those is the alarm (Pattern 010).
 *
 * That distinction carries the whole feature here. Epic finding 17 is *nobody
 * is told*: until this, a jam on a Friday evening was found by the next member
 * who wanted a token. A scan that queues perfectly and delivers nothing would
 * leave a club exactly as blind as before, with every unit test still green.
 *
 * ### The assertions that matter are the negative ones
 *
 * Dispenser state is the `admin` office's — every `/api/admin/terminals*` route
 * is ADMIN_ONLY, owner decision 2026-09-20 — so the Kassenwart and the
 * Getränkewart must receive nothing. An empty mailbox is also what a broken
 * chain looks like, so each test waits for the **positive** delivery first and
 * only then holds the other two mailboxes open (Pattern 011).
 *
 * ### Blast radius
 *
 * Three accounts and its own terminals (Patterns 001, 003), all removed in
 * `afterAll`: an extra active admin collects every later notice in the suite,
 * and a terminal left jammed would queue a warning into every admin mailbox a
 * later project is counting.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: creates its own recipients and terminals rather than asserting on shared ones
 * - Pattern 004: `fullyParallel: false`; the drains happen one at a time
 * - Pattern 010: every assertion reads what a real drain delivered to Mailpit
 * - Pattern 011: the offices that must hear nothing are named, not inferred
 */

import { test, expect } from '../../fixtures/auth.fixture'
import type { APIRequestContext } from '@playwright/test'
import {
  assertMailpitReachable,
  createMailpitClient,
  MailpitClient,
  MailpitMessage,
} from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { ageDispenserEpisode } from '../../utils/sql'
import { stepUp } from '../../fixtures/stepUp'

const API_BASE = 'http://localhost:8080/api'
const MAIL_CONFIG = '/api/admin/mail-config'
const ADMIN_USERS = '/api/admin/admin-users'
const TERMINALS = `${API_BASE}/admin/terminals`
const REPORT = `${API_BASE}/sync/terminal-status`

/** A sender is required before the drain will claim anything at all. */
const SENDER_ADDRESS = 'noreply@dispenser-mail.test.example'

/** The whole queue may be waiting; a run needs room to reach these messages. */
const BUDGET_SECONDS = 55

/** Comfortably past `DispenserAttentionNotifier::PERSISTENCE_MINUTES`. */
const HELD_FOR_MINUTES = 30

test.describe.configure({ mode: 'serial' })

test.describe('a dispenser that needs a human reaches the admin office', () => {
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

  let mail: MailpitClient
  let disposeMailpit: () => Promise<void>

  const offices: Record<'admin' | 'kassenwart' | 'getraenkewart', { id: string; email: string }> = {
    admin: { id: '', email: '' },
    kassenwart: { id: '', email: '' },
    getraenkewart: { id: '', email: '' },
  }

  /** Terminals this file created, removed in `afterAll`. */
  const terminals: string[] = []

  /** The dispenser notices in one mailbox, by subject. */
  async function notices(recipient: string, terminalName: string): Promise<string[]> {
    const messages = await mail.messagesTo(recipient)

    return messages
      .map((message) => message.Subject ?? '')
      .filter((subject) => subject.includes('Ausgabegerät') && subject.includes(terminalName))
  }

  async function noticeFor(recipient: string, terminalName: string): Promise<MailpitMessage> {
    await expect
      .poll(async () => (await notices(recipient, terminalName)).length, {
        timeout: 30_000,
        message: `Mailpit should hold one dispenser notice for ${recipient}`,
      })
      .toBe(1)

    const summaries = await mail.messagesTo(recipient)
    const summary = summaries.find(
      (m) => (m.Subject ?? '').includes('Ausgabegerät') && (m.Subject ?? '').includes(terminalName),
    )!

    return mail.message(summary.ID)
  }

  async function createTerminal(
    authenticatedRequest: APIRequestContext,
    label: string,
  ): Promise<{ id: string; name: string; token: string }> {
    // A fresh tag per call rather than the file's `suffix` alone: CI retries
    // a failed test in the same worker, `suffix` is computed once when the file
    // loads, and a second `POST /terminals` with a `device_id` that already
    // exists is refused — so the retry would fail on a duplicate instead of on
    // whatever it was retrying (Pattern 001).
    const tag = `${label}-${Math.random().toString(36).slice(2, 8)}`
    const name = `Dispenser ${tag} ${suffix}`
    const response = await authenticatedRequest.post(TERMINALS, {
      data: { ...stepUp(), name, device_id: `device-mail-${tag}-${suffix}` },
    })
    expect(response.status(), await response.text()).toBe(201)
    const created = await response.json()
    terminals.push(created.terminal.id)

    return { id: created.terminal.id, name, token: created.api_token }
  }

  function jam(): unknown {
    return {
      dispenser: {
        configured: true,
        contact: 'reported',
        state: 'fault',
        fault: 'jam',
        fault_code: 0,
        firmware: '1.2.0',
        protocol: 2,
        lifetime: { requested_tokens: 412, dispensed_tokens: 409, jams: 4, crashes: 0 },
        observed_at: new Date().toISOString(),
      },
    }
  }

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
      data: { sender_address: config.sender_address || SENDER_ADDRESS },
    })
    expect(patched.status(), await patched.text()).toBe(200)

    // `admin` last on purpose: creating an account is itself an `admin`-only
    // notice, so an admin mailbox minted first would collect one per account
    // this file creates.
    for (const role of ['getraenkewart', 'kassenwart', 'admin'] as const) {
      const email = `dispenser-${role}-${suffix}@test.example`
      const created = await authenticatedRequest.post(ADMIN_USERS, {
        data: {
          ...stepUp(),
          email,
          display_name: `Dispenser ${role} ${suffix}`,
          locale: 'de',
          roles: [role],
        },
      })
      expect(created.status(), await created.text()).toBe(201)
      offices[role] = { id: (await created.json()).admin.id, email }
    }

    // Each account was also mailed its own invitation (migration 058). That is
    // this file's own noise; delivered and cleared here so the office mailboxes
    // start empty.
    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toMatch(/claimed=\d+ sent=\d+/)
    for (const office of Object.values(offices)) {
      await mail.deleteFor(office.email)
    }
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // The terminals first: one left jammed or with an empty hopper would queue
    // a notice to every admin account the rest of the suite creates.
    for (const id of terminals) {
      await authenticatedRequest.delete(`${TERMINALS}/${id}`)
    }
    for (const office of Object.values(offices)) {
      if (office.id) {
        await authenticatedRequest.delete(`${ADMIN_USERS}/${office.id}`)
      }
    }
    await disposeMailpit?.()
  })

  /**
   * **The failure the issue exists for**, and the two properties that make the
   * channel worth having: the errand reaches the office that can run it, and
   * saying it once does not mean saying it every quarter of an hour.
   */
  test('a jam that has held is mailed once, to the admin and to nobody else', async ({
    authenticatedRequest,
    request,
  }) => {
    const terminal = await createTerminal(authenticatedRequest, 'jam')

    const reported = await request.put(REPORT, {
      headers: { Authorization: `Bearer ${terminal.token}` },
      data: jam(),
    })
    // Telemetry never fails a sync cycle, whatever it carries.
    expect(reported.status()).toBe(204)

    // A jam a moment old is not an incident: a dispenser is briefly unreachable
    // during a dispense often enough that an immediate notice would be a WLAN
    // log by email. The stamp is the backend's own, so no client can produce a
    // fault that started half an hour ago.
    ageDispenserEpisode(terminal.id, HELD_FOR_MINUTES)

    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

    const message = await noticeFor(offices.admin.email, terminal.name)

    // The condition in the words the panel and the kiosk use — one machine,
    // three surfaces, one vocabulary.
    expect(message.Subject).toContain('Stau oder leer')
    expect(message.Text).toContain(
      'Stau beseitigen, bei Bedarf nachfüllen, dann das Gerät 5 Sekunden vom Strom trennen.',
    )
    expect(message.Text).toContain('In diesem Zustand seit')

    // Nothing to press. The device has no reset route and a jam is cleared by a
    // power cycle, so this message cannot offer to clear one.
    expect(message.Text).toContain('quittiert nichts und setzt nichts zurück')
    expect(`${message.Text}${message.HTML}`).not.toContain('quittieren')

    // …and the two offices this screen is not for heard nothing. The drain has
    // run and delivered by now, so these mailboxes are empty because nothing
    // was addressed to them.
    await mail.expectNothingFor(offices.kassenwart.email)
    await mail.expectNothingFor(offices.getraenkewart.email)

    // The same episode on the next tick queues nothing: the dedup key is
    // `fault:<state_since>`, and `state_since` does not move while the machine
    // keeps reporting the same fault.
    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(await notices(offices.admin.email, terminal.name)).toHaveLength(1)
  })

  /**
   * The hopper's own errand, which is keyed on the refill rather than on the
   * report — a draining hopper changes nothing about what the terminal says.
   *
   * A counted-in zero is a legitimate refill (a hopper emptied for maintenance,
   * ADR-0058), and it is the cheapest honest way to stand a used-up estimate up
   * through the real admin API rather than by writing rows.
   */
  test('an empty hopper is its own notice, and also only one', async ({
    authenticatedRequest,
    request,
  }) => {
    const terminal = await createTerminal(authenticatedRequest, 'low')

    const reported = await request.put(REPORT, {
      headers: { Authorization: `Bearer ${terminal.token}` },
      data: {
        dispenser: {
          configured: true,
          contact: 'reported',
          state: 'idle',
          fault: 'none',
          fault_code: 0,
          observed_at: new Date().toISOString(),
        },
      },
    })
    expect(reported.status()).toBe(204)

    const refilled = await authenticatedRequest.post(
      `${TERMINALS}/${terminal.id}/dispenser-refill`,
      { data: { tokens: 0 } },
    )
    expect(refilled.status(), await refilled.text()).toBe(200)

    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

    const message = await noticeFor(offices.admin.email, terminal.name)
    expect(message.Subject).toContain('Token gehen zur Neige')
    expect(message.Text).toContain('Schätzung aufgebraucht')
    // It is an estimate and says so — the machine has no empty sensor.
    expect(message.Text).toContain('keinen Leer-Sensor')

    await mail.expectNothingFor(offices.kassenwart.email)
    await mail.expectNothingFor(offices.getraenkewart.email)

    // One warning per hopper load, not one per tick.
    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(await notices(offices.admin.email, terminal.name)).toHaveLength(1)
  })

  /**
   * **Nothing on success**, the property the channel's usefulness rests on. A
   * club that receives "the dispenser is fine" every quarter-hour has a filter
   * rule by Tuesday, and the first real jam lands behind it.
   *
   * A recovered crash is the sharp case: `state: idle`, `fault: none`, with an
   * error behind it. That is a working machine, and taking a bar out of service
   * for it would close it for nothing (ADR-0057 context 4).
   */
  test('a working dispenser delivers nothing at all', async ({
    authenticatedRequest,
    request,
  }) => {
    const terminal = await createTerminal(authenticatedRequest, 'idle')

    const reported = await request.put(REPORT, {
      headers: { Authorization: `Bearer ${terminal.token}` },
      data: {
        dispenser: {
          configured: true,
          contact: 'reported',
          state: 'idle',
          fault: 'none',
          fault_code: 0,
          reset_reason: 'Software Watchdog',
          lifetime: { requested_tokens: 12, dispensed_tokens: 12, jams: 0, crashes: 1 },
          observed_at: new Date().toISOString(),
        },
      },
    })
    expect(reported.status()).toBe(204)

    ageDispenserEpisode(terminal.id, HELD_FOR_MINUTES)

    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

    // Not an empty mailbox — the two tests above put notices in it, and those
    // are this feature working. What must be absent is a notice about *this*
    // terminal, and only that.
    expect(await notices(offices.admin.email, terminal.name)).toEqual([])
  })
})
