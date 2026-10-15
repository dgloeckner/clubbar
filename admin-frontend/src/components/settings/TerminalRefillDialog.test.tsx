// @vitest-environment jsdom

/**
 * Recording a refill (#955, ADR-0058).
 *
 * A component test rather than E2E for the three properties E2E is worst at
 * proving — each of them about what is *not* sent:
 *
 * 1. **The count is exact, and it is the number typed.** No addition to a
 *    previous estimate, and nothing prefilled to nod at: the estimate exists
 *    because nobody counted, and a refill is the moment somebody did.
 * 2. **The threshold is only written when it changed.** It is a setting on the
 *    terminal, so writing it every time would put an `update` audit row behind
 *    every refill claiming a change that never happened.
 * 3. **Nothing here commands the machine.** Two buttons, and neither of them
 *    clears anything: the device has no reset route, and a jam is cleared by a
 *    power cycle (owner decision 3).
 */

import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { TerminalRefillDialog } from './TerminalRefillDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params?.name ? `${key}:${String(params.name)}` : key,
    i18n: { language: 'de' },
  }),
}))

const recordDispenserRefill = vi.fn()
const updateTerminal = vi.fn()

vi.mock('../../api/generated/terminals/terminals', () => ({
  getTerminals: () => ({ recordDispenserRefill, updateTerminal }),
}))

vi.mock('../../hooks/useApiError', () => ({
  useApiError: () => ({ apiErrorMessage: (_err: unknown, fallback: string) => fallback }),
}))

const terminal = {
  id: 'terminal-1',
  name: 'Bar 1',
  dispenser_fill: { refilled_at: '2026-09-20T18:00:00Z', refill_tokens: 400, sold_since: 390, estimated_left: 10, low_threshold: 20 },
}

function open(onSaved = vi.fn()) {
  render(<TerminalRefillDialog isOpen terminal={terminal as never} onClose={vi.fn()} onSaved={onSaved} />)
  return onSaved
}

function type(testId: string, value: string) {
  fireEvent.change(screen.getByTestId(testId), { target: { value } })
}

afterEach(() => {
  vi.clearAllMocks()
  cleanup()
})

describe('TerminalRefillDialog', () => {
  it('sends the counted number, and nothing derived from the old estimate', () => {
    open()
    type('terminal-refill-tokens-input', '500')
    fireEvent.click(screen.getByTestId('terminal-refill-dialog-save'))

    expect(recordDispenserRefill).toHaveBeenCalledWith('terminal-1', { tokens: 500 })
    // Not 510 — the 10 the arithmetic still believed in is replaced, not added to.
    expect(updateTerminal).not.toHaveBeenCalled()
  })

  it('starts with an empty count and the stored threshold', () => {
    open()

    // Empty: a prefilled count is a count nobody made.
    expect((screen.getByTestId('terminal-refill-tokens-input') as HTMLInputElement).value).toBe('')
    expect((screen.getByTestId('terminal-refill-threshold-input') as HTMLInputElement).value).toBe('20')
  })

  it('writes the threshold only when it was changed', async () => {
    open()
    type('terminal-refill-tokens-input', '400')
    type('terminal-refill-threshold-input', '35')
    fireEvent.click(screen.getByTestId('terminal-refill-dialog-save'))

    await waitFor(() =>
      expect(updateTerminal).toHaveBeenCalledWith('terminal-1', { dispenser_low_threshold: 35 }),
    )
    expect(recordDispenserRefill).toHaveBeenCalledWith('terminal-1', { tokens: 400 })
  })

  /** A token is a whole thing, so half of one cannot be typed at all. */
  it('accepts whole tokens only', () => {
    open()
    type('terminal-refill-tokens-input', '12,5')

    expect((screen.getByTestId('terminal-refill-tokens-input') as HTMLInputElement).value).toBe('125')
  })

  it('refuses to save with no count at all', () => {
    open()
    fireEvent.click(screen.getByTestId('terminal-refill-dialog-save'))

    expect(recordDispenserRefill).not.toHaveBeenCalled()
  })

  it('says a refill is not an acknowledgement, and offers nothing that would be', () => {
    open()

    expect(screen.getByTestId('terminal-refill-dialog-note').textContent).toBe(
      'settings.terminalDispenserRefillNote',
    )

    const buttons = screen.getByTestId('terminal-refill-dialog-content').querySelectorAll('button')
    expect([...buttons].map((b) => b.getAttribute('data-testid'))).toEqual([
      'terminal-refill-dialog-cancel',
      'terminal-refill-dialog-save',
    ])
  })

  it('reports a refusal instead of claiming the hopper was recorded', async () => {
    recordDispenserRefill.mockRejectedValueOnce(new Error('nope'))
    const onSaved = open()

    type('terminal-refill-tokens-input', '400')
    fireEvent.click(screen.getByTestId('terminal-refill-dialog-save'))

    await waitFor(() =>
      expect(screen.getByTestId('terminal-refill-dialog-error').textContent).toBe(
        'settings.terminalDispenserRefillError',
      ),
    )
    expect(onSaved).not.toHaveBeenCalled()
  })
})
