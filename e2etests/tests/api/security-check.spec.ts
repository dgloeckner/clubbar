import { test, expect } from '../../fixtures/auth.fixture'

const API_BASE = 'http://localhost:8080/api'

/**
 * Security self-check API (#247, ADR-0031 decision 3).
 *
 * The endpoint answers "did a host change break something since the install?"
 * — so what these tests protect is the property that makes the answer worth
 * reading: every row is a measurement of the process serving the request, and a
 * check that could not be run is reported as `unknown` rather than as a pass.
 *
 * The per-row logic is unit-tested in `SecuritySelfCheckTest` (flip a directive,
 * watch the row flip), which needs to mutate `ini` settings and cannot be done
 * over HTTP. What only this level can prove is that the report reaches an
 * authenticated admin, and nobody else.
 *
 * Test Data Isolation (E2E Pattern 001): read-only, creates nothing.
 * Authentication (E2E Pattern 002): session auth via the authenticatedRequest fixture.
 * Database-Agnostic Assertions (E2E Pattern 003): rows are found by id, never by
 * position, and values that depend on how this environment is deployed are
 * asserted as constraints rather than as fixed strings.
 */

type Finding = {
  id: string
  category: string
  label: string
  status: 'pass' | 'warn' | 'fail' | 'unknown'
  observed: string
  remedy: string | null
}

// `delivery` (#406) and `backup` (#710) are appended by SecurityCheckService
// rather than produced by the engine: those rows need the database or the
// backup module's parsers, and the engine is dependency-free because
// install.php loads it before Composer's autoloader exists.
//
// This list, SecuritySelfCheck's CATEGORY_* constants, admin.yaml's enum and
// the admin panel's CATEGORY_ORDER have to agree. They are checked separately
// because disagreeing costs something different in each place: here a red
// build, in the panel a row that was measured and never shown.
const CATEGORIES = ['runtime', 'session', 'data', 'exposure', 'transport', 'delivery', 'backup']

test.describe('Security self-check API', () => {
  test('requires an authenticated admin session', async ({ request }) => {
    const response = await request.get(`${API_BASE}/admin/security-check`)

    // The report names the directories holding the scanned mandates and lists
    // which protections are not in force — a map of the weak points.
    expect(response.status()).toBe(401)
  })

  test('returns a measured report with a verdict and a summary', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(`${API_BASE}/admin/security-check`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(body).toHaveProperty('generated_at')
    expect(['pass', 'warn', 'fail']).toContain(body.status)
    expect(Array.isArray(body.findings)).toBe(true)
    expect(body.findings.length).toBeGreaterThan(0)

    const summary = body.summary
    const counted = summary.pass + summary.warn + summary.fail + summary.unknown
    expect(counted, 'every row is counted exactly once').toBe(body.findings.length)
  })

  test('every row names what it measured, and every non-green row what to do', async ({
    authenticatedRequest,
  }) => {
    const findings: Finding[] = (await (
      await authenticatedRequest.get(`${API_BASE}/admin/security-check`)
    ).json()).findings

    for (const finding of findings) {
      expect(CATEGORIES, `${finding.id} has a known category`).toContain(finding.category)
      expect(finding.label.length, `${finding.id} is labelled`).toBeGreaterThan(0)
      expect(finding.observed.length, `${finding.id} reports what was observed`).toBeGreaterThan(0)

      if (finding.status === 'pass') continue

      // ADR-0031 accepts "your host does not allow this" as an outcome — but
      // only when the report says so. A finding with no remedy is a dead end.
      expect(finding.remedy, `${finding.id} is not green and must carry a remedy`).toBeTruthy()
    }
  })

  test('reports the runtime hardening this deployment applies at boot', async ({
    authenticatedRequest,
  }) => {
    const findings: Finding[] = (await (
      await authenticatedRequest.get(`${API_BASE}/admin/security-check`)
    ).json()).findings

    const byId = new Map(findings.map((finding) => [finding.id, finding]))

    // These are set from code (ADR-0031 decision 1), so they hold wherever the
    // suite runs — a host cannot drop them, which is the whole claim.
    expect(byId.get('session_strict_mode')?.status).toBe('pass')
    expect(byId.get('session_cookie_only')?.status).toBe('pass')
    expect(byId.get('session_cookie_httponly')?.status).toBe('pass')
    expect(byId.get('log_errors')?.status).toBe('pass')
    expect(byId.get('session_strict_mode')?.observed).toContain('session.use_strict_mode=On')
  })

  test('never reports a row it could not measure as passing', async ({ authenticatedRequest }) => {
    const findings: Finding[] = (await (
      await authenticatedRequest.get(`${API_BASE}/admin/security-check`)
    ).json()).findings

    // The rule the report rests on, asserted from the outside: an observation
    // string is present on every green row, and no row is both green and
    // "could not be measured".
    for (const finding of findings.filter((f) => f.status === 'pass')) {
      expect(finding.observed.toLowerCase()).not.toContain('could not be measured')
      expect(finding.observed.toLowerCase()).not.toContain('not measurable')
    }
  })

  test('carries the delivery rows an alarm sends somebody to', async ({ authenticatedRequest }) => {
    const findings: Finding[] = (await (
      await authenticatedRequest.get(`${API_BASE}/admin/security-check`)
    ).json()).findings

    const byId = new Map(findings.map((finding) => [finding.id, finding]))

    // The heartbeat (#406) says *something* is wrong and, being external, cannot
    // say what. These five rows are the answer — an alarm with no diagnosis page
    // just produces a phone call.
    for (const id of [
      'mail_transport',
      'mail_scheduler_run',
      'mail_scheduler_interval',
      'mail_queue_backlog',
      'mail_queue_failed',
    ]) {
      const row = byId.get(id)
      expect(row, `${id} is part of the report`).toBeTruthy()
      expect(row?.category).toBe('delivery')
      expect(row?.observed.length, `${id} reports what was observed`).toBeGreaterThan(0)
    }

    // The seed stamps a run, so this environment has an observed scheduler.
    expect(byId.get('mail_scheduler_run')?.observed).toContain('last run')
  })

  test('exposes no failing row on the environment the suite runs against', async ({
    authenticatedRequest,
  }) => {
    const findings: Finding[] = (await (
      await authenticatedRequest.get(`${API_BASE}/admin/security-check`)
    ).json()).findings

    // A `fail` means credentials or member documents are reachable. The
    // development stack keeps its data outside the document root, so a red row
    // here is a regression in the deployment, not a property of the host.
    const failing = findings.filter((finding) => finding.status === 'fail')
    expect(failing.map((finding) => `${finding.id}: ${finding.observed}`)).toEqual([])
  })
})
