// @vitest-environment jsdom

/**
 * The distinction ADR-0054 requirement 10 exists for: `behind` is the ordinary
 * state of every terminal in the club for a few hours after every backend
 * upgrade, and `blocked` is a terminal that will never move again on its own.
 * A page that renders them the same way trains the club to ignore both.
 *
 * A component test rather than E2E because the interesting input is a
 * `version_state` the server computed, and driving a real terminal into each of
 * the five states through the UI would take a release and a failed update.
 */

import { render, screen, cleanup } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { TerminalVersionCell } from './TerminalVersionCell'

// `jest-dom`'s matchers are not installed in this project, so the assertions
// below read the DOM directly rather than through `toHaveTextContent`.

// Keys, not sentences: asserting on copy fails on the day somebody improves
// the wording, which is not a regression.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params?.version ? `${key}:${String(params.version)}` : key,
    i18n: { language: 'de' },
  }),
}))

afterEach(cleanup)

const testId = 'settings-terminal-version-t1'

function renderCell(terminal: Record<string, unknown>) {
  render(<TerminalVersionCell terminal={terminal as never} testId={testId} />)
  return screen.getByTestId(testId)
}

describe('TerminalVersionCell', () => {
  it('shows the bare tag and no badge when the terminal is on the backend’s version', () => {
    const cell = renderCell({
      version_state: 'current',
      reported_version: 'v1.0.7',
      backend_version: 'v1.0.7',
    })

    expect(cell.getAttribute('data-version-state')).toBe('current')
    expect(cell.textContent).toContain('v1.0.7')
    // A green "OK" on every row every day is a green nobody reads.
    expect(screen.queryByTestId(`${testId}-badge`)).toBeNull()
  })

  it('names a terminal that is behind without dressing it as an alarm', () => {
    const cell = renderCell({
      version_state: 'behind',
      reported_version: 'v1.0.6',
      backend_version: 'v1.0.7',
    })

    expect(cell.getAttribute('data-version-state')).toBe('behind')
    // Both versions are on screen: what it runs, and what it is behind at.
    expect(cell.textContent).toContain('v1.0.6')
    expect(screen.getByTestId(`${testId}-badge`).textContent).toContain('settings.terminalVersionBehind')
  })

  it('names the tag a blocked terminal is stuck at', () => {
    const cell = renderCell({
      version_state: 'blocked',
      reported_version: 'v1.0.6',
      blocked_version: 'v1.0.7',
      backend_version: 'v1.0.7',
    })

    expect(cell.getAttribute('data-version-state')).toBe('blocked')
    // The tag itself, because "blocked" alone is not something an admin can act
    // on or repeat over the phone.
    expect(screen.getByTestId(`${testId}-badge`).textContent).toContain(
      'settings.terminalVersionBlocked:v1.0.7',
    )
  })

  it('marks a terminal newer than its backend', () => {
    const cell = renderCell({
      version_state: 'ahead',
      reported_version: 'v1.0.8',
      backend_version: 'v1.0.7',
    })

    expect(cell.getAttribute('data-version-state')).toBe('ahead')
    expect(screen.getByTestId(`${testId}-badge`).textContent).toContain('settings.terminalVersionAhead')
  })

  it('says nothing was reported rather than inventing a state', () => {
    // A terminal older than the header, a proxy that strips it, or a backend on
    // `dev` — none of which is an error, and none of which is agreement.
    const cell = renderCell({ version_state: 'unknown', reported_version: null })

    expect(cell.getAttribute('data-version-state')).toBe('unknown')
    expect(cell.textContent).toContain('settings.terminalVersionUnknown')
  })

  it('treats a state it does not recognise as unknown', () => {
    // A server that grows a sixth state must not render as a blank cell, and
    // must never be guessed into `current`.
    const cell = renderCell({ version_state: 'something-new', reported_version: 'v1.0.7' })

    expect(cell.getAttribute('data-version-state')).toBe('unknown')
    expect(cell.textContent).toContain('settings.terminalVersionUnknown')
  })
})
