// @vitest-environment jsdom

/**
 * "Offene Mandate drucken" (UC-A18).
 *
 * Component tests for the properties an E2E run is bad at proving: that the
 * blank print sends *no* secret, that the keyed one cannot be sent without a
 * key, and that a refused credential leaves the dialog — and the key — exactly
 * where they were.
 */

import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { PrintOpenMandatesDialog } from './PrintOpenMandatesDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key, i18n: { language: 'de' } }),
}))

const downloadFile = vi.fn()
vi.mock('../../api/client', () => ({
  downloadFile: (...args: unknown[]) => downloadFile(...args),
}))

const getProfile = vi.fn()
vi.mock('../../auth/session', () => ({ getProfile: () => getProfile() }))

// The shared hook resolves a reason code to a sentence; here the code itself
// is enough to tell which refusal the dialog is showing.
vi.mock('../../hooks/useApiError', () => ({
  useApiError: () => ({
    apiErrorMessage: (error: { response?: { data?: { reason?: string; error?: string } } }, fallback: string) =>
      error.response?.data?.reason ?? error.response?.data?.error ?? fallback,
    reasonText: (code: string) => code,
  }),
}))

const KEY = 'A'.repeat(43) + '='

function refusal(status: number, data: Record<string, unknown>) {
  return { isAxiosError: true, response: { status, data } }
}

function renderDialog() {
  const onClose = vi.fn()
  const onPrinted = vi.fn()

  render(<PrintOpenMandatesDialog isOpen onClose={onClose} onPrinted={onPrinted} />)

  return { onClose, onPrinted }
}

const okButton = (): HTMLButtonElement => screen.getByTestId('confirm-dialog-ok') as HTMLButtonElement

beforeEach(() => {
  vi.clearAllMocks()
  getProfile.mockResolvedValue({ totp_enabled: false })
})

afterEach(cleanup)

describe('the blank print', () => {
  it('is the default, and asks for no credential', () => {
    renderDialog()

    expect((screen.getByTestId('mandate-print-full-iban') as HTMLInputElement).checked).toBe(false)
    expect(screen.queryByTestId('step-up-password')).toBeNull()
    expect(screen.queryByTestId('private-key-paste')).toBeNull()
  })

  /** No key, no password: the request must not carry a secret it does not need. */
  it('posts an empty body', async () => {
    downloadFile.mockResolvedValue({ 'x-mandate-sheets': '3', 'x-mandate-sheets-without-iban': '0' })
    const { onClose, onPrinted } = renderDialog()

    fireEvent.click(okButton())

    await vi.waitFor(() => expect(downloadFile).toHaveBeenCalledTimes(1))
    expect(downloadFile).toHaveBeenCalledWith('/admin/members/mandate-documents', 'offene-mandate.pdf', {})
    await vi.waitFor(() => expect(onPrinted).toHaveBeenCalledWith({ sheets: 3, withoutIban: 0 }))
    expect(onClose).toHaveBeenCalled()
  })

  /** "Nobody is waiting for a signature" is an answer, and belongs where the question was asked. */
  it('shows a refusal in the dialog and stays open', async () => {
    downloadFile.mockRejectedValue(refusal(409, { reason: 'no_open_mandates' }))
    const { onClose, onPrinted } = renderDialog()

    fireEvent.click(okButton())

    await vi.waitFor(() => expect(screen.getByTestId('mandate-print-error').textContent).toBe('no_open_mandates'))
    expect(onClose).not.toHaveBeenCalled()
    expect(onPrinted).not.toHaveBeenCalled()
  })
})

describe('the full-IBAN print', () => {
  it('reveals the key input and the step-up once chosen', () => {
    renderDialog()

    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))

    expect(screen.getByTestId('private-key-paste')).toBeTruthy()
    expect(screen.getByTestId('step-up-password')).toBeTruthy()
    // The choice is still there to be taken back.
    expect((screen.getByTestId('mandate-print-full-iban') as HTMLInputElement).checked).toBe(true)
  })

  it('cannot be sent without the key', () => {
    renderDialog()
    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))

    fireEvent.change(screen.getByTestId('step-up-password'), { target: { value: 'correct horse' } })

    expect(okButton().disabled).toBe(true)
  })

  it('cannot be sent without the password', () => {
    renderDialog()
    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))

    fireEvent.change(screen.getByTestId('private-key-paste'), { target: { value: KEY } })

    expect(okButton().disabled).toBe(true)
  })

  /** The same body the SEPA export sends: the credential and the key, in JSON. */
  it('posts the key with the step-up credential', async () => {
    downloadFile.mockResolvedValue({ 'x-mandate-sheets': '40', 'x-mandate-sheets-without-iban': '2' })
    const { onPrinted } = renderDialog()

    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))
    fireEvent.change(screen.getByTestId('private-key-paste'), { target: { value: KEY } })
    fireEvent.change(screen.getByTestId('step-up-password'), { target: { value: 'correct horse' } })
    fireEvent.click(okButton())

    await vi.waitFor(() => expect(downloadFile).toHaveBeenCalledTimes(1))
    expect(downloadFile).toHaveBeenCalledWith('/admin/members/mandate-documents', 'offene-mandate.pdf', {
      current_password: 'correct horse',
      totp_code: undefined,
      private_key: KEY,
    })
    await vi.waitFor(() => expect(onPrinted).toHaveBeenCalledWith({ sheets: 40, withoutIban: 2 }))
  })

  it('asks for a code as well when the admin has 2FA', async () => {
    getProfile.mockResolvedValue({ totp_enabled: true })
    renderDialog()

    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))

    await vi.waitFor(() => expect(screen.getByTestId('step-up-totp-code')).toBeTruthy())
  })

  /**
   * A wrong password or a key that is not the club's must not cost the admin
   * the key they just fetched out of the safe: the dialog stays, the key stays.
   */
  it.each([
    [401, { error: 'invalid_credentials' }, 'invalid_credentials'],
    [422, { error: 'private_key_mismatch' }, 'private_key_mismatch'],
  ])('keeps the dialog and the key after a %i', async (status, data, shown) => {
    downloadFile.mockRejectedValue(refusal(status, data))
    const { onClose, onPrinted } = renderDialog()

    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))
    fireEvent.change(screen.getByTestId('private-key-paste'), { target: { value: KEY } })
    fireEvent.change(screen.getByTestId('step-up-password'), { target: { value: 'wrong' } })
    fireEvent.click(okButton())

    await vi.waitFor(() => expect(screen.getByTestId('step-up-error').textContent).toBe(shown))
    expect(onClose).not.toHaveBeenCalled()
    expect(onPrinted).not.toHaveBeenCalled()
    expect((screen.getByTestId('private-key-paste') as HTMLInputElement).value).toBe(KEY)
  })

  /** Unticking withdraws the request for a decryption, and the key goes with it. */
  it('drops the key when the choice is taken back', () => {
    renderDialog()

    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))
    fireEvent.change(screen.getByTestId('private-key-paste'), { target: { value: KEY } })
    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))
    fireEvent.click(screen.getByTestId('mandate-print-full-iban'))

    expect((screen.getByTestId('private-key-paste') as HTMLInputElement).value).toBe('')
  })
})
