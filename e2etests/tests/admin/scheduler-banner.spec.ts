import { test, expect } from '../../fixtures/pageObjects'
import { createIsolatedAdmin, signInAndEnroll } from '../../utils/isolatedAdmin'

/**
 * The scheduler banner (#405).
 *
 * The mail drain is the only thing that sends the pre-notification every
 * collection promises, and until a scheduled run has been observed the API
 * refuses to finalize a direct debit. Learning that at the moment the treasurer
 * presses the button is too late, so the setup instructions ride along on every
 * page until the first run lands.
 *
 * ### Why the API response is intercepted rather than the database changed
 *
 * `cron_heartbeat` is a **singleton**: one row, shared by every Playwright
 * worker and by the whole backend. Clearing it to produce the unverified state
 * would block settlement creation for every test running concurrently, which is
 * exactly the shared-mutable-state failure Patterns 001 and 004 exist to
 * prevent — and it would do it intermittently, which is the worst version.
 *
 * Routing `/api/admin/scheduler` in this browser context instead keeps the
 * whole check inside one page: the real component, the real layout, the real
 * response shape, and nothing another worker can see. The gate's *server* half
 * is asserted where it lives, over a real database, in
 * `SchedulerGateHttpTest`; the end-to-end chain through a real drain run is
 * #409's.
 *
 * ### The second describe block: which office sees it (#677)
 *
 * The banner is the panel's half of a promise that only matters to the office
 * the gate blocks. The route responds differently per role and the component
 * decides per role whether to ask at all, so the interception below stands in
 * for the server's answer — and the Getränkewart case asserts that the request
 * is never made, which no fulfilled route could show. The payload split itself
 * is `tests/api/scheduler-payload-split.spec.ts`'s.
 */

/** What the server sends an office outside `admin` — no command, no host detail. */
function officeStatusBody(verified: boolean) {
  return { verified, setup: { recommended_interval_minutes: 15 } }
}

test.describe('Scheduler banner', () => {
  const CLI_COMMAND = 'php /srv/htdocs/backend/bin/cron.php'

  const BACKUP_COMMAND = 'php /srv/htdocs/backend/bin/backup.php'

  /**
   * The backup half (#693). `backup` absent means either "this reader is not
   * the operator" or "no backup module was wired", and both must read as
   * nothing to say — which is what keeps every fixture below that omits it
   * valid unchanged.
   */
  function backupBody(configured: boolean, verified: boolean) {
    return {
      configured,
      verified,
      cli_command: BACKUP_COMMAND,
      trigger_url: null,
    }
  }

  function statusBody(verified: boolean, backup?: ReturnType<typeof backupBody>) {
    return {
      verified,
      last_run_at: verified ? '2026-08-14T09:15:00Z' : null,
      source: verified ? 'cli' : null,
      last_sent: 0,
      last_failed: 0,
      php_version: verified ? '8.3.33' : null,
      missing_extensions: verified ? [] : null,
      setup: {
        cli_command: CLI_COMMAND,
        drain_url: null,
        recommended_interval_minutes: 15,
      },
      ...(backup ? { backup } : {}),
    }
  }

  test('appears with the setup command while no run has been observed', async ({ page }) => {
    await page.route('**/api/admin/scheduler', (route) =>
      route.fulfill({ json: statusBody(false) }),
    )

    await page.goto('/members')

    const banner = page.getByTestId('scheduler-banner')
    await expect(banner).toBeVisible()
    // A warning without the command names a problem the reader cannot act on,
    // so the command is the assertion — not the presence of a coloured box.
    await expect(page.getByTestId('scheduler-banner-command')).toHaveText(CLI_COMMAND)
  })

  test('is gone once a run has been recorded', async ({ page }) => {
    await page.route('**/api/admin/scheduler', (route) =>
      route.fulfill({ json: statusBody(true) }),
    )

    await page.goto('/members')

    // Waiting for the page itself first: asserting "not visible" against a page
    // that has not rendered yet would pass for the wrong reason.
    await expect(page.getByTestId('members-page')).toBeVisible()
    await expect(page.getByTestId('scheduler-banner')).toBeHidden()
  })

  /**
   * **The state the second notice exists for** (#693): the drain was set up,
   * the backup cron never was, and nothing else in the product will ever
   * mention it again. Before this, a club that fixed the drain saw a clean
   * panel and had no backups.
   */
  test('warns about the backup job on its own, once the drain is healthy', async ({ page }) => {
    await page.route('**/api/admin/scheduler', (route) =>
      route.fulfill({ json: statusBody(true, backupBody(true, false)) }),
    )

    await page.goto('/members')

    await expect(page.getByTestId('scheduler-banner')).toBeVisible()
    await expect(page.getByTestId('scheduler-banner-backup-command')).toHaveText(BACKUP_COMMAND)
    // And it does not borrow the drain's "collections are blocked": nothing is.
    await expect(page.getByTestId('scheduler-banner-drain')).toBeHidden()
  })

  /**
   * Both missing is the common case in the hour after an install, and it gets
   * one box with two notices rather than two stacked coloured bars.
   */
  test('carries both notices in one box when neither job has run', async ({ page }) => {
    await page.route('**/api/admin/scheduler', (route) =>
      route.fulfill({ json: statusBody(false, backupBody(true, false)) }),
    )

    await page.goto('/members')

    await expect(page.getByTestId('scheduler-banner')).toHaveCount(1)
    await expect(page.getByTestId('scheduler-banner-command')).toHaveText(CLI_COMMAND)
    await expect(page.getByTestId('scheduler-banner-backup-command')).toHaveText(BACKUP_COMMAND)
  })

  /**
   * **Backups off is a legitimate state**, not a fault (ADR-0049 decision 2):
   * configuring a recipient key is the on-switch, and a club that has not
   * generated a keypair has chosen this. A warning nobody can clear is one
   * people learn to ignore, and `BackupConfigCheck` already reports it on the
   * security page for the admin who goes looking.
   */
  test('says nothing about backups that are switched off', async ({ page }) => {
    await page.route('**/api/admin/scheduler', (route) =>
      route.fulfill({ json: statusBody(true, backupBody(false, false)) }),
    )

    await page.goto('/members')

    await expect(page.getByTestId('members-page')).toBeVisible()
    await expect(page.getByTestId('scheduler-banner')).toBeHidden()
  })

  /** Both jobs seen: the state every healthy installation stays in. */
  test('is gone once both jobs have been observed', async ({ page }) => {
    await page.route('**/api/admin/scheduler', (route) =>
      route.fulfill({ json: statusBody(true, backupBody(true, true)) }),
    )

    await page.goto('/members')

    await expect(page.getByTestId('members-page')).toBeVisible()
    await expect(page.getByTestId('scheduler-banner')).toBeHidden()
  })

  /**
   * A failed status read must not paint the banner. Telling every admin to
   * schedule a cron they may already have, on the strength of a request that
   * never answered, is a false alarm on every page of the panel.
   */
  test('stays silent when the status cannot be read', async ({ page }) => {
    await page.route('**/api/admin/scheduler', (route) => route.fulfill({ status: 500, json: {} }))

    await page.goto('/members')

    await expect(page.getByTestId('members-page')).toBeVisible()
    await expect(page.getByTestId('scheduler-banner')).toBeHidden()
  })
})

/**
 * Per-office visibility (#677).
 *
 * Each test signs in as the office it is about (Patterns 001 and 002: a fresh
 * account per test, never the shared seeded admin), because the component reads
 * the session's roles to decide whether to fetch at all.
 */
test.describe('Scheduler banner per office', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  /**
   * The bug #677 names: every settlement route is the Kassenwart's, so the
   * Kassenwart is exactly who the finalize block lands on — and from the day
   * roles shipped, the warning meant to pre-empt it was the one request their
   * session could not make. It renders now, and it renders the wording addressed to them: the
   * command is absent from their response because scheduling a cron is not
   * their job, so the body asks for whoever administers the installation.
   */
  test('a Kassenwart sees the banner, addressed to whoever holds the server', async ({
    loginPage,
    page,
    playwright,
  }) => {
    await page.route('**/api/admin/scheduler', (route) =>
      route.fulfill({ json: officeStatusBody(false) }),
    )

    const { email, password } = await createIsolatedAdmin(playwright, 'sched-treasury', ['kassenwart'])
    await signInAndEnroll(loginPage, page, email, password)

    await expect(page.getByTestId('scheduler-banner')).toBeVisible()
    // No command: printing this installation's document root to a club office
    // is exactly what the payload split exists to prevent.
    await expect(page.getByTestId('scheduler-banner-command')).toBeHidden()
    // A warning with no remedy at all would be worse than none, so the body has
    // to name the remedy it *can* offer — asking the operator.
    await expect(page.getByTestId('scheduler-banner-body')).toContainText(/administers|betreut/i)
  })

  /**
   * The other half: an office the gate never blocks is not warned about it, and
   * — the part that was noise in the access log — does not ask. Asserting the
   * request count rather than the banner's absence is deliberate: a fetch that
   * 403s and is swallowed also renders nothing, and looks identical on screen.
   */
  test('a Getränkewart neither sees the banner nor asks for it', async ({
    loginPage,
    page,
    playwright,
  }) => {
    let asked = 0
    await page.route('**/api/admin/scheduler', (route) => {
      asked += 1
      return route.fulfill({ json: officeStatusBody(false) })
    })

    const { email, password } = await createIsolatedAdmin(playwright, 'sched-bar', ['getraenkewart'])
    await signInAndEnroll(loginPage, page, email, password, '/products')

    await expect(page.getByTestId('products-page')).toBeVisible()
    await expect(page.getByTestId('scheduler-banner')).toBeHidden()

    // A navigation too: the banner re-reads on every mount, so one page proves
    // less than two.
    await page.goto('/reports')
    await expect(page.getByTestId('scheduler-banner')).toBeHidden()

    expect(asked, 'a request whose only possible outcome is 403 must never be made').toBe(0)
  })
})
