// @vitest-environment jsdom

/**
 * The four display states ADR-0057 makes distinguishable, and the rule that
 * they are never shown without an age (#954).
 *
 * A component test rather than E2E for the reason `TerminalVersionCell.test`
 * gives: the interesting input is a verdict the backend derived, and driving a
 * real dispenser into a hopper error through the UI would take a wedged coin.
 * The E2E spec covers the path that a report really reaches this cell.
 */

import { render, screen, cleanup } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { TerminalDispenserCell } from './TerminalDispenserCell'

// Keys, not sentences: asserting on copy fails on the day somebody improves
// the wording, which is not a regression. The German strings themselves are
// checked by the locale suite and read by the E2E spec.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      const rest = Object.entries(params ?? {})
        .map(([k, v]) => `${k}=${String(v)}`)
        .join(',')
      return rest ? `${key}:${rest}` : key
    },
    i18n: { language: 'de' },
  }),
}))

const testId = 'settings-terminal-dispenser-t1'

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-09-21T12:00:00Z'))
})

afterEach(() => {
  vi.useRealTimers()
  cleanup()
})

function renderCell(terminal: Record<string, unknown>) {
  // `onSelect` is what the table passes — the detail panel is the only control
  // this cell has, and the test below counts the buttons to prove it.
  render(<TerminalDispenserCell terminal={terminal as never} testId={testId} onSelect={vi.fn()} />)
  return screen.getByTestId(testId)
}

const healthy = {
  configured: true,
  contact: 'reported',
  state: 'idle',
  fault: 'none',
  fault_code: 0,
  available: true,
  unavailable_reason: null,
  state_since: '2026-09-21T09:00:00.000Z',
}

describe('TerminalDispenserCell', () => {
  it('makes no claim about a terminal that has never reported', () => {
    const cell = renderCell({ dispenser_status: null, dispenser_status_at: null })

    expect(cell.getAttribute('data-dispenser-state')).toBe('unknown')
    expect(cell.textContent).toContain('settings.terminalDispenserUnknown')
    // Nothing arrived, so there is no age to show and nothing to open.
    expect(screen.queryByTestId(`${testId}-age`)).toBeNull()
    expect(screen.queryByTestId(`${testId}-details`)).toBeNull()
  })

  it('says there is no dispenser when the terminal reported that there is none', () => {
    // `configured: false` is a report, not an absence of one — which is the
    // whole difference between this row and the one above.
    const cell = renderCell({
      dispenser_status: { configured: false, available: false, unavailable_reason: null },
      dispenser_status_at: '2026-09-21T11:58:00Z',
    })

    expect(cell.getAttribute('data-dispenser-state')).toBe('none')
    expect(cell.textContent).toContain('settings.terminalDispenserNone')
    expect(screen.getByTestId(`${testId}-age`).textContent).toBe('settings.terminalDispenserAgeMinutes:value=2')
  })

  it('shows a working dispenser with the age of the report beside it', () => {
    const cell = renderCell({ dispenser_status: healthy, dispenser_status_at: '2026-09-21T11:45:00Z' })

    expect(cell.getAttribute('data-dispenser-state')).toBe('available')
    expect(screen.getByTestId(`${testId}-badge`).textContent).toContain('settings.terminalDispenserReady')
    // The age is not decoration: a terminal that is off reports nothing, and a
    // status with no age beside it is a claim nobody can check (ADR-0057).
    expect(screen.getByTestId(`${testId}-age`).textContent).toBe('settings.terminalDispenserAgeMinutes:value=15')
  })

  it('does not make a recovered crash look broken', () => {
    // `state: idle`, `fault: none`, crashes in the lifetime counters — the
    // controller came back and nobody has to walk anywhere.
    const cell = renderCell({
      dispenser_status: { ...healthy, lifetime: { crashes: 4, jams: 3 } },
      dispenser_status_at: '2026-09-21T11:59:50Z',
    })

    expect(cell.getAttribute('data-dispenser-state')).toBe('available')
    expect(cell.getAttribute('data-dispenser-reason')).toBe('')
    expect(screen.getByTestId(`${testId}-age`).textContent).toBe('settings.terminalDispenserAgeNow')
  })

  it.each([
    ['offline', 'settings.terminalDispenserUnavailableOffline'],
    ['jam', 'settings.terminalDispenserUnavailableJam'],
    ['unspecified_fault', 'settings.terminalDispenserUnavailableFault'],
  ])('names %s rather than saying only "unavailable"', (reason, key) => {
    const cell = renderCell({
      dispenser_status: { ...healthy, available: false, unavailable_reason: reason, state: 'fault' },
      dispenser_status_at: '2026-09-21T11:00:00Z',
    })

    expect(cell.getAttribute('data-dispenser-state')).toBe('unavailable')
    expect(cell.getAttribute('data-dispenser-reason')).toBe(reason)
    expect(screen.getByTestId(`${testId}-badge`).textContent).toContain(key)
  })

  it('names the Azkoyen code behind a hopper error', () => {
    // "Hopper error" alone is not something an admin can repeat over the phone.
    renderCell({
      dispenser_status: {
        ...healthy,
        available: false,
        unavailable_reason: 'hopper_error',
        fault: 'hopper_error',
        fault_code: 5,
        state: 'fault',
      },
      dispenser_status_at: '2026-09-21T11:00:00Z',
    })

    expect(screen.getByTestId(`${testId}-badge`).textContent).toContain(
      'settings.terminalDispenserUnavailableHopperError:code=5',
    )
  })

  it('keeps a protocol mismatch out of the device faults', () => {
    // Nothing is wrong at the machine; the errand is a deployment one. Folding
    // it into "offline" sent somebody looking for a power cable (finding 13),
    // so it carries its own reason and its own, non-danger, colour.
    const cell = renderCell({
      dispenser_status: {
        ...healthy,
        contact: 'protocol_mismatch',
        available: false,
        unavailable_reason: 'protocol_mismatch',
        protocol: 1,
      },
      dispenser_status_at: '2026-09-21T11:00:00Z',
    })

    expect(cell.getAttribute('data-dispenser-reason')).toBe('protocol_mismatch')
    expect(screen.getByTestId(`${testId}-badge`).textContent).toContain(
      'settings.terminalDispenserUnavailableProtocol',
    )
    // Warning, not danger: the two are different errands, and the colour is
    // half of what an admin reads off the row.
    expect(cell.getAttribute('data-dispenser-variant')).toBe('warning')
  })

  it('dates a fault to when it began, not to the last report about it', () => {
    // A jam re-reported every thirty seconds for an hour is one fault that
    // started an hour ago.
    renderCell({
      dispenser_status: {
        ...healthy,
        available: false,
        unavailable_reason: 'jam',
        fault: 'jam',
        state: 'fault',
        state_since: '2026-09-21T11:00:00.000Z',
      },
      dispenser_status_at: '2026-09-21T11:59:45Z',
    })

    expect(screen.getByTestId(`${testId}-age`).textContent).toBe('settings.terminalDispenserAgeNow')
    expect(screen.getByTestId(`${testId}-since`).textContent).toContain('settings.terminalDispenserSince')
  })

  it('offers no way to clear or reset anything', () => {
    // The device has no reset route and a jam is cleared by a power cycle
    // (owner decision 3). A button here would change a screen and not a hopper.
    const cell = renderCell({
      dispenser_status: { ...healthy, available: false, unavailable_reason: 'jam', fault: 'jam' },
      dispenser_status_at: '2026-09-21T11:59:45Z',
    })

    const buttons = cell.querySelectorAll('button')
    expect(buttons).toHaveLength(1)
    expect(buttons[0].getAttribute('data-testid')).toBe(`${testId}-details`)
  })

  it('renders a status with no stamp without inventing an age', () => {
    const cell = renderCell({ dispenser_status: healthy, dispenser_status_at: null })

    expect(cell.getAttribute('data-dispenser-state')).toBe('available')
    expect(screen.queryByTestId(`${testId}-age`)).toBeNull()
  })
})
