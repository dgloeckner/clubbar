// @vitest-environment jsdom

/**
 * The two dialogs that change somebody's data (#782).
 *
 * Component tests rather than E2E for exactly the properties E2E is bad at
 * proving: that the approve button *cannot* be pressed in a given state, and
 * that a control does not exist. E2E can show the happy path works; only this
 * can show the gate holds for every combination of the two inputs.
 */

import { render, screen, fireEvent, cleanup } from '@testing-library/react'
import { afterEach, describe, expect, it, vi, beforeEach } from 'vitest'

import { RegistrationReviewPanel } from './RegistrationReviewPanel'
import type { PendingRegistration } from '../../api/generated/pendingRegistration'

vi.mock('react-i18next', () => ({
  // The keys, not the sentences: a test asserting German copy fails on the day
  // somebody improves the wording, which is not a regression.
  useTranslation: () => ({ t: (key: string) => key, i18n: { language: 'de' } }),
}))

const approveRegistration = vi.fn()
const rejectRegistration = vi.fn()
const updateRegistration = vi.fn()

vi.mock('../../api/generated/registration-review/registration-review', () => ({
  getRegistrationReview: () => ({ approveRegistration, rejectRegistration, updateRegistration }),
}))

vi.mock('../../api/client', () => ({ downloadFile: vi.fn() }))

const registration: PendingRegistration = {
  id: '33333333-3333-4333-8333-333333333333',
  first_name: 'Lena',
  last_name: 'Brandt',
  email: 'lena@example.org',
  date_of_birth: '1998-04-02',
  preferred_language: 'de',
  mandate_reference: 'c0ffee1234beef9d41d8cd98f00b204a',
  iban_masked: '****3000',
  iban_last4: '3000',
  bank_name: 'Sparkasse',
  privacy_notice_url: 'https://club.example/Anmeldung.pdf',
  privacy_notice_shown_at: '2026-08-31T10:00:00Z',
  submitted_at: '2026-08-31T10:00:00Z',
  expires_at: '2026-09-30T10:00:00Z',
  duplicate_email: false,
  duplicate_iban: false,
} as PendingRegistration

function renderPanel(overrides: Partial<PendingRegistration> = {}) {
  const onDone = vi.fn()

  render(
    <RegistrationReviewPanel
      registration={{ ...registration, ...overrides }}
      onClose={vi.fn()}
      onDone={onDone}
      onError={vi.fn()}
    />
  )

  return { onDone }
}

beforeEach(() => {
  vi.clearAllMocks()
})

// Explicit, because this project runs vitest without the testing-library
// globals setup that would do it automatically. Without it every render stacks
// in the same document and `getByTestId` finds the previous test's panel too.
afterEach(cleanup)

/**
 * The approve button, typed.
 *
 * `jest-dom`'s matchers are not installed in this project, so `disabled` is
 * read off the element rather than asserted with `toBeDisabled()`.
 */
const confirmButton = (): HTMLButtonElement =>
  screen.getByTestId('approve-confirm') as HTMLButtonElement

describe('the review panel', () => {
  /**
   * The claim the whole module rests on: the server never sends a readable
   * IBAN, and the panel must not invent one. Asserted against the rendered
   * output rather than argued from the DTO.
   */
  it('shows the IBAN masked and nothing more', () => {
    renderPanel()

    expect(screen.getByTestId('panel-iban').textContent).toContain('****3000')
    expect(document.body.textContent).not.toContain('DE89')
  })

  it('flags an applicant the club already has', () => {
    renderPanel({ duplicate_email: true })

    expect(screen.getByTestId('panel-duplicate-warning')).toBeTruthy()
  })

  it('shows no duplicate warning for a stranger', () => {
    renderPanel()

    expect(screen.queryByTestId('panel-duplicate-warning')).toBeNull()
  })
})

describe('the approve dialog', () => {
  /**
   * The attestation is the point of the endpoint, so the button that sends it
   * cannot be pressed until somebody has actually said it. Both inputs are
   * required, and each is checked on its own — a gate that only happened to
   * hold because the *other* field was also empty would pass a happy-path test
   * and fail the first admin who filled in the date first.
   */
  it('cannot be submitted without the attestation', () => {
    renderPanel()
    fireEvent.click(screen.getByTestId('panel-approve'))

    expect(confirmButton().disabled).toBe(true)

    // Even with a signature date: the date alone is not an attestation.
    fireEvent.change(screen.getByTestId('approve-signed-at'), { target: { value: '30.08.2026' } })
    expect(confirmButton().disabled).toBe(true)
  })

  it('cannot be submitted without a signature date', () => {
    renderPanel()
    fireEvent.click(screen.getByTestId('panel-approve'))
    fireEvent.click(screen.getByTestId('approve-attestation'))

    expect(confirmButton().disabled).toBe(true)
  })

  it('sends the attestation and the date once both are given', async () => {
    approveRegistration.mockResolvedValue({ id: 'member-1', email: 'lena@example.org' })
    const { onDone } = renderPanel()

    fireEvent.click(screen.getByTestId('panel-approve'))
    fireEvent.change(screen.getByTestId('approve-signed-at'), { target: { value: '30.08.2026' } })
    fireEvent.click(screen.getByTestId('approve-attestation'))

    expect(confirmButton().disabled).toBe(false)
    fireEvent.click(screen.getByTestId('approve-confirm'))

    await vi.waitFor(() => expect(approveRegistration).toHaveBeenCalledTimes(1))
    expect(approveRegistration).toHaveBeenCalledWith(registration.id, {
      mandate_signed_at: '2026-08-30',
      signed_mandate_confirmed: true,
    })

    // The approval's whole point is a member, so the caller is handed the way
    // to find them rather than being left on the emptier queue. The email
    // rather than the id: the members screen is a searchable list, not a
    // detail route.
    await vi.waitFor(() => expect(onDone).toHaveBeenCalledWith('lena@example.org'))
  })

  /** Unticking is a stated refusal to attest, and re-locks the button. */
  it('re-locks when the attestation is withdrawn', () => {
    renderPanel()
    fireEvent.click(screen.getByTestId('panel-approve'))
    fireEvent.change(screen.getByTestId('approve-signed-at'), { target: { value: '30.08.2026' } })

    const attestation = screen.getByTestId('approve-attestation')
    fireEvent.click(attestation)
    expect(confirmButton().disabled).toBe(false)

    fireEvent.click(attestation)
    expect(confirmButton().disabled).toBe(true)
  })
})

describe('the reject dialog', () => {
  /**
   * There is no rejected state to undo — the row is gone the moment this is
   * pressed. The dialog has to say that, not ask "are you sure?".
   */
  it('warns that the data is deleted immediately', () => {
    renderPanel()
    fireEvent.click(screen.getByTestId('panel-reject'))

    expect(screen.getByTestId('reject-warning')).toBeTruthy()
  })

  it('rejects without a reason', async () => {
    rejectRegistration.mockResolvedValue(undefined)
    renderPanel()

    fireEvent.click(screen.getByTestId('panel-reject'))
    fireEvent.click(screen.getByTestId('reject-confirm'))

    await vi.waitFor(() => expect(rejectRegistration).toHaveBeenCalledWith(registration.id, {}))
  })

  it('passes a reason through when one is given', async () => {
    rejectRegistration.mockResolvedValue(undefined)
    renderPanel()

    fireEvent.click(screen.getByTestId('panel-reject'))
    fireEvent.change(screen.getByTestId('reject-reason'), { target: { value: 'Kein Mandat eingegangen' } })
    fireEvent.click(screen.getByTestId('reject-confirm'))

    await vi.waitFor(() =>
      expect(rejectRegistration).toHaveBeenCalledWith(registration.id, { reason: 'Kein Mandat eingegangen' })
    )
  })
})

describe('the edit form', () => {
  /**
   * The IBAN field opens **empty** and is omitted when left that way. Pre-filled
   * from the row it would invite an accidental "correction" to a value that was
   * already right — and a correction here re-seals the quartet irreversibly,
   * because the plaintext it was derived from is long gone.
   */
  it('omits the IBAN unless one was actually typed', async () => {
    updateRegistration.mockResolvedValue(undefined)
    renderPanel()

    fireEvent.click(screen.getByTestId('panel-edit'))
    expect((screen.getByTestId('edit-iban') as HTMLInputElement).value).toBe('')

    fireEvent.change(screen.getByTestId('edit-first-name'), { target: { value: 'Magdalena' } })
    fireEvent.click(screen.getByTestId('edit-save'))

    await vi.waitFor(() => expect(updateRegistration).toHaveBeenCalledTimes(1))
    expect(updateRegistration.mock.calls[0][1]).not.toHaveProperty('iban')
    expect(updateRegistration.mock.calls[0][1].first_name).toBe('Magdalena')
  })

  it('sends a corrected IBAN compacted', async () => {
    updateRegistration.mockResolvedValue(undefined)
    renderPanel()

    fireEvent.click(screen.getByTestId('panel-edit'))
    fireEvent.change(screen.getByTestId('edit-iban'), { target: { value: 'DE02 1203 0000 0000 2020 51' } })
    fireEvent.click(screen.getByTestId('edit-save'))

    await vi.waitFor(() => expect(updateRegistration).toHaveBeenCalledTimes(1))
    expect(updateRegistration.mock.calls[0][1].iban).toBe('DE02120300000000202051')
  })
})
