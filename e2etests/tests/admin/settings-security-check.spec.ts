/**
 * E2E Tests: Security self-check (#247, ADR-0031 decision 3)
 *
 * The panel's job is to make a host change visible without a re-install, so
 * these tests follow the whole path — open the tab, the SPA calls
 * `GET /api/admin/security-check`, the backend measures the process serving
 * that request, and the rows render with what it observed.
 *
 * Pattern 003: rows are found by their stable id, never by position.
 * Pattern 008: `expect()` for every visibility check, no try-catch.
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Security self-check', () => {
  test('reports measured findings with an overall verdict', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSecurityTab()

    expect(['pass', 'warn', 'fail']).toContain(await authenticatedSettingsPage.getSecurityVerdict())

    const findings = await authenticatedSettingsPage.getSecurityFindings()
    expect(findings.length).toBeGreaterThan(0)
    for (const finding of findings) {
      expect(['pass', 'warn', 'fail', 'unknown'], finding.id).toContain(finding.status)
    }
  })

  /**
   * The row that carries ADR-0016's session requirement, and one the
   * application sets from code — so it is green wherever this suite runs, and
   * its observed value is the directive itself rather than a restatement of
   * what we intended.
   */
  test('shows the effective value behind each row, not the intended one', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSecurityTab()

    await authenticatedSettingsPage.expectSecurityFindingVisible('session_strict_mode')
    expect(await authenticatedSettingsPage.getSecurityFindingStatus('session_strict_mode')).toBe('pass')
    expect(await authenticatedSettingsPage.getSecurityFindingObserved('session_strict_mode')).toContain(
      'session.use_strict_mode=On'
    )
  })

  test('tells the admin what to do about every row that is not green', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSecurityTab()

    const notGreen = (await authenticatedSettingsPage.getSecurityFindings()).filter(
      (finding) => finding.status !== 'pass'
    )

    // The suite's own stack is not a perfect production host — it is reached
    // over plain HTTP — so there is always at least one such row, and every one
    // of them has to carry its remedy (ADR-0031: "your host does not allow
    // this" is an accepted outcome, but never a silent one).
    expect(notGreen.length).toBeGreaterThan(0)
    for (const finding of notGreen) {
      await authenticatedSettingsPage.expectSecurityRemedyVisible(finding.id)
    }
  })

  test('measures again on request, without leaving the page', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSecurityTab()

    const before = await authenticatedSettingsPage.getSecurityFindings()

    const response = authenticatedSettingsPage.page.waitForResponse(
      (res) => res.url().includes('/api/admin/security-check') && res.request().method() === 'GET'
    )
    await authenticatedSettingsPage.clickSecurityRecheck()
    expect((await response).status()).toBe(200)

    // A fresh measurement of an unchanged host reports the same rows — what
    // matters is that it was taken again rather than served from the first one.
    expect(await authenticatedSettingsPage.getSecurityFindings()).toEqual(before)
  })
})
