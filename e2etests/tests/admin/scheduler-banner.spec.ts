import { test, expect } from '../../fixtures/auth.fixture'

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
 */
test.describe('Scheduler banner', () => {
  const CLI_COMMAND = 'php /srv/htdocs/backend/bin/cron.php'

  function statusBody(verified: boolean) {
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
