// @vitest-environment jsdom

/**
 * The gate on the switch (#783).
 *
 * A component test rather than E2E for the property E2E is worst at proving:
 * that a control *cannot* be pressed in a given state, and that the screen says
 * which precondition is missing. The server refuses each of these by name
 * anyway — that is asserted in `self-registration.spec.ts` — so what is checked
 * here is the half the admin sees before the round trip.
 */

import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { SelfRegistrationCredentials } from './SelfRegistrationCredentials'
import type { SelfRegistrationSettings } from '../../api/generated'

// Keys, not sentences: a test asserting German copy fails on the day somebody
// improves the wording, which is not a regression.
//
// `t` and `i18n` are module-level constants rather than fresh objects per call,
// and that is load-bearing: the component's loader is a `useCallback` closing
// over `t`, so a new `t` on every render invalidates it, re-fires the effect
// that calls it, and the component never leaves its loading state.
const translation = { t: (key: string) => key, i18n: { language: 'de' } }

vi.mock('react-i18next', () => ({ useTranslation: () => translation }))

const getSelfRegistrationSettings = vi.fn()
const updateSelfRegistrationSettings = vi.fn()
const rotateSelfRegistrationSecret = vi.fn()

vi.mock('../../api/generated/registration-review/registration-review', () => ({
  getRegistrationReview: () => ({
    getSelfRegistrationSettings,
    updateSelfRegistrationSettings,
    rotateSelfRegistrationSecret,
  }),
}))

const downloadFile = vi.fn()
vi.mock('../../api/client', () => ({ downloadFile: (...args: unknown[]) => downloadFile(...args) }))

function settings(overrides: Partial<SelfRegistrationSettings> = {}): SelfRegistrationSettings {
  return {
    enabled: false,
    disabled_reason: null,
    has_secret: true,
    secret_rotated_at: '2026-03-14T09:00:00Z',
    document_url: 'https://club.example/anmeldung.pdf',
    retention_days: 30,
    ...overrides,
  }
}

/** Rendered, with the first load settled. */
async function open(initial: SelfRegistrationSettings) {
  getSelfRegistrationSettings.mockResolvedValue(initial)
  render(<SelfRegistrationCredentials />)
  await screen.findByTestId('self-registration-status')
}

const toggle = () => screen.getByTestId('self-registration-toggle') as HTMLButtonElement
const rotateButton = () => screen.getByTestId('self-registration-rotate') as HTMLButtonElement
const posterButton = () => screen.getByTestId('self-registration-poster') as HTMLButtonElement

beforeEach(() => {
  vi.clearAllMocks()
  updateSelfRegistrationSettings.mockResolvedValue(settings())
  rotateSelfRegistrationSecret.mockResolvedValue(settings())
})

afterEach(cleanup)

describe('SelfRegistrationCredentials', () => {
  it('never puts the secret on screen', async () => {
    await open(settings({ has_secret: true }))

    // The strongest claim this screen makes, and the cheapest to lose: the
    // settings payload carries no secret material, so nothing here can render
    // it — but a later "show the secret for convenience" would pass every other
    // test in this file.
    expect(document.body.textContent).not.toMatch(/[A-Za-z0-9]{4}-[A-Za-z0-9]{4}/)
    expect(screen.getByTestId('self-registration-secret-age').dataset.hasSecret).toBe('true')
  })

  it('will not switch on without a secret, and says which one is missing', async () => {
    await open(settings({ has_secret: false }))

    expect(toggle().disabled).toBe(true)
    expect(screen.getByTestId('self-registration-blocked').textContent).toBe(
      'settings.selfRegistration.blockedSecret',
    )
    // Nothing to print either — the button is not a dead control that answers
    // 409 when pressed.
    expect(posterButton().disabled).toBe(true)
    // Generating is offered instead of rotating: there is nothing to rotate.
    expect(rotateButton().textContent).toBe('settings.selfRegistration.generate')
  })

  it('will not switch on without a club document', async () => {
    await open(settings({ has_secret: true, document_url: null }))

    expect(toggle().disabled).toBe(true)
    expect(screen.getByTestId('self-registration-blocked').textContent).toBe(
      'settings.selfRegistration.blockedDocument',
    )
  })

  it('switches on once both preconditions hold', async () => {
    await open(settings({ enabled: false }))

    expect(toggle().disabled).toBe(false)
    fireEvent.click(toggle())

    await waitFor(() => expect(updateSelfRegistrationSettings).toHaveBeenCalled())
    // No reason on the way on: the stale sentence is cleared server-side, and
    // sending the one still in the box would re-store it.
    expect(updateSelfRegistrationSettings).toHaveBeenCalledWith({ enabled: true })
  })

  it('will not switch off with nothing to show the poster-holder', async () => {
    await open(settings({ enabled: true, disabled_reason: null }))

    expect(toggle().disabled).toBe(true)
    expect(screen.getByTestId('self-registration-blocked').textContent).toBe(
      'settings.selfRegistration.blockedReason',
    )

    fireEvent.change(screen.getByTestId('self-registration-reason'), {
      target: { value: '  Wir pausieren bis zur Versammlung  ' },
    })

    expect(toggle().disabled).toBe(false)
    fireEvent.click(toggle())

    await waitFor(() => expect(updateSelfRegistrationSettings).toHaveBeenCalled())
    expect(updateSelfRegistrationSettings).toHaveBeenCalledWith({
      enabled: false,
      disabled_reason: 'Wir pausieren bis zur Versammlung',
    })
  })

  it('asks before rotating, and says what rotation costs', async () => {
    await open(settings({ has_secret: true }))

    fireEvent.click(rotateButton())

    // Nothing has happened yet — the dialog is the point. Every poster in the
    // building dies at this button, and it cannot be undone.
    expect(rotateSelfRegistrationSecret).not.toHaveBeenCalled()
    expect(screen.getByText('settings.selfRegistration.rotateWarning')).toBeTruthy()

    fireEvent.click(screen.getByText('settings.selfRegistration.rotateConfirm'))
    await waitFor(() => expect(rotateSelfRegistrationSecret).toHaveBeenCalledTimes(1))
  })

  it('downloads the poster rather than rendering it', async () => {
    await open(settings({ has_secret: true }))

    fireEvent.click(posterButton())

    await waitFor(() => expect(downloadFile).toHaveBeenCalled())
    // A credential rendered as a picture on paper, fetched by POST — a GET
    // would be replayed by every prefetcher and history entry that touched it.
    expect(downloadFile).toHaveBeenCalledWith(
      '/admin/self-registration/poster',
      'anmeldung-poster.pdf',
      { language: 'de' },
    )
    // And never rotates on the way: losing a printout must not invalidate the
    // poster already on the wall.
    expect(rotateSelfRegistrationSecret).not.toHaveBeenCalled()
  })

  it('saves the document URL on its own, trimmed', async () => {
    await open(settings())

    fireEvent.change(screen.getByTestId('self-registration-document-url'), {
      target: { value: '  https://club.example/neu.pdf  ' },
    })
    fireEvent.click(screen.getByTestId('self-registration-save'))

    await waitFor(() => expect(updateSelfRegistrationSettings).toHaveBeenCalled())
    // The switch is deliberately absent: saving a URL is not a decision about
    // whether the club is open.
    expect(updateSelfRegistrationSettings).toHaveBeenCalledWith({
      document_url: 'https://club.example/neu.pdf',
    })
  })

  it('shows a refused save and keeps what the admin typed', async () => {
    await open(settings())
    updateSelfRegistrationSettings.mockRejectedValue({
      response: { status: 409, data: { reason: 'document_template_not_a_pdf' } },
    })

    const field = screen.getByTestId('self-registration-document-url') as HTMLInputElement
    fireEvent.change(field, { target: { value: 'https://club.example/index.html' } })
    fireEvent.click(screen.getByTestId('self-registration-save'))

    await screen.findByTestId('self-registration-error')
    // Losing the address on a failed save means retyping it to try again, and
    // the admin is most likely one character away from the right one.
    expect(field.value).toBe('https://club.example/index.html')
  })
})
