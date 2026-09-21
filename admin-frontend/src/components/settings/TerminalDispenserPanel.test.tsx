// @vitest-environment jsdom

/**
 * The detail behind the cell (#954), and the two things it must not do:
 * print a counter nobody reported as `0`, and offer to fix the machine.
 */

import { render, screen, cleanup } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { TerminalDispenserPanel } from './TerminalDispenserPanel'

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

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-09-21T12:00:00Z'))
})

afterEach(() => {
  vi.useRealTimers()
  cleanup()
})

/** What a healthy device really sends: no `filtered_pulses` against the mock. */
const reported = {
  dispenser_status: {
    configured: true,
    contact: 'reported',
    state: 'idle',
    fault: 'none',
    fault_code: 0,
    firmware: '1.2.0',
    protocol: 2,
    rssi: -61,
    uptime_s: 86400,
    reset_reason: 'Power On',
    lifetime: {
      requested_tokens: 1412,
      dispensed_tokens: 1409,
      jams: 3,
      crashes: 0,
      overrun_tokens: 1,
    },
    pending_reconciliations: 0,
    manual_reconciliations: 2,
    observed_at: '2026-09-21T11:58:11Z',
    available: true,
    unavailable_reason: null,
    state_since: '2026-09-21T09:00:00.000Z',
  },
  dispenser_status_at: '2026-09-21T11:58:20Z',
}

function open(terminal: Record<string, unknown>) {
  render(
    <TerminalDispenserPanel isOpen terminalName="Bar 1" terminal={terminal as never} onClose={vi.fn()} />,
  )
}

const text = (testId: string) => screen.getByTestId(testId).textContent

describe('TerminalDispenserPanel', () => {
  it('shows what the device reported, requested against dispensed included', () => {
    open(reported)

    expect(text('terminal-dispenser-detail-firmware')).toBe('1.2.0')
    expect(text('terminal-dispenser-detail-protocol')).toBe('2')
    expect(text('terminal-dispenser-detail-requested')).toBe('1412')
    expect(text('terminal-dispenser-detail-dispensed')).toBe('1409')
    // The gap between the two is the cheapest tamper-or-defect indicator the
    // document carries, so it is named rather than left to be subtracted.
    expect(text('terminal-dispenser-detail-shortfall')).toBe('3')
    expect(text('terminal-dispenser-detail-uptime')).toBe('settings.terminalDispenserAgeDays:value=1')
  })

  it('renders a counter the firmware never sent as absent, not as zero', () => {
    // `filtered_pulses` is absent against the mock even on a healthy report.
    // "0 filtered pulses" would be a measurement nobody made.
    open(reported)

    expect(text('terminal-dispenser-detail-filtered')).toBe('—')
    // And a real zero still reads as zero.
    expect(text('terminal-dispenser-detail-crashes')).toBe('0')
  })

  it('drops every device field at once when the terminal did not reach the machine', () => {
    // O2's rule: when contact != reported the fields are absent, not zero —
    // firmware, uptime, RSSI, reset reason and the whole lifetime object.
    open({
      dispenser_status: {
        configured: true,
        contact: 'unreachable',
        state: null,
        fault: 'none',
        fault_code: 0,
        pending_reconciliations: 1,
        manual_reconciliations: 0,
        available: false,
        unavailable_reason: 'offline',
        state_since: '2026-09-21T10:00:00.000Z',
      },
      dispenser_status_at: '2026-09-21T11:59:00Z',
    })

    for (const field of ['firmware', 'protocol', 'rssi', 'uptime', 'reset-reason', 'jams', 'requested', 'dispensed']) {
      expect(text(`terminal-dispenser-detail-${field}`), field).toBe('—')
    }
    // Said out loud, so an empty table does not read as a broken panel.
    expect(screen.getByTestId('terminal-dispenser-panel-no-contact')).toBeTruthy()
    // The reconciliation rows survive: they are rows in the terminal's own
    // database, not readings from a device nobody reached.
    expect(text('terminal-dispenser-detail-pending')).toBe('1')
    expect(text('terminal-dispenser-detail-manual')).toBe('0')
    expect(text('terminal-dispenser-panel-remedy')).toBe('settings.terminalDispenserRemedyOffline')
  })

  it('sends nobody to the bar with a power cable over a protocol mismatch', () => {
    open({
      dispenser_status: {
        configured: true,
        contact: 'protocol_mismatch',
        state: null,
        fault: 'none',
        fault_code: 0,
        protocol: 1,
        available: false,
        unavailable_reason: 'protocol_mismatch',
        state_since: '2026-09-21T10:00:00.000Z',
      },
      dispenser_status_at: '2026-09-21T11:59:00Z',
    })

    expect(text('terminal-dispenser-panel-remedy')).toBe('settings.terminalDispenserRemedyProtocol')
  })

  it('gives the power-cycle instruction for a jam and nothing to press', () => {
    open({
      dispenser_status: {
        configured: true,
        contact: 'reported',
        state: 'fault',
        fault: 'jam',
        fault_code: 0,
        available: false,
        unavailable_reason: 'jam',
        state_since: '2026-09-21T10:00:00.000Z',
        lifetime: { jams: 4 },
      },
      dispenser_status_at: '2026-09-21T11:59:00Z',
    })

    expect(text('terminal-dispenser-panel-remedy')).toBe('settings.terminalDispenserRemedyFault')

    // Owner decision 3: the device has no reset route and a jam is cleared by
    // a power cycle. The only button in the panel closes it.
    const buttons = screen.getByTestId('terminal-dispenser-panel-content').querySelectorAll('button')
    expect([...buttons].map((b) => b.getAttribute('data-testid'))).toEqual([
      'terminal-dispenser-panel-close',
    ])
  })

  it('marks reconciliations that are waiting for a human', () => {
    open(reported)

    // `manual` is the short post-#947 list: acknowledged, then lost. Above
    // zero it is money waiting for somebody, so it is not just a number.
    expect(text('terminal-dispenser-detail-manual')).toBe('2')
    expect(screen.getByTestId('terminal-dispenser-detail-manual').style.fontWeight).not.toBe('')
    expect(screen.getByTestId('terminal-dispenser-detail-pending').style.fontWeight).toBe('')
  })

  it('renders nothing at all while closed or without a terminal', () => {
    const { container } = render(
      <TerminalDispenserPanel isOpen={false} terminalName="Bar 1" terminal={reported as never} onClose={vi.fn()} />,
    )
    expect(container.innerHTML).toBe('')
  })
})
