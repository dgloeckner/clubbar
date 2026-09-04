// @vitest-environment jsdom

/**
 * The double-click guard and the refusal text (#821, UC-A70).
 *
 * A component test rather than E2E, for the two properties E2E is worst at
 * proving:
 *
 * 1. **A second click does not send a second message.** The backend
 *    deliberately does not deduplicate — `dedup_key` is a per-send nonce, so
 *    two sends to one address really are two mails (ADR-0053 decision 4) — and
 *    this component is therefore the only thing between an impatient double
 *    click and two identical messages in a stranger's inbox. E2E can show one
 *    message arriving; it cannot show that the second request was never made.
 * 2. **A refusal is rendered in the admin's language**, from the reason code,
 *    never from the backend's English sentence.
 */

import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { SendRegistrationLinkModal } from './SendRegistrationLinkModal'

// Keys, not sentences: a test asserting German copy fails on the day somebody
// improves the wording, which is not a regression.
const translation = {
  t: (key: string, params?: Record<string, unknown>) =>
    params?.email ? `${key}:${String(params.email)}` : key,
  i18n: { language: 'de' },
}

vi.mock('react-i18next', () => ({ useTranslation: () => translation }))

const sendRegistrationLink = vi.fn()

vi.mock('../../api/generated/registration-review/registration-review', () => ({
  getRegistrationReview: () => ({ sendRegistrationLink }),
}))

// The real hook resolves `errors.reasons.<code>` out of the locale files; here
// it is enough that the code, and never the backend's English sentence, is what
// reaches the screen.
vi.mock('../../hooks/useApiError', () => ({
  useApiError: () => ({
    apiErrorMessage: (err: unknown, fallback: string) => {
      const reason = (err as { response?: { data?: { reason?: string } } })?.response?.data?.reason
      return reason ? `errors.reasons.${reason}` : fallback
    },
  }),
}))

function open() {
  render(<SendRegistrationLinkModal isOpen onClose={() => {}} />)
}

function type(address: string) {
  fireEvent.change(screen.getByTestId('send-registration-link-email'), {
    target: { value: address },
  })
}

beforeEach(() => {
  sendRegistrationLink.mockReset()
})

afterEach(cleanup)

describe('SendRegistrationLinkModal', () => {
  it('sends once and names the address it queued', async () => {
    sendRegistrationLink.mockResolvedValue({})
    open()

    type('interessent@example.org')
    fireEvent.click(screen.getByTestId('send-registration-link-confirm'))

    await waitFor(() => expect(screen.getByTestId('send-registration-link-success')).toBeTruthy())

    expect(sendRegistrationLink).toHaveBeenCalledTimes(1)
    expect(sendRegistrationLink).toHaveBeenCalledWith({ email: 'interessent@example.org' })
    // The admin typed the address and a typo is the failure mode that matters:
    // nothing verifies the recipient and nothing bounces back into this screen.
    expect(screen.getByTestId('send-registration-link-success').textContent).toContain(
      'interessent@example.org'
    )
  })

  it('does not send twice when the button is clicked twice', async () => {
    sendRegistrationLink.mockResolvedValue({})
    open()

    type('interessent@example.org')
    const button = screen.getByTestId('send-registration-link-confirm')
    fireEvent.click(button)
    fireEvent.click(button)

    await waitFor(() => expect(screen.getByTestId('send-registration-link-success')).toBeTruthy())

    expect(sendRegistrationLink).toHaveBeenCalledTimes(1)
    // Still disabled afterwards: the confirmation stands, and re-arming needs a
    // deliberate act — editing the address, or closing the dialog.
    expect((button as HTMLButtonElement).disabled).toBe(true)
  })

  it('re-arms when the admin corrects the address', async () => {
    sendRegistrationLink.mockResolvedValue({})
    open()

    type('typo@exmaple.org')
    fireEvent.click(screen.getByTestId('send-registration-link-confirm'))
    await waitFor(() => expect(screen.getByTestId('send-registration-link-success')).toBeTruthy())

    // Correcting the address is the deliberate second send, and it must not be
    // blocked by the first one's confirmation still being on screen.
    type('richtig@example.org')
    expect(screen.queryByTestId('send-registration-link-success')).toBeNull()

    fireEvent.click(screen.getByTestId('send-registration-link-confirm'))
    await waitFor(() => expect(sendRegistrationLink).toHaveBeenCalledTimes(2))
    expect(sendRegistrationLink).toHaveBeenLastCalledWith({ email: 'richtig@example.org' })
  })

  it('refuses an address that is not one, without a request', () => {
    open()

    type('not-an-address')
    fireEvent.click(screen.getByTestId('send-registration-link-confirm'))

    expect(screen.getByTestId('send-registration-link-email-error')).toBeTruthy()
    expect(sendRegistrationLink).not.toHaveBeenCalled()
  })

  it('renders the club-state refusal by its reason code', async () => {
    // What the availability switch answers when the club is switched off. The
    // English sentence beside it is for the log; the code is what a panel
    // running in German turns into German.
    sendRegistrationLink.mockRejectedValue({
      response: {
        status: 409,
        data: { reason: 'registration_disabled', message: 'Self-registration is switched off' },
      },
    })
    open()

    type('interessent@example.org')
    fireEvent.click(screen.getByTestId('send-registration-link-confirm'))

    await waitFor(() => expect(screen.getByTestId('send-registration-link-error')).toBeTruthy())

    const banner = screen.getByTestId('send-registration-link-error')
    expect(banner.textContent).toBe('errors.reasons.registration_disabled')
    expect(banner.textContent).not.toContain('switched off')
    expect(screen.queryByTestId('send-registration-link-success')).toBeNull()
  })

  it('lets the admin retry after a refusal', async () => {
    sendRegistrationLink.mockRejectedValueOnce({ response: { status: 500, data: {} } })
    sendRegistrationLink.mockResolvedValueOnce({})
    open()

    type('interessent@example.org')
    fireEvent.click(screen.getByTestId('send-registration-link-confirm'))
    await waitFor(() => expect(screen.getByTestId('send-registration-link-error')).toBeTruthy())

    // A failed send leaves the button armed: there is nothing to guard against
    // — no message was queued — and the admin's next move is to try again.
    expect(
      (screen.getByTestId('send-registration-link-confirm') as HTMLButtonElement).disabled
    ).toBe(false)

    fireEvent.click(screen.getByTestId('send-registration-link-confirm'))
    await waitFor(() => expect(screen.getByTestId('send-registration-link-success')).toBeTruthy())
    expect(sendRegistrationLink).toHaveBeenCalledTimes(2)
  })
})
