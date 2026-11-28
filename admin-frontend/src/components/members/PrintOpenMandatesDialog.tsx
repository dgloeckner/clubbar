/**
 * "Offene Mandate drucken" (UC-A18; ADR-0052 and ADR-0036 amended 2026-11-28).
 *
 * One PDF with the club's Anmeldung for every active member whose mandate has
 * not been signed yet — the middle step of *accept first, print in bulk, sign
 * later*.
 *
 * ## One choice, and it changes what the dialog asks for
 *
 * - **Unticked** — the IBAN line is left for a pen, with the last four digits
 *   beside it. The server needs nothing it does not already hold, so the
 *   dialog is a plain confirmation.
 * - **"IBAN im Klartext drucken"** — the line is filled in. IBANs are sealed
 *   at rest and the server holds no private key (ADR-0036), so this is the
 *   SEPA export's dialog, deliberately: the same `StepUpConfirmDialog`, the
 *   same `PrivateKeyInput`, the same request shape. A second, slightly
 *   different way to hand over the club's key would be a second thing to get
 *   wrong.
 *
 * The key lives in component state for the length of the request and is
 * dropped as soon as the download succeeds or the dialog is abandoned — never
 * stored.
 *
 * ## A rejected credential keeps the dialog open
 *
 * 401 (the step-up) and 422 (a key that is not the club's) are shown *in* the
 * dialog with everything else intact, so the admin can retry without fetching
 * the key sheet out of the safe again. So is 409 — most often "there are no
 * open mandates", which is an answer rather than a failure and belongs where
 * the question was asked.
 *
 * Test IDs: the surrounding dialog's (`confirm-dialog`, `-ok`, `-cancel`),
 * `mandate-print-full-iban`, `mandate-print-error` (blank variant; the keyed
 * one reuses `step-up-error`), plus `private-key-*` and `step-up-*` from the
 * shared pieces.
 */

import { useEffect, useState } from 'react'
import axios from 'axios'
import { useTranslation } from 'react-i18next'

import { downloadFile } from '../../api/client'
import { getProfile } from '../../auth/session'
import { useApiError } from '../../hooks/useApiError'
import { theme } from '../../styles/design-system'
import { ConfirmDialog } from '../modals/ConfirmDialog'
import { ModalError } from '../modals/ModalError'
import { PrivateKeyInput } from '../modals/PrivateKeyInput'
import { StepUpConfirmDialog, type StepUpCredentials } from '../modals/StepUpConfirmDialog'

/** What the page is told once a document has been handed over. */
export interface MandatePrintOutcome {
  /** How many members are in the document. */
  sheets: number
  /** How many sheets of a keyed print were left blank after all. */
  withoutIban: number
}

export interface PrintOpenMandatesDialogProps {
  isOpen: boolean
  onClose: () => void
  onPrinted: (outcome: MandatePrintOutcome) => void
}

const ENDPOINT = '/admin/members/mandate-documents'
const FALLBACK_FILENAME = 'offene-mandate.pdf'

export function PrintOpenMandatesDialog({ isOpen, onClose, onPrinted }: PrintOpenMandatesDialogProps) {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()

  const [fullIban, setFullIban] = useState(false)
  const [privateKey, setPrivateKey] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [callerTotpEnabled, setCallerTotpEnabled] = useState(false)

  // A fresh open starts from the safe default — the blank print — with no key
  // and no error carried over from the last time.
  useEffect(() => {
    if (!isOpen) return

    setFullIban(false)
    setPrivateKey('')
    setError(null)
    setBusy(false)

    // Whether the signed-in admin has 2FA enrolled decides whether the keyed
    // variant asks for a code beside the password.
    let cancelled = false
    getProfile()
      .then((profile) => {
        if (!cancelled) setCallerTotpEnabled(!!profile.totp_enabled)
      })
      .catch(() => {
        if (!cancelled) setCallerTotpEnabled(false)
      })

    return () => {
      cancelled = true
    }
  }, [isOpen])

  const close = () => {
    setPrivateKey('')
    setError(null)
    onClose()
  }

  const print = async (body: Record<string, unknown>) => {
    setBusy(true)
    setError(null)

    try {
      // Through `downloadFile()`, as every download is: it goes via the API
      // client, honours the filename the backend sends, and returns the
      // headers — which is where the two counts ride, because the body is a
      // PDF and has nowhere to put a remark.
      const headers = await downloadFile(ENDPOINT, FALLBACK_FILENAME, body)

      // Only now: a failed attempt keeps the dialog open with the key intact.
      setPrivateKey('')
      onPrinted({
        sheets: Number(headers['x-mandate-sheets'] ?? 0),
        withoutIban: Number(headers['x-mandate-sheets-without-iban'] ?? 0),
      })
      onClose()
    } catch (err: unknown) {
      // Every refusal stays here, where the admin is looking — see the file
      // header for why that includes the 409.
      const status = axios.isAxiosError(err) ? err.response?.status : undefined
      const message = apiErrorMessage(err, t('members.printMandates.failed'))

      if (status === undefined || status >= 500) {
        // Not an answer about this request: the dialog has nothing to add.
        setPrivateKey('')
      }
      setError(message)
    } finally {
      setBusy(false)
    }
  }

  const choice = (
    <div style={{ marginBottom: theme.spacing.md }}>
      <label style={{ display: 'flex', gap: theme.spacing.sm, alignItems: 'flex-start', cursor: 'pointer' }}>
        <input
          type="checkbox"
          data-testid="mandate-print-full-iban"
          checked={fullIban}
          onChange={(event) => {
            setFullIban(event.target.checked)
            setError(null)
            // Unticking withdraws the request for a decryption, so the key
            // goes with it rather than waiting in state for a second thought.
            if (!event.target.checked) setPrivateKey('')
          }}
          style={{ width: 20, height: 20, marginTop: 2, flexShrink: 0 }}
        />
        <span style={{ color: theme.colors.text.primary, fontWeight: theme.typography.fontWeight.semibold }}>
          {t('members.printMandates.fullIban')}
        </span>
      </label>
      <p
        data-testid="mandate-print-full-iban-hint"
        style={{
          margin: `${theme.spacing.xs} 0 0 28px`,
          fontSize: theme.typography.fontSize.sm,
          color: theme.colors.text.secondary,
        }}
      >
        {fullIban ? t('members.printMandates.fullIbanWarning') : t('members.printMandates.fullIbanHint')}
      </p>
    </div>
  )

  if (!fullIban) {
    return (
      <ConfirmDialog
        isOpen={isOpen}
        title={t('members.printMandates.title')}
        variant="primary"
        maxWidth="520px"
        confirmLabel={t('members.printMandates.confirm')}
        confirmDisabled={busy}
        onConfirm={() => void print({})}
        onCancel={close}
        message={
          <>
            <p style={{ margin: 0, marginBottom: theme.spacing.md }}>{t('members.printMandates.message')}</p>
            <ModalError message={error} testId="mandate-print-error" />
            {choice}
          </>
        }
      />
    )
  }

  return (
    <StepUpConfirmDialog
      isOpen={isOpen}
      title={t('members.printMandates.title')}
      message={t('members.printMandates.message')}
      confirmLabel={t('members.printMandates.confirm')}
      requiresTotp={callerTotpEnabled}
      error={error}
      confirmDisabled={busy || privateKey.trim() === ''}
      extraFields={
        <>
          {choice}
          <PrivateKeyInput
            value={privateKey}
            onChange={setPrivateKey}
            hint={t('members.printMandates.keyHint')}
          />
        </>
      }
      onConfirm={(credentials: StepUpCredentials) => void print({ ...credentials, private_key: privateKey })}
      onCancel={close}
    />
  )
}
