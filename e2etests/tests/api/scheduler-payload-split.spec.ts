/**
 * `GET /api/admin/scheduler` — one route, two payloads (#677).
 *
 * The grant on this route is wider than the body it returns, which is the one
 * place in ADR-0044's table where that is true, so it needs a test that reads
 * the body rather than the status code. `role-access-matrix.spec.ts` asserts
 * who may knock; this asserts what each office hears.
 *
 * ### Why it matters in both directions
 *
 * The route was `admin`-only until #677, which meant the banner warning that
 * collections are blocked never reached the Kassenwart — the office the block
 * actually lands on, since every settlement route is theirs. Widening the grant
 * fixed that and created the risk this file guards: `setup.cli_command` names
 * this installation's document root, and the rest of the response is the
 * self-check's reading of the host. None of it belongs in a club office's
 * response (ADR-0031).
 *
 * ### Independent of the installation's actual state
 *
 * `cron_heartbeat` is a singleton shared by every worker, so nothing here
 * touches it. The assertions are about *which keys* each office receives, which
 * is true whether or not this installation has ever been drained — and the
 * unverified path, where the banner actually renders, is covered over a real
 * database in `SchedulerGateHttpTest`.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (reads only; mutates nothing)
 * - Pattern 002: Authentication Isolation (per-worker office accounts)
 * - Pattern 004: Parallel Execution Safety (no shared state is written)
 */

import { test, expect } from '../../fixtures/roleRequests'

const API_BASE = 'http://localhost:8080/api'
const SCHEDULER = `${API_BASE}/admin/scheduler`

/** Every field that describes the deployment rather than the club. */
const OPERATOR_FIELDS = [
  'last_run_at',
  'source',
  'last_sent',
  'last_failed',
  'php_version',
  'missing_extensions',
  'declared_interval',
  'observed_interval',
  'interval_disagrees',
]

test.describe('Scheduler status is split by office', () => {
  test('an admin reads the whole status, setup command included', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(SCHEDULER)
    expect(response.status()).toBe(200)

    const body = await response.json()

    expect(typeof body.verified).toBe('boolean')
    // The banner prints this verbatim, so it has to be a command rather than a
    // description of one.
    expect(body.setup.cli_command).toContain('backend/bin/cron.php')
    expect(body.setup.recommended_interval_minutes).toBeGreaterThan(0)
    for (const field of OPERATOR_FIELDS) {
      expect(Object.keys(body), `admin must still receive ${field}`).toContain(field)
    }
  })

  /**
   * The half the banner needs, and the reason the grant moved: without this the
   * treasurer first learns the scheduler is missing from the refusal their own
   * finalize button returns.
   */
  test('a Kassenwart reads the flag and the interval', async ({ kassenwartRequest }) => {
    const response = await kassenwartRequest.get(SCHEDULER)
    expect(response.status()).toBe(200)

    const body = await response.json()

    expect(typeof body.verified).toBe('boolean')
    expect(body.setup.recommended_interval_minutes).toBeGreaterThan(0)
  })

  /**
   * And nothing else. Asserted as an exact key set rather than as an absence
   * list: a field added to the response next year has to be written into the
   * office view deliberately, and this is what says so out loud when it is not.
   */
  test('a Kassenwart reads no operator detail', async ({ kassenwartRequest }) => {
    const body = await (await kassenwartRequest.get(SCHEDULER)).json()

    expect(Object.keys(body).sort()).toEqual(['setup', 'verified'])
    expect(Object.keys(body.setup)).toEqual(['recommended_interval_minutes'])

    const encoded = JSON.stringify(body)
    expect(encoded).not.toContain('cron.php')
    expect(encoded).not.toContain('cron/drain')
  })

  /**
   * The Getränkewart is out of the grant on purpose: the scheduler gate blocks
   * nothing they can do. This 403 is what a bookmark gets — the panel does not
   * ask on their behalf, which is the other half of #677.
   */
  test('a Getränkewart is refused by name', async ({ getraenkewartRequest }) => {
    const response = await getraenkewartRequest.get(SCHEDULER)

    expect(response.status()).toBe(403)
    expect((await response.json()).error).toBe('insufficient_role')
  })
})
