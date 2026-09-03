/**
 * Reviewing one submission: correct it, print it, approve it, reject it
 * (#782, UC-A17).
 *
 * ## The attestation is the point of the approve button
 *
 * `signed_mandate_confirmed` is not a checkbox the form defaults to true. It
 * records an admin stating that they are holding the signed SEPA mandate, and
 * — where the club printed the form with the IBAN comb left blank — that the
 * hand-written number matches the `****last4` on file. The button is disabled
 * until it is ticked, and the server refuses without it anyway: the disabled
 * state is a courtesy, never the gate.
 *
 * ## Rejection is a deletion, and says so
 *
 * There is no "rejected" state to undo. The row is gone the moment the button
 * is pressed, because a rejected application is data about somebody who is not
 * becoming a member (ADR-0052 decision 10). The confirmation says that in
 * words rather than asking "are you sure?".
 */

import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { downloadFile } from '../../api/client'
import { getRegistrationReview } from '../../api/generated/registration-review/registration-review'
import type { PendingRegistration } from '../../api/generated/pendingRegistration'
import { DateField } from '../forms/DateField'
import { useApiError } from '../../hooks/useApiError'
import { useFormatters } from '../../hooks/useFormatters'
import { theme } from '../../styles/design-system'

type Mode = 'review' | 'edit' | 'approve' | 'reject'

interface Props {
  registration: PendingRegistration
  onClose: () => void
  /**
   * Called with the new member's email after an approval, without one
   * otherwise. The email rather than the id because the members screen is a
   * searchable list rather than a detail route — see `RegistrationsPage`.
   */
  onDone: (email?: string) => Promise<void> | void
  onError: (message: string | null) => void
}

export function RegistrationReviewPanel({ registration, onClose, onDone, onError }: Props) {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const { formatDate } = useFormatters()

  const [mode, setMode] = useState<Mode>('review')
  const [busy, setBusy] = useState(false)
  const [panelError, setPanelError] = useState<string | null>(null)

  const [edit, setEdit] = useState({
    first_name: registration.first_name ?? '',
    last_name: registration.last_name ?? '',
    email: registration.email ?? '',
    date_of_birth: registration.date_of_birth ?? '',
    account_holder_name: registration.account_holder_name ?? '',
    iban: '',
  })

  const [signedAt, setSignedAt] = useState('')
  const [attested, setAttested] = useState(false)
  const [rejectReason, setRejectReason] = useState('')

  const today = new Date().toISOString().slice(0, 10)

  const run = useCallback(
    async (action: () => Promise<string | undefined>) => {
      setBusy(true)
      setPanelError(null)
      onError(null)

      try {
        const landOn = await action()
        await onDone(landOn)
      } catch (error) {
        // In the panel, not the page banner: the admin is looking at the dialog
        // they just used, and a message behind it is a message they never see.
        setPanelError(apiErrorMessage(error, t('registrations.errors.action')))
      } finally {
        setBusy(false)
      }
    },
    [apiErrorMessage, onDone, onError, t]
  )

  const save = () =>
    run(async () => {
      const api = getRegistrationReview()
      await api.updateRegistration(registration.id ?? '', {
        first_name: edit.first_name,
        last_name: edit.last_name,
        email: edit.email,
        date_of_birth: edit.date_of_birth,
        account_holder_name: edit.account_holder_name === '' ? null : edit.account_holder_name,
        // Absent unless retyped. An empty string here would ask the server to
        // re-seal nothing, and the field is deliberately blank on open: an IBAN
        // pre-filled from the row would invite an accidental "correction" to
        // the value that was already right.
        ...(edit.iban.trim() === '' ? {} : { iban: edit.iban.replace(/\s+/g, '') }),
      })

      return undefined
    })

  const approve = () =>
    run(async () => {
      const member = await getRegistrationReview().approveRegistration(registration.id ?? '', {
        mandate_signed_at: signedAt,
        signed_mandate_confirmed: attested,
      })

      return member.email ?? undefined
    })

  const reject = () =>
    run(async () => {
      await getRegistrationReview().rejectRegistration(registration.id ?? '', {
        ...(rejectReason.trim() === '' ? {} : { reason: rejectReason.trim() }),
      })

      return undefined
    })

  /**
   * The club's document, filled, with the IBAN line left blank for the member
   * to write by hand.
   *
   * Through `downloadFile()` so it goes via the API client and honours
   * `Content-Disposition` — building an `<a download>` here would bypass the
   * session handling every other download in this panel relies on.
   */
  const print = async () => {
    setBusy(true)
    setPanelError(null)

    try {
      await downloadFile(`/admin/registrations/${registration.id}/document`, 'anmeldung.pdf')
    } catch (error) {
      setPanelError(apiErrorMessage(error, t('registrations.errors.document')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={overlayStyle} role="dialog" aria-modal="true" data-testid="registration-panel">
      <div style={panelStyle}>
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <div>
            <h2 style={{ margin: 0, fontSize: theme.typography.fontSize.xl }}>
              {registration.first_name} {registration.last_name}
            </h2>
            <p style={{ margin: `${theme.spacing.xs} 0 0`, color: theme.colors.text.secondary }}>
              {t('registrations.panel.submitted', {
                date: registration.submitted_at ? formatDate(registration.submitted_at) : '',
              })}
            </p>
          </div>
          <button type="button" onClick={onClose} data-testid="panel-close" style={secondaryButtonStyle}>
            {t('common.close')}
          </button>
        </header>

        {panelError && (
          <p role="alert" data-testid="panel-error" style={errorStyle}>
            {panelError}
          </p>
        )}

        {(registration.duplicate_email || registration.duplicate_iban) && (
          <p data-testid="panel-duplicate-warning" style={warningStyle}>
            {registration.duplicate_email && t('registrations.duplicates.email')}{' '}
            {registration.duplicate_iban && t('registrations.duplicates.iban')}
          </p>
        )}

        {mode === 'review' && (
          <>
            <dl style={{ margin: `${theme.spacing.lg} 0` }} data-testid="panel-details">
              <Detail label={t('registrations.columns.email')} value={registration.email} />
              <Detail
                label={t('registrations.fields.dateOfBirth')}
                value={registration.date_of_birth ? formatDate(registration.date_of_birth) : '—'}
              />
              <Detail
                label={t('registrations.fields.accountHolder')}
                value={registration.account_holder_name ?? '—'}
              />
              <Detail label={t('registrations.fields.mandateReference')} value={registration.mandate_reference} />
              {/* Masked, and it is all the server has. */}
              <Detail label={t('registrations.columns.iban')} value={registration.iban_masked} testId="panel-iban" />
              <Detail label={t('registrations.columns.bank')} value={registration.bank_name ?? '—'} />
              <Detail
                label={t('registrations.fields.notice')}
                value={registration.privacy_notice_url ?? '—'}
                testId="panel-notice-url"
              />
              <Detail
                label={t('registrations.fields.expires')}
                value={registration.expires_at ? formatDate(registration.expires_at) : '—'}
              />
            </dl>

            <div style={actionsStyle}>
              <button type="button" onClick={print} disabled={busy} data-testid="panel-print" style={secondaryButtonStyle}>
                {t('registrations.actions.print')}
              </button>
              <button type="button" onClick={() => setMode('edit')} data-testid="panel-edit" style={secondaryButtonStyle}>
                {t('registrations.actions.edit')}
              </button>
              <button type="button" onClick={() => setMode('reject')} data-testid="panel-reject" style={dangerButtonStyle}>
                {t('registrations.actions.reject')}
              </button>
              <button type="button" onClick={() => setMode('approve')} data-testid="panel-approve" style={primaryButtonStyle}>
                {t('registrations.actions.approve')}
              </button>
            </div>
          </>
        )}

        {mode === 'edit' && (
          <>
            <Field label={t('registrations.fields.firstName')} testId="edit-first-name"
              value={edit.first_name} onChange={(v) => setEdit({ ...edit, first_name: v })} />
            <Field label={t('registrations.fields.lastName')} testId="edit-last-name"
              value={edit.last_name} onChange={(v) => setEdit({ ...edit, last_name: v })} />
            <Field label={t('registrations.columns.email')} testId="edit-email"
              value={edit.email} onChange={(v) => setEdit({ ...edit, email: v })} />

            <label style={labelStyle}>{t('registrations.fields.dateOfBirth')}</label>
            <DateField
              value={edit.date_of_birth}
              onChange={(iso) => setEdit({ ...edit, date_of_birth: iso })}
              testId="edit-date-of-birth"
              mode="birthdate"
              max={today}
            />

            <Field label={t('registrations.fields.accountHolder')} testId="edit-account-holder"
              value={edit.account_holder_name} onChange={(v) => setEdit({ ...edit, account_holder_name: v })} />

            <label style={labelStyle}>{t('registrations.fields.newIban')}</label>
            <input
              data-testid="edit-iban"
              value={edit.iban}
              onChange={(event) => setEdit({ ...edit, iban: event.target.value })}
              placeholder={t('registrations.fields.newIbanPlaceholder')}
              style={inputStyle}
            />
            <p style={hintStyle}>{t('registrations.fields.newIbanHint')}</p>

            <div style={actionsStyle}>
              <button type="button" onClick={() => setMode('review')} data-testid="edit-cancel" style={secondaryButtonStyle}>
                {t('common.cancel')}
              </button>
              <button type="button" onClick={save} disabled={busy} data-testid="edit-save" style={primaryButtonStyle}>
                {t('common.save')}
              </button>
            </div>
          </>
        )}

        {mode === 'approve' && (
          <>
            <p style={{ marginTop: theme.spacing.lg }}>{t('registrations.approve.lede')}</p>

            <label style={labelStyle}>{t('registrations.approve.signedAt')}</label>
            <DateField
              value={signedAt}
              onChange={setSignedAt}
              testId="approve-signed-at"
              // The date on the paper: it cannot be in the future, and today is
              // the ordinary case — an admin approving at the desk while the
              // member is standing there.
              max={today}
              required
            />

            <label style={{ ...labelStyle, display: 'flex', gap: theme.spacing.sm, alignItems: 'flex-start' }}>
              <input
                type="checkbox"
                data-testid="approve-attestation"
                checked={attested}
                onChange={(event) => setAttested(event.target.checked)}
                style={{ width: 20, height: 20, marginTop: 2, flexShrink: 0 }}
              />
              <span style={{ fontWeight: 400 }}>
                {t('registrations.approve.attestation', { last4: registration.iban_masked })}
              </span>
            </label>

            <div style={actionsStyle}>
              <button type="button" onClick={() => setMode('review')} data-testid="approve-cancel" style={secondaryButtonStyle}>
                {t('common.cancel')}
              </button>
              <button
                type="button"
                onClick={approve}
                // Disabled is a courtesy so nobody clicks a button that cannot
                // work; the server refuses an unattested approval regardless.
                disabled={busy || !attested || signedAt === ''}
                data-testid="approve-confirm"
                style={primaryButtonStyle}
              >
                {t('registrations.actions.approve')}
              </button>
            </div>
          </>
        )}

        {mode === 'reject' && (
          <>
            <p style={{ marginTop: theme.spacing.lg }} data-testid="reject-warning">
              {t('registrations.reject.warning')}
            </p>

            <label style={labelStyle}>{t('registrations.reject.reason')}</label>
            <input
              data-testid="reject-reason"
              value={rejectReason}
              onChange={(event) => setRejectReason(event.target.value)}
              maxLength={500}
              style={inputStyle}
            />
            <p style={hintStyle}>{t('registrations.reject.reasonHint')}</p>

            <div style={actionsStyle}>
              <button type="button" onClick={() => setMode('review')} data-testid="reject-cancel" style={secondaryButtonStyle}>
                {t('common.cancel')}
              </button>
              <button type="button" onClick={reject} disabled={busy} data-testid="reject-confirm" style={dangerButtonStyle}>
                {t('registrations.reject.confirm')}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function Detail({ label, value, testId }: { label: string; value?: string; testId?: string }) {
  return (
    <>
      <dt style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>{label}</dt>
      <dd data-testid={testId} style={{ margin: `2px 0 ${theme.spacing.md}`, overflowWrap: 'anywhere' }}>
        {value ?? '—'}
      </dd>
    </>
  )
}

function Field({
  label,
  value,
  onChange,
  testId,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  testId: string
}) {
  return (
    <>
      <label style={labelStyle}>{label}</label>
      <input data-testid={testId} value={value} onChange={(e) => onChange(e.target.value)} style={inputStyle} />
    </>
  )
}

const overlayStyle: React.CSSProperties = {
  position: 'fixed',
  inset: 0,
  background: 'rgba(0, 0, 0, 0.45)',
  display: 'flex',
  justifyContent: 'flex-end',
  zIndex: 1000,
}

const panelStyle: React.CSSProperties = {
  width: 'min(520px, 100%)',
  height: '100%',
  overflowY: 'auto',
  background: theme.colors.bg.secondary,
  padding: theme.spacing.xl,
  boxSizing: 'border-box',
}

const labelStyle: React.CSSProperties = {
  display: 'block',
  marginTop: theme.spacing.md,
  marginBottom: theme.spacing.xs,
  fontWeight: 600,
  color: theme.colors.text.primary,
}

const inputStyle: React.CSSProperties = {
  width: '100%',
  minHeight: 44,
  padding: `0 ${theme.spacing.md}`,
  borderRadius: theme.borderRadius.md,
  border: `1px solid ${theme.colors.border.light}`,
  background: theme.colors.bg.primary,
  color: theme.colors.text.primary,
  boxSizing: 'border-box',
}

const hintStyle: React.CSSProperties = {
  margin: `${theme.spacing.xs} 0 0`,
  color: theme.colors.text.secondary,
  fontSize: theme.typography.fontSize.sm,
}

const actionsStyle: React.CSSProperties = {
  display: 'flex',
  flexWrap: 'wrap',
  gap: theme.spacing.sm,
  marginTop: theme.spacing.xl,
}

const buttonBase: React.CSSProperties = {
  minHeight: 44,
  padding: `0 ${theme.spacing.lg}`,
  borderRadius: theme.borderRadius.md,
  border: '1px solid transparent',
  fontWeight: 600,
  cursor: 'pointer',
}

const primaryButtonStyle: React.CSSProperties = {
  ...buttonBase,
  background: theme.colors.semantic.primary,
  color: '#ffffff',
}

const secondaryButtonStyle: React.CSSProperties = {
  ...buttonBase,
  background: 'transparent',
  borderColor: theme.colors.border.light,
  color: theme.colors.text.primary,
}

const dangerButtonStyle: React.CSSProperties = {
  ...buttonBase,
  background: 'transparent',
  borderColor: theme.badges.danger.border,
  color: theme.badges.danger.text,
}

const errorStyle: React.CSSProperties = {
  marginTop: theme.spacing.md,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  background: theme.badges.danger.bg,
  border: `1px solid ${theme.badges.danger.border}`,
  color: theme.badges.danger.text,
}

const warningStyle: React.CSSProperties = {
  marginTop: theme.spacing.md,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  background: theme.badges.warning.bg,
  border: `1px solid ${theme.badges.warning.border}`,
  color: theme.badges.warning.text,
}
