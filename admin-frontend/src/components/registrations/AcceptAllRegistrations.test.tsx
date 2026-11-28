// @vitest-environment jsdom

/**
 * "Alle aufnehmen" (UC-A17; ADR-0052 amended 2026-11-28).
 *
 * What the confirmation has to *say* before anything is accepted, and what the
 * result says afterwards. The request itself is the page's and is covered end
 * to end; these are the sentences an admin decides on.
 */

import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { AcceptAllDialog, AcceptAllResult } from './AcceptAllRegistrations'

vi.mock('react-i18next', () => ({
  // Key plus options, so a test can see *which* count a sentence was given.
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) =>
      options ? `${key}::${JSON.stringify(options)}` : key,
    i18n: { language: 'de' },
  }),
}))

vi.mock('../../hooks/useApiError', () => ({
  useApiError: () => ({
    apiErrorMessage: (_: unknown, fallback: string) => fallback,
    reasonText: (code: string) => `reason:${code}`,
  }),
}))

afterEach(cleanup)

function renderDialog(overrides: Partial<Parameters<typeof AcceptAllDialog>[0]> = {}) {
  const onConfirm = vi.fn()

  render(
    <AcceptAllDialog
      isOpen
      shownCount={5}
      totalCount={5}
      flaggedCount={0}
      busy={false}
      error={null}
      onConfirm={onConfirm}
      onCancel={vi.fn()}
      {...overrides}
    />
  )

  return { onConfirm }
}

const okButton = (): HTMLButtonElement => screen.getByTestId('confirm-dialog-ok') as HTMLButtonElement

describe('the confirmation', () => {
  it('states how many registrations become members', () => {
    renderDialog()

    expect(screen.getByTestId('accept-all-count').textContent).toContain('"count":5')
  })

  /** The consequence, before the click: these members cannot buy yet. */
  it('says the members are created without a signature and cannot buy yet', () => {
    renderDialog()

    expect(screen.getByTestId('accept-all-unsigned').textContent).toContain('registrations.acceptAll.unsigned')
  })

  it('says possible duplicates stay in the inbox', () => {
    renderDialog()

    expect(screen.getByTestId('accept-all-duplicates').textContent).toContain('registrations.acceptAll.duplicates')
  })

  /**
   * A flagged row is skipped by the server, so counting it would promise one
   * more member than the answer delivers.
   */
  it('does not count a flagged registration among those accepted', () => {
    renderDialog({ shownCount: 5, flaggedCount: 2 })

    expect(screen.getByTestId('accept-all-count').textContent).toContain('"count":3')
    expect(screen.getByTestId('accept-all-duplicates').textContent).toContain('duplicatesShown')
    expect(screen.getByTestId('accept-all-duplicates').textContent).toContain('"count":2')
  })

  /** "All" means all that are on screen, and with more pending it says so. */
  it('says so when only one page of a longer inbox is being accepted', () => {
    renderDialog({ shownCount: 20, totalCount: 63 })

    expect(screen.getByTestId('accept-all-page-only').textContent).toContain('"count":20')
    expect(screen.getByTestId('accept-all-page-only').textContent).toContain('"total":63')
  })

  it('says nothing about pages when everything pending is on screen', () => {
    renderDialog()

    expect(screen.queryByTestId('accept-all-page-only')).toBeNull()
  })

  it('cannot be confirmed when every row shown is a possible duplicate', () => {
    renderDialog({ shownCount: 2, flaggedCount: 2 })

    expect(okButton().disabled).toBe(true)
  })

  it('cannot be confirmed twice while the request is running', () => {
    const { onConfirm } = renderDialog({ busy: true })

    fireEvent.click(okButton())

    expect(okButton().disabled).toBe(true)
    expect(onConfirm).not.toHaveBeenCalled()
  })
})

describe('the result', () => {
  it('says how many were accepted and what to do next', () => {
    render(
      <AcceptAllResult
        result={{
          accepted: [
            { registration_id: 'r1', member_id: 'm1' },
            { registration_id: 'r2', member_id: 'm2' },
          ],
          skipped: [],
        }}
        onDismiss={vi.fn()}
      />
    )

    expect(screen.getByTestId('batch-result-accepted').textContent).toContain('"count":2')
    expect(screen.queryByTestId('batch-result-skipped')).toBeNull()
    expect(screen.getByTestId('batch-result-next')).toBeTruthy()
  })

  /** One line per reason, not one per registration. */
  it('groups what was skipped by reason', () => {
    render(
      <AcceptAllResult
        result={{
          accepted: [{ registration_id: 'r1', member_id: 'm1' }],
          skipped: [
            { registration_id: 'r2', reason: 'registration_possible_duplicate' },
            { registration_id: 'r3', reason: 'registration_possible_duplicate' },
            { registration_id: 'r4', reason: 'registration_gone' },
          ],
        }}
        onDismiss={vi.fn()}
      />
    )

    expect(screen.getByTestId('batch-result-skipped').textContent).toContain('"count":3')

    const duplicates = screen.getByTestId('batch-result-reason-registration_possible_duplicate').textContent
    expect(duplicates).toContain('"count":2')
    expect(duplicates).toContain('reason:registration_possible_duplicate')
    expect(screen.getByTestId('batch-result-reason-registration_gone').textContent).toContain('"count":1')
  })

  it('says plainly when nothing was accepted', () => {
    render(
      <AcceptAllResult
        result={{ accepted: [], skipped: [{ registration_id: 'r1', reason: 'registration_possible_duplicate' }] }}
        onDismiss={vi.fn()}
      />
    )

    expect(screen.getByTestId('batch-result-accepted').textContent).toBe('registrations.acceptAll.resultNone')
    expect(screen.queryByTestId('batch-result-next')).toBeNull()
  })

  it('can be dismissed', () => {
    const onDismiss = vi.fn()
    render(<AcceptAllResult result={{ accepted: [], skipped: [] }} onDismiss={onDismiss} />)

    fireEvent.click(screen.getByTestId('batch-result-dismiss'))

    expect(onDismiss).toHaveBeenCalled()
  })
})
