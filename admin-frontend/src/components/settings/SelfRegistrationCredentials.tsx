/**
 * The self-registration half of Security & Credentials (#783, UC-A69).
 *
 * The poster secret is a credential in exactly the sense the two blocks above
 * it are: it is long-lived, it is printed on something in the physical world,
 * and replacing it takes that thing out of service. So it belongs here rather
 * than on the review inbox — a Kassenwart works the queue, and does not mint
 * the credential that fills it (ADR-0044).
 *
 * Three controls, and none of them is a preference:
 *
 * - **Rotate** mints a new secret and kills every poster in the building. The
 *   confirmation says so in those words, because that is the consequence an
 *   admin has to weigh and it is not undoable.
 * - **The switch** carries the club's own sentence. Switching off with an empty
 *   reason is refused by the server; the field is marked required here so the
 *   admin finds out before the round trip rather than after it.
 * - **The document URL** is checked when it is saved, so a wrong one is a
 *   validation error on this screen instead of a member's registration
 *   silently arriving without a document weeks later.
 *
 * The secret itself is never on screen. Printing the poster is what reads it
 * back, and the sheet arrives as a PDF the browser downloads — a credential
 * rendered as a picture on paper, not as text in a page a screen share catches.
 *
 * Test IDs: `self-registration-credentials`, `self-registration-status`,
 * `self-registration-secret-age`, `self-registration-rotate`,
 * `self-registration-poster`, `self-registration-toggle`,
 * `self-registration-reason`, `self-registration-document-url`,
 * `self-registration-save`, `self-registration-error`,
 * `self-registration-success`.
 */

import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useApiError } from '../../hooks/useApiError'
import { theme, formatDateTime } from '../../styles/design-system'
import { Alert } from '../common/Alert'
import { Badge } from '../common/Badge'
import { ConfirmDialog } from '../modals/ConfirmDialog'
import { modalInputStyle, modalLabelStyle } from '../modals/ModalError'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { downloadFile } from '../../api/client'
import { getRegistrationReview } from '../../api/generated/registration-review/registration-review'
import type { SelfRegistrationSettings } from '../../api/generated'

export function SelfRegistrationCredentials() {
  const { t, i18n } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const request = useLatestRequest()

  const [settings, setSettings] = useState<SelfRegistrationSettings | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [confirmRotate, setConfirmRotate] = useState(false)

  // The two editable fields are held locally while the admin types, and seeded
  // from whatever the server last said. A controlled input bound straight to
  // `settings` would be overwritten mid-sentence by any reload.
  const [reason, setReason] = useState('')
  const [documentUrl, setDocumentUrl] = useState('')

  const adopt = useCallback((next: SelfRegistrationSettings) => {
    setSettings(next)
    setReason(next.disabled_reason ?? '')
    setDocumentUrl(next.document_url ?? '')
  }, [])

  const load = useCallback(async () => {
    const signal = request.next()
    try {
      setLoading(true)
      const result = await getRegistrationReview().getSelfRegistrationSettings({ signal })
      if (signal.aborted) return
      adopt(result)
      setError(null)
    } catch (err: unknown) {
      if (signal.aborted) return
      setError(apiErrorMessage(err, t('settings.selfRegistration.errors.load')))
    } finally {
      if (!signal.aborted) setLoading(false)
    }
  }, [request, adopt, t, apiErrorMessage])

  useEffect(() => {
    void load()
    return () => request.abort()
  }, [load, request])

  /** Every mutation reports through the same pair of banners. */
  const run = async (action: () => Promise<SelfRegistrationSettings>, message: string) => {
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      adopt(await action())
      setSuccess(message)
    } catch (err: unknown) {
      // `useApiError` resolves the refusal's reason code — which is the whole
      // point of the server naming one. "Generate a poster secret first" is
      // actionable; "409 Conflict" is not.
      setError(apiErrorMessage(err, t('settings.selfRegistration.errors.save')))
    } finally {
      setBusy(false)
    }
  }

  const save = () =>
    run(
      () =>
        getRegistrationReview().updateSelfRegistrationSettings({
          document_url: documentUrl.trim(),
        }),
      t('settings.selfRegistration.saved'),
    )

  const setEnabled = (enabled: boolean) =>
    run(
      () =>
        getRegistrationReview().updateSelfRegistrationSettings(
          // The reason travels with the switch, because the server refuses a
          // pause with nothing to show the person holding the poster.
          enabled ? { enabled } : { enabled, disabled_reason: reason.trim() },
        ),
      enabled ? t('settings.selfRegistration.enabled') : t('settings.selfRegistration.disabled'),
    )

  const rotate = async () => {
    setConfirmRotate(false)
    await run(
      () => getRegistrationReview().rotateSelfRegistrationSecret(),
      t('settings.selfRegistration.rotated'),
    )
  }

  const printPoster = async () => {
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      await downloadFile('/admin/self-registration/poster', 'anmeldung-poster.pdf', {
        language: i18n.language.startsWith('en') ? 'en' : 'de',
      })
      setSuccess(t('settings.selfRegistration.printed'))
    } catch (err: unknown) {
      setError(apiErrorMessage(err, t('settings.selfRegistration.errors.poster')))
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <div data-testid="self-registration-credentials" style={sectionStyle}>
        {t('common.loading')}
      </div>
    )
  }

  const enabled = settings?.enabled ?? false
  const hasSecret = settings?.has_secret ?? false
  // Switching off needs a sentence, and switching on needs both preconditions.
  // The server refuses either way; disabling the button here just means the
  // admin is told before the round trip instead of after it.
  const switchBlocked = enabled
    ? reason.trim() === ''
    : !hasSecret || documentUrl.trim() === ''

  return (
    <div data-testid="self-registration-credentials" style={sectionStyle}>
      <h3 style={headingStyle}>{t('settings.selfRegistration.title')}</h3>
      <p style={leadStyle}>{t('settings.selfRegistration.description')}</p>

      {error && <Alert variant="danger" message={error} testId="self-registration-error" />}
      {success && <Alert variant="success" message={success} testId="self-registration-success" />}

      <div style={rowStyle}>
        <span style={labelStyle}>{t('settings.selfRegistration.status')}</span>
        <span data-testid="self-registration-status" data-enabled={enabled ? 'true' : 'false'}>
          <Badge
            variant={enabled ? 'success' : 'neutral'}
            label={
              enabled
                ? t('settings.selfRegistration.statusOn')
                : t('settings.selfRegistration.statusOff')
            }
          />
        </span>
      </div>

      <div style={rowStyle}>
        <span style={labelStyle}>{t('settings.selfRegistration.poster')}</span>
        <span data-testid="self-registration-secret-age" data-has-secret={hasSecret ? 'true' : 'false'}>
          {hasSecret
            ? t('settings.selfRegistration.posterPrinted', {
                date: settings?.secret_rotated_at
                  ? formatDateTime(settings.secret_rotated_at)
                  : '—',
              })
            : t('settings.selfRegistration.posterNone')}
        </span>
      </div>

      <div style={{ display: 'flex', gap: theme.spacing.sm, flexWrap: 'wrap', marginBottom: theme.spacing.lg }}>
        <button
          data-testid="self-registration-poster"
          onClick={() => void printPoster()}
          disabled={busy || !hasSecret}
          style={buttonStyle(false, busy || !hasSecret)}
        >
          {t('settings.selfRegistration.downloadPoster')}
        </button>
        <button
          data-testid="self-registration-rotate"
          onClick={() => setConfirmRotate(true)}
          disabled={busy}
          style={buttonStyle(false, busy)}
        >
          {hasSecret
            ? t('settings.selfRegistration.rotate')
            : t('settings.selfRegistration.generate')}
        </button>
      </div>

      <label style={modalLabelStyle()} htmlFor="self-registration-document-url">
        {t('settings.selfRegistration.documentUrl')}
      </label>
      <input
        id="self-registration-document-url"
        data-testid="self-registration-document-url"
        type="url"
        value={documentUrl}
        onChange={(event) => setDocumentUrl(event.target.value)}
        placeholder="https://…/anmeldung.pdf"
        style={modalInputStyle(false)}
      />
      {/* Checked when it is saved, not when it is printed — which is the whole
          reason this field is here rather than left to the SEPA tab. */}
      <p style={hintStyle}>{t('settings.selfRegistration.documentUrlHint')}</p>
      <button
        data-testid="self-registration-save"
        onClick={() => void save()}
        disabled={busy}
        style={{ ...buttonStyle(true, busy), marginBottom: theme.spacing.lg }}
      >
        {t('settings.selfRegistration.save')}
      </button>

      <label style={modalLabelStyle()} htmlFor="self-registration-reason">
        {t('settings.selfRegistration.reason')}
      </label>
      <textarea
        id="self-registration-reason"
        data-testid="self-registration-reason"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        rows={2}
        maxLength={500}
        placeholder={t('settings.selfRegistration.reasonPlaceholder')}
        style={{ ...modalInputStyle(false), resize: 'vertical' }}
      />
      <p style={hintStyle}>{t('settings.selfRegistration.reasonHint')}</p>

      <button
        data-testid="self-registration-toggle"
        onClick={() => void setEnabled(!enabled)}
        disabled={busy || switchBlocked}
        style={buttonStyle(!enabled, busy || switchBlocked)}
      >
        {enabled
          ? t('settings.selfRegistration.switchOff')
          : t('settings.selfRegistration.switchOn')}
      </button>
      {/* A dead control that will not say why is a support call, so the reason
          it is dead is written beside it rather than left to a tooltip. */}
      {switchBlocked && (
        <p data-testid="self-registration-blocked" style={hintStyle}>
          {enabled
            ? t('settings.selfRegistration.blockedReason')
            : !hasSecret
              ? t('settings.selfRegistration.blockedSecret')
              : t('settings.selfRegistration.blockedDocument')}
        </p>
      )}

      <ConfirmDialog
        isOpen={confirmRotate}
        title={t('settings.selfRegistration.rotateTitle')}
        message={
          hasSecret
            ? t('settings.selfRegistration.rotateWarning')
            : t('settings.selfRegistration.generateWarning')
        }
        confirmLabel={t('settings.selfRegistration.rotateConfirm')}
        variant="danger"
        onConfirm={() => void rotate()}
        onCancel={() => setConfirmRotate(false)}
      />
    </div>
  )
}

const sectionStyle: React.CSSProperties = {
  marginTop: theme.spacing.xl,
  paddingTop: theme.spacing.lg,
  borderTop: `1px solid ${theme.colors.border.light}`,
}

const headingStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.lg,
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
  marginBottom: theme.spacing.xs,
}

const leadStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.secondary,
  marginBottom: theme.spacing.lg,
}

const rowStyle: React.CSSProperties = {
  display: 'flex',
  gap: theme.spacing.sm,
  alignItems: 'center',
  flexWrap: 'wrap',
  marginBottom: theme.spacing.sm,
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.primary,
}

const labelStyle: React.CSSProperties = {
  color: theme.colors.text.secondary,
  minWidth: '10rem',
}

const hintStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.xs,
  color: theme.colors.text.secondary,
  margin: `${theme.spacing.xs} 0 ${theme.spacing.md}`,
}

function buttonStyle(primary: boolean, disabled: boolean): React.CSSProperties {
  return {
    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
    borderRadius: theme.borderRadius.md,
    border: `1px solid ${theme.colors.border.light}`,
    background: primary ? theme.colors.semantic.primary : 'transparent',
    color: primary ? 'white' : theme.colors.text.primary,
    fontSize: theme.typography.fontSize.sm,
    cursor: disabled ? 'not-allowed' : 'pointer',
    opacity: disabled ? 0.6 : 1,
    whiteSpace: 'nowrap',
  }
}
